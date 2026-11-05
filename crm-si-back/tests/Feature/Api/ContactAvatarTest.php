<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContactAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_upload_replaces_channel_photo_and_is_served_everywhere(): void
    {
        Storage::fake('public');
        [$tenant, $owner] = $this->makeTenantUser('Owner');
        $contact = $this->makeContact($tenant);
        Storage::disk('public')->put('contact-avatars/channel.jpg', 'OLD');
        $contact->forceFill(['avatar_path' => 'contact-avatars/channel.jpg', 'avatar_source' => 'facebook'])->saveQuietly();
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'external_id' => 'PHONE_1',
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);
        $contact->conversations()->create(['tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'status' => 'open']);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/contacts/{$contact->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ])->assertOk()
            ->assertJsonPath('data.avatar_source', 'manual');

        $contact->refresh();
        Storage::disk('public')->assertExists($contact->avatar_path);
        Storage::disk('public')->assertMissing('contact-avatars/channel.jpg');
        $this->assertSame($contact->avatar_url, $response->json('data.avatar_url'));
        $this->assertStringEndsWith('/storage/'.$contact->avatar_path, $contact->avatar_url);
        // La foto no es un dato que proteja el contador de concurrencia: una
        // extracción de documentos pendiente no debe fallar con 409 por esto.
        $this->assertSame(0, (int) $contact->lock_version);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertJsonPath('data.avatar_url', $contact->avatar_url)
            ->assertJsonPath('data.avatar_source', 'manual')
            ->assertJsonMissingPath('data.avatar_path');
        $this->getJson('/api/conversations')
            ->assertJsonPath('data.0.contact.avatar_url', $contact->avatar_url);
    }

    public function test_upload_rejects_non_image(): void
    {
        Storage::fake('public');
        [$tenant, $owner] = $this->makeTenantUser('Owner');
        $contact = $this->makeContact($tenant);
        Sanctum::actingAs($owner);

        $this->postJson("/api/contacts/{$contact->id}/avatar", [
            'avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);

        $this->assertNull($contact->fresh()->avatar_path);
    }

    public function test_user_who_cannot_update_the_contact_cannot_change_its_photo(): void
    {
        Storage::fake('public');
        [$tenant] = $this->makeTenantUser('Owner');
        [, $member] = $this->makeTenantUser('Member', $tenant);
        $contact = $this->makeContact($tenant);
        Sanctum::actingAs($member);

        $this->postJson("/api/contacts/{$contact->id}/avatar", [
            'avatar' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ])->assertForbidden();

        $this->assertNull($contact->fresh()->avatar_path);
    }

    public function test_removing_manual_photo_lets_the_channel_photo_return(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('contact-avatars/manual.jpg', 'IMG');
        [$tenant, $owner] = $this->makeTenantUser('Owner');
        $contact = $this->makeContact($tenant);
        $contact->forceFill([
            'avatar_path' => 'contact-avatars/manual.jpg',
            'avatar_source' => 'manual',
            'avatar_checked_at' => now(),
        ])->save();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/contacts/{$contact->id}/avatar")
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $contact->refresh();
        Storage::disk('public')->assertMissing('contact-avatars/manual.jpg');
        $this->assertNull($contact->avatar_source);
        // Sin chequeo previo: el próximo mensaje entrante vuelve a consultar el canal.
        $this->assertNull($contact->avatar_checked_at);
    }

    public function test_delete_keeps_a_channel_photo(): void
    {
        Storage::fake('public');
        [$tenant, $owner] = $this->makeTenantUser('Owner');
        $contact = $this->makeContact($tenant);
        $contact->forceFill(['avatar_path' => 'contact-avatars/channel.jpg', 'avatar_source' => 'instagram'])->save();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/contacts/{$contact->id}/avatar")
            ->assertOk()
            ->assertJsonPath('data.avatar_source', 'instagram');

        $this->assertSame('contact-avatars/channel.jpg', $contact->fresh()->avatar_path);
    }

    public function test_deleting_the_contact_deletes_its_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('contact-avatars/manual.jpg', 'IMG');
        [$tenant] = $this->makeTenantUser('Owner');
        $contact = $this->makeContact($tenant);
        $contact->forceFill(['avatar_path' => 'contact-avatars/manual.jpg', 'avatar_source' => 'manual'])->save();

        $contact->delete();

        Storage::disk('public')->assertMissing('contact-avatars/manual.jpg');
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeTenantUser(string $role, ?Tenant $tenant = null): array
    {
        if ($tenant === null) {
            $tenant = Tenant::create(['name' => 'Acme']);
            app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        }

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->syncRoles([$role]);

        return [$tenant, $user];
    }

    private function makeContact(Tenant $tenant): Contact
    {
        return Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana',
            'phone' => '5491100000000',
            'source' => 'whatsapp',
        ]);
    }
}
