<?php

namespace App\Providers;

use App\Automation\AutomationRegistry;
use App\Automation\Handlers\DateTriggerHandler;
use App\Automation\Handlers\EventTriggerHandler;
use App\Automation\Handlers\InstagramCommentKeywordTriggerHandler;
use App\Automation\Handlers\InstagramPrivateReplyActionHandler;
use App\Automation\Handlers\WhatsAppTemplateActionHandler;
use App\Models\Branch;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Message;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppTemplate;
use App\Observers\ContactAutomationObserver;
use App\Observers\ConversationAutomationObserver;
use App\Observers\MessageObserver;
use App\Policies\BranchPolicy;
use App\Policies\ChannelPolicy;
use App\Policies\ContactPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\InvitationPolicy;
use App\Policies\MessagePolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\PipelineStagePolicy;
use App\Policies\RolePolicy;
use App\Policies\TagPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use App\Policies\WhatsAppGroupPolicy;
use App\Policies\WhatsAppTemplatePolicy;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AutomationRegistry::class, fn ($app) => new AutomationRegistry(
            triggers: [
                new EventTriggerHandler('contact.created', 'Contacto creado', 'contact'),
                new EventTriggerHandler('contact.field_changed', 'Campo de contacto modificado', 'contact', true),
                new EventTriggerHandler('conversation.created', 'Conversación creada', 'conversation'),
                new EventTriggerHandler('conversation.stage_changed', 'Etapa de conversación modificada', 'conversation'),
                new EventTriggerHandler('conversation.status_changed', 'Estado de conversación modificado', 'conversation'),
                new InstagramCommentKeywordTriggerHandler,
                new DateTriggerHandler,
            ],
            actions: [
                $app->make(WhatsAppTemplateActionHandler::class),
                $app->make(InstagramPrivateReplyActionHandler::class),
            ],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands(
            (bool) env('DB_PROHIBIT_DESTRUCTIVE_COMMANDS', false)
                && ! $this->app->environment('testing')
        );

        Message::observe(MessageObserver::class);
        Contact::observe(ContactAutomationObserver::class);
        Conversation::observe(ConversationAutomationObserver::class);

        // Rate limit del endpoint público de webhooks entrantes: por api key
        // (varios tenants pueden compartir IP), con fallback a IP si no vino la key.
        RateLimiter::for('incoming-webhooks', fn (Request $request) => Limit::perMinute(60)
            ->by($request->header('X-Api-Key') ?: $request->ip()));

        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Channel::class, ChannelPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Invitation::class, InvitationPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(Opportunity::class, OpportunityPolicy::class);
        Gate::policy(PipelineStage::class, PipelineStagePolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Tag::class, TagPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(WhatsAppGroup::class, WhatsAppGroupPolicy::class);
        Gate::policy(WhatsAppTemplate::class, WhatsAppTemplatePolicy::class);

        // Owner bypasses every gate within their tenant.
        //
        // The bypass is keyed by tenants.owner_role_id, not by role name. This
        // lets workspaces rename the seeded "Owner" role (e.g. "Dueño") without
        // losing the bypass. We must keep the bypass scoped to the owner's
        // tenant; otherwise an Owner could touch tenant-scoped resources (e.g.
        // Spatie roles, which are not covered by the BelongsToTenant global
        // scope) that belong to other tenants simply by guessing their primary
        // key.
        Gate::before(function (?User $user, string $ability, array $arguments = []) {
            if ($user === null) {
                return null;
            }

            if (! $user->isTenantOwner()) {
                return null;
            }

            $ownerRoleId = $user->tenant?->owner_role_id;

            foreach ($arguments as $argument) {
                if (is_object($argument) && property_exists($argument, 'tenant_id') === false && ! isset($argument->tenant_id)) {
                    continue;
                }

                if (is_object($argument) && isset($argument->tenant_id) && (int) $argument->tenant_id !== (int) $user->tenant_id) {
                    return null;
                }
            }

            // Hard guard: even an Owner cannot rename or delete the Owner role
            // itself. Returning false here short-circuits the gate; without it,
            // the bypass above would let the action through.
            if (in_array($ability, ['update', 'delete', 'syncPermissions'], true)) {
                foreach ($arguments as $argument) {
                    if ($argument instanceof Role && (int) $argument->id === (int) $ownerRoleId) {
                        return false;
                    }
                }
            }

            return true;
        });

        Gate::define('viewPulse', function (?User $user) {
            if (app()->environment('local')) {
                return true;
            }

            $key = config('app.pulse_key');

            if ($key && request()->query('key') === $key) {
                session()->put('pulse_authorized', true);
            }

            return session()->get('pulse_authorized', false);
        });

        $this->configureSentryUserContext();
    }

    /**
     * Adjunta el usuario autenticado y su tenant a cada evento de Sentry.
     * Esto es lo que permite, ante un error reportado, saber qué cliente
     * (tenant) y qué usuario lo sufrió — clave para soporte. No envía PII
     * extra: solo id, email y name del propio usuario logueado.
     */
    private function configureSentryUserContext(): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        Event::listen(function (Authenticated $event): void {
            $user = $event->user;

            Integration::configureScope(static function (Scope $scope) use ($user): void {
                $scope->setUser([
                    'id' => $user->id,
                    'email' => $user->email ?? null,
                    'name' => $user->name ?? null,
                ]);

                if (isset($user->tenant_id)) {
                    $scope->setTag('tenant_id', (string) $user->tenant_id);
                }
            });
        });
    }
}
