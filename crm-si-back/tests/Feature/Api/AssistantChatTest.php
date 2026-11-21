<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WorkspaceAssistant;
use App\Enums\AiProvider;
use App\Models\AiConfig;
use App\Models\AssistantUsage;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssistantChatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        // Independiente del .env local (que puede apuntar a un Ollama).
        config(['services.ai.assistant.source' => 'tenant']);

        $this->tenant = $this->createTenantWithRoles();
        $this->member = $this->memberOf($this->tenant);

        $config = AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => AiProvider::OPENAI,
            'enabled' => false,
        ]);
        $config->setEncryptedApiKey('sk-test');

        WorkspaceAssistant::fake(['Respuesta OK']);
        Sanctum::actingAs($this->member);
    }

    public function test_member_chats_in_a_persisted_thread(): void
    {
        $conversationId = $this->createConversation('Resumen del workspace');

        $this->getJson('/api/assistant/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.title', 'Resumen del workspace');

        $response = $this->chat($conversationId, 'Resumen');
        $response->assertOk()->assertHeader('x-vercel-ai-ui-message-stream', 'v1');
        $this->assertSame('Respuesta OK', $this->streamedText($response));

        $stored = ConversationMessage::query()->where('conversation_id', $conversationId)->pluck('id')->all();
        $this->assertCount(2, $stored);
        $this->assertTrue(AssistantUsage::query()->where('tenant_id', $this->tenant->id)->exists());

        $messages = $this->getJson("/api/assistant/conversations/{$conversationId}/messages")
            ->assertOk()
            ->json('data');
        $this->assertSame(['user', 'assistant'], array_column($messages, 'role'));
        $this->assertEqualsCanonicalizing($stored, array_column($messages, 'id'));
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $registrar = app(PermissionRegistrar::class);
        Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Member')->firstOrFail()
            ->revokePermissionTo('assistant.use');
        $registrar->forgetCachedPermissions();

        $this->getJson('/api/assistant/conversations')->assertForbidden();
        $this->postJson('/api/assistant/chat', [
            'conversation_id' => str_repeat('a', 36),
            'messages' => [$this->userMessage('Hola')],
        ])->assertForbidden();
    }

    public function test_chat_without_ai_key_explains_how_to_configure_it(): void
    {
        AiConfig::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->delete();
        $conversationId = $this->createConversation('Hola');

        $this->chat($conversationId, 'Hola')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_not_configured');

        $this->assertSame(0, ConversationMessage::query()->count());
    }

    public function test_platform_source_stops_at_the_daily_token_limit(): void
    {
        config([
            'services.ai.assistant.source' => 'platform',
            'services.ai.assistant.platform' => [
                'driver' => 'ollama',
                'key' => null,
                'url' => 'http://ollama.test:11434',
                'model' => 'qwen3:8b',
            ],
            'services.ai.assistant.daily_token_limit_per_tenant' => 100,
        ]);
        AssistantUsage::query()->create([
            'tenant_id' => $this->tenant->id,
            'date' => today()->toDateString(),
            'input_tokens' => 80,
            'output_tokens' => 20,
        ]);
        $conversationId = $this->createConversation('Hola');

        $this->chat($conversationId, 'Hola')
            ->assertStatus(429)
            ->assertJsonPath('code', 'assistant_daily_limit');
    }

    public function test_threads_are_isolated_per_workspace(): void
    {
        $otherTenant = $this->createTenantWithRoles('Otro');
        TenantMembership::create(['tenant_id' => $otherTenant->id, 'user_id' => $this->member->id, 'joined_at' => now()]);
        $this->member->assignRole('Member'); // el registrar apunta al team del tenant recién creado
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $otherThread = $this->withHeader('X-Workspace-Id', (string) $otherTenant->id)
            ->postJson('/api/assistant/conversations', ['first_message' => 'Hilo del otro workspace'])
            ->assertCreated()
            ->json('data.id');
        $this->flushHeaders();

        $this->withHeader('X-Workspace-Id', (string) $this->tenant->id)
            ->getJson("/api/assistant/conversations/{$otherThread}/messages")
            ->assertNotFound();
        $this->chat($otherThread, 'Hola', $this->tenant->id)->assertNotFound();
        $this->getJson('/api/assistant/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_feedback_is_saved_only_for_own_messages(): void
    {
        $conversationId = $this->createConversation('Resumen');
        $this->chat($conversationId, 'Resumen')->streamedContent();
        $assistantMessage = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->value('id');

        $this->putJson("/api/assistant/messages/{$assistantMessage}/feedback", ['rating' => 1])->assertNoContent();
        $this->getJson("/api/assistant/conversations/{$conversationId}/messages")
            ->assertJsonPath("feedback.{$assistantMessage}", 1);

        Sanctum::actingAs($this->memberOf($this->tenant));
        $this->putJson("/api/assistant/messages/{$assistantMessage}/feedback", ['rating' => -1])->assertNotFound();
    }

    public function test_pending_action_waits_for_confirmation_and_resumes_with_the_decision(): void
    {
        WorkspaceAssistant::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval(
                    id: 'call_1',
                    tool: 'create_task',
                    arguments: ['name' => 'Llamar a Jorge'],
                    reason: 'Crear tarea «Llamar a Jorge»',
                ),
            ]),
            'Listo, creé la tarea.',
        ]);
        $conversationId = $this->createConversation('Recordame llamar a Jorge');

        $approvalRequest = $this->streamedParts($this->chat($conversationId, 'Recordame llamar a Jorge'))
            ->firstWhere('type', 'tool-approval-request');
        $this->assertSame('call_1', $approvalRequest['toolCallId']);
        $this->assertSame('Crear tarea «Llamar a Jorge»', $approvalRequest['reason']);

        $assistant = collect($this->getJson("/api/assistant/conversations/{$conversationId}/messages")->json('data'))
            ->firstWhere('role', 'assistant');
        $pending = collect($assistant['parts'])->firstWhere('type', 'tool-create_task');
        $this->assertSame('approval-requested', $pending['state']);

        // El cliente reenvía el mensaje completo del asistente, con razonamiento
        // y texto que pueden superar cualquier tope de largo.
        $resumed = $this->postJson('/api/assistant/chat', [
            'conversation_id' => $conversationId,
            'messages' => [[
                'id' => $assistant['id'],
                'role' => 'assistant',
                'parts' => [
                    ['type' => 'reasoning', 'text' => str_repeat('pensando ', 600)],
                    ['type' => 'text', 'text' => str_repeat('Te propongo crear la tarea. ', 120)],
                    [
                        ...$pending,
                        'state' => 'approval-responded',
                        'approval' => ['id' => 'call_1', 'approved' => true],
                    ],
                ],
            ]],
        ]);

        $resumed->assertOk();
        $this->assertSame('Listo, creé la tarea.', $this->streamedText($resumed));
        WorkspaceAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->hasApprovalDecisions()
            && $prompt->approvalDecisions->get('call_1')->isApproved());
    }

    public function test_assistant_message_without_a_decision_is_rejected(): void
    {
        $conversationId = $this->createConversation('Hola');

        $this->postJson('/api/assistant/chat', [
            'conversation_id' => $conversationId,
            'messages' => [['id' => 'a1', 'role' => 'assistant', 'parts' => [['type' => 'text', 'text' => 'hola']]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('messages.0.parts');
    }

    public function test_malformed_messages_are_rejected_as_validation_errors(): void
    {
        $conversationId = $this->createConversation('Hola');
        $post = fn (array $message) => $this->postJson('/api/assistant/chat', [
            'conversation_id' => $conversationId,
            'messages' => [$message],
        ]);

        // Usuario: solo una parte de texto (sin adjuntos).
        $post(['id' => 'u1', 'role' => 'user', 'parts' => [
            ['type' => 'text', 'text' => 'hola'],
            ['type' => 'file', 'mediaType' => 'image/png', 'url' => 'data:image/png;base64,AAAA'],
        ]])->assertUnprocessable();

        // Asistente: tipos inválidos en la parte de herramienta dan 422, no 500.
        $post(['id' => 'a1', 'role' => 'assistant', 'parts' => [[
            'type' => 'tool-create_task',
            'toolCallId' => ['no-es-texto'],
            'state' => 'approval-responded',
            'approval' => ['id' => 'call_1', 'approved' => true],
        ]]])->assertUnprocessable();
    }

    private function memberOf(Tenant $tenant): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Member');

        return $user;
    }

    private function createConversation(string $firstMessage): string
    {
        return $this->postJson('/api/assistant/conversations', ['first_message' => $firstMessage])
            ->assertCreated()
            ->json('data.id');
    }

    private function chat(string $conversationId, string $text, ?int $workspaceId = null): TestResponse
    {
        $headers = $workspaceId ? ['X-Workspace-Id' => (string) $workspaceId] : [];

        return $this->postJson('/api/assistant/chat', [
            'conversation_id' => $conversationId,
            'messages' => [$this->userMessage($text)],
        ], $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function userMessage(string $text): array
    {
        return ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $text]]];
    }

    /** Texto de la respuesta, unido desde los eventos text-delta del stream. */
    private function streamedText(TestResponse $response): string
    {
        return $this->streamedParts($response)->where('type', 'text-delta')->pluck('delta')->implode('');
    }

    /**
     * Eventos del stream (protocolo de useChat) ya decodificados.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function streamedParts(TestResponse $response): Collection
    {
        return collect(explode("\n", $response->streamedContent()))
            ->filter(fn (string $line) => str_starts_with($line, 'data: {'))
            ->map(fn (string $line) => json_decode(substr($line, 6), true))
            ->values();
    }
}
