<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConversationSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->seedTenantWithRoles('Acme');

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('Owner');
    }

    private function seedTenantWithRoles(string $name): Tenant
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => $name.' '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        return $tenant;
    }

    private function createConversationWithMessage(Tenant $tenant, User $channelOwner, string $content, string $phone): Conversation
    {
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $channelOwner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Channel '.$phone,
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contact '.$phone,
            'phone' => $phone,
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => $content,
            'direction' => MessageDirection::INBOUND,
        ]);

        return $conversation;
    }

    public function test_search_matches_message_content_case_insensitive(): void
    {
        $matching = $this->createConversationWithMessage($this->tenant, $this->owner, 'Hola mundo desde el chat', '+5491111111111');
        $other = $this->createConversationWithMessage($this->tenant, $this->owner, 'Sin coincidencia alguna', '+5492222222222');

        Sanctum::actingAs($this->owner);

        $ids = collect($this->getJson('/api/conversations?search=hola')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($matching->id));
        $this->assertFalse($ids->contains($other->id));
    }

    public function test_search_matches_structured_email_subject(): void
    {
        $conversation = $this->createConversationWithMessage(
            $this->tenant,
            $this->owner,
            'El cuerpo no contiene el término buscado',
            '+5491111111111',
        );
        $conversation->messages()->first()->mailDetails()->create([
            'subject' => 'Renovación del contrato anual',
            'body_text' => 'El cuerpo no contiene el término buscado',
        ]);

        Sanctum::actingAs($this->owner);

        $ids = collect($this->getJson('/api/conversations?search=renovación')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($conversation->id));
    }

    public function test_search_does_not_leak_other_tenants_conversations(): void
    {
        $otherTenant = $this->seedTenantWithRoles('Globex');
        $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherOwner->assignRole('Owner');

        $this->createConversationWithMessage($otherTenant, $otherOwner, 'palabra secreta compartida', '+5493333333333');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/conversations?search=secreta')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_member_only_searches_visible_conversations(): void
    {
        $member = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $member->assignRole('Member');

        $visible = $this->createConversationWithMessage($this->tenant, $member, 'presupuesto aprobado', '+5491111111111');
        $visible->users()->attach($member->id);

        $hidden = $this->createConversationWithMessage($this->tenant, $this->owner, 'presupuesto rechazado', '+5492222222222');

        Sanctum::actingAs($member);

        $ids = collect($this->getJson('/api/conversations?search=presupuesto')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    public function test_search_ignores_soft_deleted_messages(): void
    {
        $conversation = $this->createConversationWithMessage($this->tenant, $this->owner, 'mensaje borrable', '+5491111111111');
        $conversation->messages()->first()->delete();

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/conversations?search=borrable')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_search_shorter_than_two_characters_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/conversations?search=a')->assertStatus(422);
    }

    public function test_search_returns_matched_message_snippet(): void
    {
        $this->createConversationWithMessage($this->tenant, $this->owner, 'Necesito la factura de marzo', '+5491111111111');

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/conversations?search=factura')
            ->assertOk()
            ->assertJsonPath('data.0.matched_message_snippet', 'Necesito la factura de marzo');
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $this->createConversationWithMessage($this->tenant, $this->owner, 'Descuento del 100% garantizado', '+5491111111111');
        $this->createConversationWithMessage($this->tenant, $this->owner, 'Mensaje cualquiera sin numeros', '+5492222222222');

        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/conversations?search='.urlencode('100%'))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Descuento del 100% garantizado', $response->json('data.0.matched_message_snippet'));
    }
}
