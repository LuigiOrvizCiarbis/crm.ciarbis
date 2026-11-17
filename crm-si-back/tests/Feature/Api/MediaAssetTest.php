<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MediaAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_upload_is_stored_and_returns_public_url(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/media-assets', [
            'file' => UploadedFile::fake()->create('comprobante.pdf', 120, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'comprobante.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf');

        $this->assertStringContainsString('/storage/media-assets/', $response->json('data.url'));
        $this->assertDatabaseHas('media_assets', ['tenant_id' => $owner->tenant_id, 'name' => 'comprobante.pdf']);
        Storage::disk('public')->assertExists(MediaAsset::first()->path);
    }

    public function test_uploads_follow_whatsapp_types_and_limits(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->owner());
        $upload = fn (string $name, int $kilobytes, string $mime) => $this->postJson('/api/media-assets', [
            'file' => UploadedFile::fake()->create($name, $kilobytes, $mime),
        ]);

        $upload('foto.png', 50, 'image/png')->assertCreated()->assertJsonPath('data.mime_type', 'image/png');
        $upload('video.mp4', 900, 'video/mp4')->assertCreated();
        $upload('foto.gif', 50, 'image/gif')->assertUnprocessable()->assertJsonValidationErrors('file');
        $upload('grande.jpg', 6 * 1024, 'image/jpeg')->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_assets_are_scoped_to_tenant(): void
    {
        $otherOwner = $this->owner();
        $foreign = MediaAsset::create([
            'tenant_id' => $otherOwner->tenant_id, 'name' => 'ajeno.pdf',
            'path' => 'media-assets/x/ajeno.pdf', 'mime_type' => 'application/pdf', 'size' => 100,
        ]);

        Sanctum::actingAs($this->owner());
        $this->deleteJson("/api/media-assets/{$foreign->id}")->assertNotFound();
    }

    private function owner(): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();
        $tenant = Tenant::create(['name' => 'Tenant '.Str::random(8), 'timezone' => 'America/Argentina/Buenos_Aires']);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $owner->assignRole('Owner');

        return $owner;
    }
}
