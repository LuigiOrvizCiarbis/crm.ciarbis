<?php

namespace Tests\Feature\Notifications;

use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsNotificationWorkspace;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use BuildsNotificationWorkspace, RefreshDatabase;

    private function notification(int $userId, int $tenantId, array $attributes = []): UserNotification
    {
        return UserNotification::withoutGlobalScopes()->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'type' => 'task.assigned',
            'data' => [],
            ...$attributes,
        ]);
    }

    public function test_lists_only_own_notifications_in_active_workspace(): void
    {
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);
        $colleague = $this->addMember($tenant);
        $otherTenant = $this->createWorkspace();

        $mine = $this->notification($user->id, $tenant->id);
        $this->notification($colleague->id, $tenant->id);
        $this->notification($user->id, $otherTenant->id);

        Sanctum::actingAs($user);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('unread_count', 1);
    }

    public function test_unread_filter_and_count(): void
    {
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);
        $this->notification($user->id, $tenant->id);
        $this->notification($user->id, $tenant->id, ['read_at' => now()]);

        Sanctum::actingAs($user);
        $this->getJson('/api/notifications?unread=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/notifications/unread-count')->assertExactJson(['unread_count' => 1]);
    }

    public function test_mark_read_endpoints(): void
    {
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);
        $first = $this->notification($user->id, $tenant->id);
        $grouped = $this->notification($user->id, $tenant->id, ['group_key' => 'conversation:5']);
        $third = $this->notification($user->id, $tenant->id);

        Sanctum::actingAs($user);
        $this->postJson("/api/notifications/{$first->id}/read")->assertExactJson(['unread_count' => 2]);
        $this->postJson('/api/notifications/read-group', ['group_key' => 'conversation:5'])->assertExactJson(['unread_count' => 1]);
        $this->assertNotNull($grouped->fresh()->read_at);
        $this->assertNull($third->fresh()->read_at);
        $this->postJson('/api/notifications/read-all')->assertExactJson(['unread_count' => 0]);
    }

    public function test_cannot_mark_someone_elses_notification(): void
    {
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);
        $foreign = $this->notification($this->addMember($tenant)->id, $tenant->id);

        Sanctum::actingAs($user);
        $this->postJson("/api/notifications/{$foreign->id}/read")->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_updates_notification_preferences_without_touching_others(): void
    {
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);
        $user->forceFill(['preferences' => ['locale' => 'en']])->save();

        Sanctum::actingAs($user);
        $response = $this->putJson('/api/profile/notification-preferences', [
            'types' => ['message.received' => false, 'bogus' => true],
            'browser' => true,
        ])->assertOk()->assertJsonPath('data.preferences.locale', 'en');

        $notifications = $response->json()['data']['preferences']['notifications'];
        $this->assertFalse($notifications['types']['message.received']);
        $this->assertTrue($notifications['types']['task.assigned']);
        $this->assertTrue($notifications['browser']);
        $this->assertTrue($notifications['sound']);

        $this->assertArrayNotHasKey('bogus', $user->fresh()->preferences['notifications']['types']);

        // El endpoint general de preferencias ya no pisa las de notificaciones.
        $this->putJson('/api/profile/preferences', ['locale' => 'es', 'timezone' => 'UTC', 'date_format' => 'dd/MM/yyyy'])->assertOk();
        $this->assertFalse($user->fresh()->notificationPreferences()['types']['message.received']);
    }
}
