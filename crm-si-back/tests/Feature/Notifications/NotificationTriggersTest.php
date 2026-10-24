<?php

namespace Tests\Feature\Notifications;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Message;
use App\Models\ProductImport;
use App\Models\Task;
use App\Models\UserNotification;
use App\Services\HumanHandoffService;
use App\Services\Notifications\DomainNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsNotificationWorkspace;
use Tests\TestCase;

class NotificationTriggersTest extends TestCase
{
    use BuildsNotificationWorkspace, RefreshDatabase;

    private function notificationsFor(int $userId, ?string $type = null)
    {
        return UserNotification::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->when($type, fn ($query) => $query->where('type', $type))
            ->get();
    }

    private function inbound(int $conversationId, int $tenantId, int $contactId, array $attributes = []): Message
    {
        return Message::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contactId,
            'content' => 'Hola, ¿tienen stock?',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'delivered_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_inbound_message_notifies_viewers_and_groups_burst(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant, 'Owner');
        $member = $this->addMember($tenant, 'Member');
        $conversation = $this->createConversation($tenant, $owner);

        $this->inbound($conversation->id, $tenant->id, $conversation->contact_id);
        $this->inbound($conversation->id, $tenant->id, $conversation->contact_id, ['content' => 'Sigo esperando']);

        $notification = $this->notificationsFor($owner->id, 'message.received')->sole();
        $this->assertSame(2, $notification->count);
        $this->assertSame('Sigo esperando', $notification->data['preview']);
        $this->assertSame('Jane Doe', $notification->data['contact_name']);
        $this->assertSame('/chats?chat='.$conversation->id, $notification->data['url']);
        $this->assertSame('conversation:'.$conversation->id, $notification->group_key);
        $this->assertCount(0, $this->notificationsFor($member->id));
    }

    public function test_inbound_message_only_notifies_assignee_when_assigned(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant, 'Owner');
        $member = $this->addMember($tenant, 'Member');
        $conversation = $this->createConversation($tenant, $owner);
        $conversation->update(['assigned_to' => $member->id]);

        $this->inbound($conversation->id, $tenant->id, $conversation->contact_id);

        $this->assertCount(1, $this->notificationsFor($member->id, 'message.received'));
        $this->assertCount(0, $this->notificationsFor($owner->id));
    }

    public function test_outbound_and_history_messages_do_not_notify(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant);
        $conversation = $this->createConversation($tenant, $owner);

        $this->inbound($conversation->id, $tenant->id, $conversation->contact_id, [
            'direction' => MessageDirection::OUTBOUND,
            'sender_type' => SenderType::USER,
            'sender_id' => $owner->id,
        ]);
        // Importación de historial: delivered_at con la fecha original.
        $this->inbound($conversation->id, $tenant->id, $conversation->contact_id, ['delivered_at' => now()->subDays(3)]);

        $this->assertSame(0, UserNotification::withoutGlobalScopes()->count());
    }

    public function test_assigning_users_notifies_added_and_removed_but_not_actor(): void
    {
        $tenant = $this->createWorkspace();
        $actor = $this->addMember($tenant, 'Owner');
        $first = $this->addMember($tenant, 'Member');
        $second = $this->addMember($tenant, 'Member');
        $conversation = $this->createConversation($tenant, $actor);
        Sanctum::actingAs($actor);

        $this->postJson("/api/conversations/{$conversation->id}/users", ['user_ids' => [$first->id, $actor->id]])->assertOk();
        $this->assertCount(1, $this->notificationsFor($first->id, 'conversation.assigned'));
        $this->assertCount(0, $this->notificationsFor($actor->id));

        $this->postJson("/api/conversations/{$conversation->id}/users", ['user_ids' => [$second->id]])->assertOk();
        $this->assertCount(1, $this->notificationsFor($first->id, 'conversation.unassigned'));
        $this->assertCount(1, $this->notificationsFor($second->id, 'conversation.assigned'));

        // Re-agregar a alguien ya asignado no vuelve a notificar.
        $this->postJson("/api/conversations/{$conversation->id}/users/add", ['user_id' => $second->id])->assertOk();
        $this->assertCount(1, $this->notificationsFor($second->id, 'conversation.assigned'));

        $this->deleteJson("/api/conversations/{$conversation->id}/users/{$second->id}")->assertOk();
        $this->assertCount(1, $this->notificationsFor($second->id, 'conversation.unassigned'));
    }

    public function test_handoff_notifies_responsible_user(): void
    {
        Queue::fake();
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant);
        $conversation = $this->createConversation($tenant, $owner);

        $handoff = app(HumanHandoffService::class)->create($conversation, null, 'customer_requested_human', 'Quiere hablar con alguien');

        $notification = $this->notificationsFor($owner->id, 'handoff.requested')->sole();
        $this->assertSame($handoff->id, $notification->data['handoff_id']);
        $this->assertSame('Quiere hablar con alguien', $notification->data['summary']);
    }

    public function test_task_assignment_and_import_notifications(): void
    {
        $tenant = $this->createWorkspace();
        $actor = $this->addMember($tenant);
        $assignee = $this->addMember($tenant, 'Member');
        $notifier = app(DomainNotifier::class);

        $task = Task::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'assigned_to' => $assignee->id,
            'name' => 'Llamar a Jane',
            'status' => TaskStatus::cases()[0],
            'priority' => TaskPriority::MEDIUM,
            'type' => TaskType::CALL,
        ]);
        $notifier->taskAssigned($task, $actor);
        $notifier->taskAssigned($task->fill(['assigned_to' => $actor->id]), $actor);

        $this->assertSame('Llamar a Jane', $this->notificationsFor($assignee->id, 'task.assigned')->sole()->data['task_name']);
        $this->assertCount(0, $this->notificationsFor($actor->id));

        $import = (new ProductImport)->forceFill([
            'id' => 99,
            'tenant_id' => $tenant->id,
            'resource' => 'contacts',
            'requested_by' => $actor->id,
            'original_filename' => 'contactos.xlsx',
            'file_path' => 'imports/x.xlsx',
            'status' => 'completed',
            'result' => ['created' => 10],
        ]);
        $notifier->importFinished($import);

        $notification = $this->notificationsFor($actor->id, 'import.completed')->sole();
        $this->assertSame('/contactos', $notification->data['url']);
    }

    public function test_trial_reminder_command_notifies_owners_once_per_threshold(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant, 'Owner');
        $admin = $this->addMember($tenant, 'Admin');
        $tenant->forceFill(['trial_ends_at' => now()->addDays(3)->subHour()])->save();

        $this->artisan('notifications:trial-reminders')->assertSuccessful();
        $this->artisan('notifications:trial-reminders')->assertSuccessful();

        $notification = $this->notificationsFor($owner->id, 'trial.expiring')->sole();
        $this->assertSame(3, $notification->data['days_left']);
        $this->assertCount(0, $this->notificationsFor($admin->id));
    }
}
