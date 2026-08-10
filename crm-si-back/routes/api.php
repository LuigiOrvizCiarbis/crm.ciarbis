<?php

use App\Http\Controllers\Api\AiConfigController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\AutomationRunController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactFieldController;
use App\Http\Controllers\Api\ContactHistoryController;
use App\Http\Controllers\Api\ContactTimelineController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationTranslationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GoogleCalendarConnectionController;
use App\Http\Controllers\Api\IncomingWebhookController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MediaAssetController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageHotkeyController;
use App\Http\Controllers\Api\MessageTranslationController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\OpportunityController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PipelineStageController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductFieldController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookEndpointController;
use App\Http\Controllers\Api\WhatsAppTemplateController;
use App\Http\Controllers\Api\WooCommerceConfigController;
use App\Http\Controllers\FacebookDataDeletionController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InstagramController;
use App\Http\Controllers\MessengerController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\Api\MailIntakeController;
use App\Http\Controllers\WhatsAppController;
use App\Models\Invitation;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProductFieldProvisioner;
use App\Support\RolePayload;
use App\Support\RoleProvisioner;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

// Health check profundo (DB + Redis). 200 = ok, 503 = degraded.
// En el grupo `api` (sin StartSession): no depende de la sesión Redis, así que
// responde rápido incluso con Redis caído y puede reportar el 503 correctamente.
// Lo consumen el healthcheck de Docker y el uptime externo (Better Stack).
Route::get('health', HealthController::class);

Route::post('login', function (Request $request): JsonResponse {
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Credenciales inválidas'], 401);
    }

    app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
    $role = $user->roles()->where('roles.tenant_id', $user->tenant_id)->first();

    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => $user->load(['tenant:id,name,owner_role_id,plan_id,trial_ends_at', 'tenant.plan:id,key,name']),
        'role' => RolePayload::transform($role, $user->tenant),
        'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        'email_verified' => $user->hasVerifiedEmail(),
    ]);
});

Route::post('register', function (Request $request): JsonResponse {
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'password' => ['required', 'confirmed', PasswordRule::min(8)],
        'invitation_token' => ['nullable', 'string', 'size:64'],
    ]);

    $invitation = null;
    $tenant = null;
    $roleName = 'Owner';
    $isNewTenant = true;

    // If invitation token provided, join existing tenant
    if ($request->invitation_token) {
        $invitation = Invitation::withoutGlobalScope(TenantScope::class)
            ->with('tenant')
            ->where('token', $request->invitation_token)
            ->first();

        if (! $invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            return response()->json(['message' => 'La invitación no es válida o ha expirado.'], 422);
        }

        if ($invitation->email !== $request->email) {
            return response()->json(['message' => 'El email no coincide con la invitación.'], 422);
        }

        $tenant = $invitation->tenant;
        $roleName = $invitation->role_name ?? 'Admin';
        $isNewTenant = false;
    }

    // Tenant creation, user creation, provisioning and role syncing must all
    // succeed together — otherwise a mid-flow failure would leave a
    // partially-provisioned tenant/user persisted. Wrap them in a transaction so
    // any failure rolls the whole registration back.
    [$tenant, $user] = DB::transaction(function () use ($request, $tenant, $isNewTenant, $roleName): array {
        if ($isNewTenant) {
            $tenant = Tenant::create([
                'name' => $request->name."'s Workspace",
                'trial_ends_at' => now()->addDays(14),
            ]);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'tenant_id' => $tenant->id,
        ]);

        if ($isNewTenant) {
            app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
            app(ProductFieldProvisioner::class)->seedDefaults($tenant);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->syncRoles([$roleName]);

        return [$tenant, $user];
    });

    // El registro no debe fallar si el proveedor de email está caído.
    try {
        $user->sendEmailVerificationNotification();
    } catch (Throwable $e) {
        Log::error('register-verification-email-failed', [
            'user_id' => $user->id,
            'email' => $user->email,
            'error' => $e->getMessage(),
        ]);
    }
    // Mark invitation as accepted
    if ($invitation) {
        $invitation->update(['accepted_at' => now()]);
    }

    $role = $user->roles()->where('roles.tenant_id', $tenant->id)->first();
    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
        'token' => $token,
        'user' => $user->load(['tenant:id,name,owner_role_id,plan_id,trial_ends_at', 'tenant.plan:id,key,name']),
        'role' => RolePayload::transform($role, $tenant->fresh()),
        'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        'email_verified' => false,
        'message' => 'Cuenta creada. Por favor verifica tu email.',
    ], 201);
});

// Verificar email con link firmado
Route::get('email/verify/{id}/{hash}', function (Request $request, $id, $hash): JsonResponse|RedirectResponse {
    $user = User::findOrFail($id);

    // Verificar que el hash coincide
    if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Link de verificación inválido'], 400);
        }
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return redirect($frontendUrl.'/verify-email/confirm?error=invalid');
    }

    // Verificar la firma y expiración
    $expires = $request->query('expires');
    $signature = $request->query('signature');
    $appKey = config('app.key');

    $expectedSignature = hash_hmac('sha256', "{$id}/{$hash}?expires={$expires}", $appKey);

    if (! hash_equals($expectedSignature, $signature) || now()->timestamp > (int) $expires) {
        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'El link de verificación ha expirado o es inválido'], 400);
        }
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return redirect($frontendUrl.'/verify-email/confirm?error=expired');
    }

    if ($user->hasVerifiedEmail()) {
        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Email ya verificado', 'already' => true]);
        }
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        return redirect($frontendUrl.'/verify-email/confirm?already=true');
    }

    $user->markEmailAsVerified();
    event(new Verified($user));

    if ($request->wantsJson() || $request->is('api/*')) {
        return response()->json(['message' => 'Email verificado exitosamente', 'success' => true]);
    }

    $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

    return redirect($frontendUrl.'/verify-email/confirm?success=true');
})->name('verification.verify');

// Reenviar email de verificación
Route::post('email/resend', function (Request $request) {
    $request->validate([
        'email' => 'required|email|exists:users,email',
    ]);

    $user = User::where('email', $request->email)->first();

    if ($user->hasVerifiedEmail()) {
        return response()->json(['message' => 'El email ya está verificado'], 400);
    }

    $user->sendEmailVerificationNotification();

    return response()->json([
        'message' => 'Email de verificación reenviado',
    ]);
});

// Cambiar email (si se equivocó al registrarse)
Route::middleware('auth:sanctum')->post('email/change', function (Request $request): JsonResponse {
    $request->validate([
        'email' => 'required|email|unique:users,email',
        'password' => 'required',
    ]);

    $user = $request->user();

    // Verificar contraseña
    if (! Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Contraseña incorrecta'], 401);
    }

    // Cambiar email y marcar como no verificado
    $user->email = $request->email;
    $user->email_verified_at = null;
    $user->save();

    // Enviar nuevo email de verificación
    $user->sendEmailVerificationNotification();

    return response()->json([
        'user' => $user,
        'message' => 'Email actualizado. Por favor verifica el nuevo email.',
    ]);
});

Route::post('forgot-password', function (Request $request): JsonResponse {
    $request->validate([
        'email' => 'required|email|exists:users,email',
    ]);

    $status = Password::sendResetLink(
        $request->only('email')
    );

    if ($status === Password::RESET_LINK_SENT) {
        return response()->json([
            'message' => 'Te hemos enviado un correo con instrucciones para restablecer tu contraseña',
        ]);
    }

    return response()->json([
        'message' => 'No pudimos enviar el correo. Intenta de nuevo.',
    ], 400);
});

Route::post('reset-password', function (Request $request) {
    $request->validate([
        'token' => 'required',
        'email' => 'required|email',
        'password' => ['required', 'confirmed', PasswordRule::min(8)],
    ]);

    $status = Password::reset(
        $request->only('email', 'password', 'password_confirmation', 'token'),
        function ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();
        }
    );

    if ($status === Password::PASSWORD_RESET) {
        return response()->json([
            'message' => 'Contraseña restablecida exitosamente',
        ]);
    }

    return response()->json([
        'message' => 'No se pudo restablecer la contraseña. El enlace puede haber expirado.',
    ], 400);
});

Route::middleware('auth:sanctum')->group(function () {

    Route::get('user', function (Request $request): JsonResponse {
        $user = $request->user()->load(['tenant:id,name,owner_role_id,plan_id,trial_ends_at', 'tenant.plan:id,key,name']);
        $role = $user->roles()->where('roles.tenant_id', $user->tenant_id)->first();

        return response()->json([
            'user' => $user,
            'role' => RolePayload::transform($role, $user->tenant),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ]);
    });

    Route::post('logout', function (Request $request): JsonResponse {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    });

    Route::get('dashboard/metrics', [DashboardController::class, 'metrics']);
    Route::get('dashboard/branches', [DashboardController::class, 'branches']);

    Route::apiResource('tags', TagController::class);
    Route::post('products/import', [ProductController::class, 'import']);
    Route::get('product-fields', [ProductFieldController::class, 'index']);
    Route::post('product-fields/reorder', [ProductFieldController::class, 'reorder']);
    Route::post('product-fields', [ProductFieldController::class, 'store']);
    Route::put('product-fields/{product_field}', [ProductFieldController::class, 'update']);
    Route::delete('product-fields/{product_field}', [ProductFieldController::class, 'destroy']);
    Route::apiResource('products', ProductController::class);

    Route::apiResource('branches', BranchController::class);
    Route::get('branches/{branch}/stats', [BranchController::class, 'stats']);

    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::patch('users/{user}/role', [UserController::class, 'assignRole']);
    Route::patch('users/{user}/branch', [UserController::class, 'assignBranch']);

    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::get('roles/{role}', [RoleController::class, 'show']);
    Route::patch('roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

    Route::get('permissions', [PermissionController::class, 'index']);

    Route::get('automations/metadata', [AutomationController::class, 'metadata']);
    Route::get('automations', [AutomationController::class, 'index']);
    Route::post('automations', [AutomationController::class, 'store']);
    Route::get('automations/{automation}', [AutomationController::class, 'show']);
    Route::put('automations/{automation}', [AutomationController::class, 'update']);
    Route::delete('automations/{automation}', [AutomationController::class, 'destroy']);
    Route::post('automations/{automation}/activate', [AutomationController::class, 'activate']);
    Route::post('automations/{automation}/pause', [AutomationController::class, 'pause']);
    Route::post('automations/{automation}/preview', [AutomationController::class, 'preview']);
    Route::get('automations/{automation}/runs', [AutomationController::class, 'runs']);
    Route::get('automation-runs/{run}', [AutomationRunController::class, 'show']);
    Route::post('automation-runs/{run}/retry', [AutomationRunController::class, 'retry']);

    Route::get('media-assets', [MediaAssetController::class, 'index']);
    Route::post('media-assets', [MediaAssetController::class, 'store']);
    Route::delete('media-assets/{mediaAsset}', [MediaAssetController::class, 'destroy']);

    Route::get('contacts', [ContactController::class, 'index']);
    Route::get('contacts/summary', [ContactController::class, 'summary']);
    Route::post('contacts', [ContactController::class, 'store']);
    Route::post('contacts/import', [ContactController::class, 'import']);
    Route::post('contacts/bulk-tags', [ContactController::class, 'bulkTags']);
    Route::get('contacts/{contact}', [ContactController::class, 'show']);
    Route::put('contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);
    Route::get('contacts/{contact}/history', [ContactHistoryController::class, 'show']);
    Route::get('contacts/{contact}/timeline', [ContactTimelineController::class, 'show']);
    Route::post('contacts/{contact}/tags', [TagController::class, 'attachToContact']);
    Route::delete('contacts/{contact}/tags/{tag}', [TagController::class, 'detachFromContact']);

    Route::get('contact-fields', [ContactFieldController::class, 'index']);
    Route::post('contact-fields', [ContactFieldController::class, 'store']);
    Route::put('contact-fields/{contact_field}', [ContactFieldController::class, 'update']);
    Route::delete('contact-fields/{contact_field}', [ContactFieldController::class, 'destroy']);
    Route::post('contact-fields/reorder', [ContactFieldController::class, 'reorder']);

    // Config de IA por tenant (proveedor + API key BYOK).
    Route::get('ai-config', [AiConfigController::class, 'show']);
    Route::get('ai-config/models', [AiConfigController::class, 'models']);
    Route::post('ai-config/test', [AiConfigController::class, 'test']);
    Route::put('ai-config', [AiConfigController::class, 'update']);

    // Config de WooCommerce por tenant (credenciales REST API + sync de productos).
    Route::get('woocommerce-config', [WooCommerceConfigController::class, 'show']);
    Route::put('woocommerce-config', [WooCommerceConfigController::class, 'update']);
    Route::post('woocommerce-config/test', [WooCommerceConfigController::class, 'test']);
    Route::post('woocommerce-config/sync', [WooCommerceConfigController::class, 'sync']);

    // Config de webhooks entrantes por tenant (el endpoint público de recepción
    // está fuera de este grupo, al final del archivo).
    Route::get('webhook-endpoints', [WebhookEndpointController::class, 'index']);
    Route::get('webhook-endpoints/schema', [WebhookEndpointController::class, 'schema']);
    Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store']);
    Route::put('webhook-endpoints/{id}', [WebhookEndpointController::class, 'update']);
    Route::delete('webhook-endpoints/{id}', [WebhookEndpointController::class, 'destroy']);
    Route::post('webhook-endpoints/{id}/rotate-key', [WebhookEndpointController::class, 'rotateKey']);
    Route::get('webhook-endpoints/{id}/deliveries', [WebhookEndpointController::class, 'deliveries']);
    Route::get('webhook-endpoints/{id}/deliveries/{deliveryId}', [WebhookEndpointController::class, 'deliveryShow']);

    Route::get('conversations', [ConversationController::class, 'index']);
    Route::get('mail-intakes', [MailIntakeController::class, 'index']);
    Route::get('mail-intakes/count', [MailIntakeController::class, 'count']);
    Route::get('mail-intakes/{mailIntake}', [MailIntakeController::class, 'show']);
    Route::post('mail-intakes/{mailIntake}/approve', [MailIntakeController::class, 'approve']);
    Route::post('mail-intakes/{mailIntake}/reject', [MailIntakeController::class, 'reject']);
    Route::post('mail-intakes/{mailIntake}/restore', [MailIntakeController::class, 'restore']);
    Route::post('mail-intakes/{mailIntake}/approve-and-allow', [MailIntakeController::class, 'approveAndAllow']);
    Route::post('mail-intakes/{mailIntake}/reject-and-block', [MailIntakeController::class, 'rejectAndBlock']);
    Route::post('conversations/bulk-tags', [ConversationController::class, 'bulkTags']);
    Route::post('conversations/bulk-assign', [ConversationController::class, 'bulkAssign']);
    Route::post('conversations/bulk-archive', [ConversationController::class, 'bulkArchive']);
    Route::post('conversations/bulk-ai-autoreply', [ConversationController::class, 'bulkAiAutoreply']);
    Route::post('conversations/bulk-delete', [ConversationController::class, 'bulkDelete']);
    Route::post('conversations/bulk-read', [ConversationController::class, 'bulkMarkRead']);
    Route::post('conversations/bulk-broadcast', [ConversationController::class, 'bulkBroadcast']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('conversations/{conversation}/tags', [TagController::class, 'attachToConversation']);
    Route::delete('conversations/{conversation}/tags/{tag}', [TagController::class, 'detachFromConversation']);

    Route::get('messages', [MessageController::class, 'index']);
    Route::post('messages', [MessageController::class, 'store']);
    Route::put('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    Route::post('messages/{message}/translation', [MessageTranslationController::class, 'store']);

    Route::apiResource('message-hotkeys', MessageHotkeyController::class)->except(['show']);

    Route::get('channels', [ChannelController::class, 'index']);
    Route::get('channels/{channel}/mail-rules', [MailIntakeController::class, 'rules']);
    Route::post('channels/{channel}/mail-rules', [MailIntakeController::class, 'storeRule']);
    Route::delete('channels/{channel}/mail-rules/{mailChannelRule}', [MailIntakeController::class, 'destroyRule']);
    Route::patch('channels/{id}', [ChannelController::class, 'update']);
    Route::get('channels/{id}/users', [ChannelController::class, 'users']);
    Route::post('channels/{id}/users', [ChannelController::class, 'addUser']);
    Route::delete('channels/{id}/users/{userId}', [ChannelController::class, 'removeUser']);
    Route::put('channels/{id}/users', [ChannelController::class, 'syncUsers']);
    Route::patch('channels/{id}/branch', [ChannelController::class, 'assignBranch']);

    Route::post('admin/channels/whatsapp-auth', [WhatsAppController::class, 'handleAuth']);
    Route::post('admin/channels/instagram-auth', [InstagramController::class, 'handleAuth']);
    Route::post('admin/channels/messenger-auth', [MessengerController::class, 'handleAuth']);
    Route::post('admin/channels/mail-auth', [MailController::class, 'handleAuth']);
    Route::post('admin/channels/{id}/mail-sync', [MailController::class, 'sync']);
    Route::get('admin/channels/{id}/business-verification', [WhatsAppController::class, 'businessVerification']);

    Route::get('/conversations/{id}/messages', [ConversationController::class, 'fetchMessages']);

    Route::post('pipeline-stages/reorder', [PipelineStageController::class, 'reorder']);
    Route::apiResource('pipeline-stages', PipelineStageController::class);
    Route::get('opportunities/summary', [OpportunityController::class, 'summary']);
    Route::apiResource('opportunities', OpportunityController::class);
    Route::patch('opportunities/{id}/stage', [OpportunityController::class, 'updateStage']);
    Route::patch('conversations/{id}/stage', [ConversationController::class, 'updateStage']);
    Route::patch('conversations/{id}/archive', [ConversationController::class, 'archive']);
    Route::patch('conversations/{id}/ai-autoreply', [ConversationController::class, 'aiAutoreply']);
    Route::post('conversations/{conversation}/translate-draft', [ConversationTranslationController::class, 'translateDraft']);
    Route::patch('conversations/{conversation}/translation-language', [ConversationTranslationController::class, 'updateLanguage']);
    Route::post('conversations/{id}/read', [ConversationController::class, 'markAsRead']);
    Route::post('conversations/{id}/unread', [ConversationController::class, 'markAsUnread']);

    Route::apiResource('tasks', TaskController::class);
    Route::post('tasks/{task}/google-calendar/retry', [TaskController::class, 'retryCalendarSync']);
    Route::apiResource('notes', NoteController::class)->only(['index', 'store', 'destroy']);

    Route::get('google-calendar/connection', [GoogleCalendarConnectionController::class, 'show']);
    Route::post('google-calendar/authorization-url', [GoogleCalendarConnectionController::class, 'authorizationUrl']);
    Route::delete('google-calendar/connection', [GoogleCalendarConnectionController::class, 'destroy']);

    Route::post('conversations/{id}/users', [ConversationController::class, 'assignUsers']);
    Route::post('conversations/{id}/users/add', [ConversationController::class, 'addUser']);
    Route::delete('conversations/{id}/users/{userId}', [ConversationController::class, 'removeUser']);

    Route::get('channels/{channel}/templates', [WhatsAppTemplateController::class, 'index']);
    Route::post('channels/{channel}/templates', [WhatsAppTemplateController::class, 'create']);
    Route::delete('channels/{channel}/templates/{template}', [WhatsAppTemplateController::class, 'destroy']);
    Route::post('channels/{channel}/templates/sync', [WhatsAppTemplateController::class, 'sync']);
    Route::post('channels/{channel}/templates/media', [WhatsAppTemplateController::class, 'uploadTemplateMedia']);
    Route::post('channels/{channel}/media', [WhatsAppTemplateController::class, 'uploadMedia']);
    Route::post('conversations/{conversation}/send-template', [WhatsAppTemplateController::class, 'send']);

    Route::get('invitations', [InvitationController::class, 'index']);
    Route::post('invitations', [InvitationController::class, 'store']);
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy']);
    Route::post('invitations/accept', [InvitationController::class, 'accept']);
});

// Public: view invitation details by token (no auth required)
Route::get('invitations/by-token/{token}', [InvitationController::class, 'showByToken']);

// Public: catálogo de planes (lo consume /pricing, también visible sin sesión)
Route::get('plans', [PlanController::class, 'index']);

Route::match(['get', 'post'], 'whatsapp-webhook', [WhatsAppController::class, 'webhook']);

Route::match(['get', 'post'], 'instagram-webhook', [InstagramController::class, 'webhook']);

Route::match(['get', 'post'], 'messenger-webhook', [MessengerController::class, 'webhook']);

Route::post('facebook/data-deletion', [FacebookDataDeletionController::class, 'handle']);

// Público: Google redirige acá tras el consentimiento OAuth, sin credenciales
// Sanctum. El `state` de un solo uso es el único vínculo de identidad.
Route::get('google-calendar/callback', [GoogleCalendarConnectionController::class, 'callback']);

// Public: webhooks entrantes configurables por tenant. Se autentican con
// X-Api-Key por endpoint (no Sanctum). Rate limit por api key (ver AppServiceProvider).
Route::post('incoming-webhooks/{slug}', [IncomingWebhookController::class, 'handle'])
    ->middleware('throttle:incoming-webhooks');

// Import bulk asíncrono: acepta batches grandes, responde 202 y procesa en cola.
// El status del delivery se consulta por GET (polling), misma X-Api-Key.
Route::post('incoming-webhooks/{slug}/bulk', [IncomingWebhookController::class, 'bulk'])
    ->middleware('throttle:incoming-webhooks');
Route::get('incoming-webhooks/{slug}/deliveries/{deliveryId}', [IncomingWebhookController::class, 'showDelivery'])
    ->middleware('throttle:incoming-webhooks');
