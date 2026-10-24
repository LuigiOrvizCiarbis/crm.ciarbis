<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Events\UserNotificationCreated;
use App\Models\Branch;
use App\Models\TenantMembership;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationRecipients;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsNotificationWorkspace;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use BuildsNotificationWorkspace, RefreshDatabase;

    public function test_creates_and_broadcasts_notification(): void
    {
        Event::fake([UserNotificationCreated::class]);
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);

        app(NotificationService::class)->notify([$user], $tenant->id, NotificationType::TASK_ASSIGNED, ['task_id' => 7]);

        $notification = UserNotification::withoutGlobalScopes()->sole();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame($tenant->id, $notification->tenant_id);
        $this->assertSame(['task_id' => 7], $notification->data);
        Event::assertDispatched(UserNotificationCreated::class, fn ($event) => $event->notification->is($notification));
    }

    public function test_groups_unread_notifications_by_group_key(): void
    {
        Event::fake([UserNotificationCreated::class]);
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);
        $service = app(NotificationService::class);

        $service->notify([$user], $tenant->id, NotificationType::MESSAGE_RECEIVED, ['preview' => 'hola'], 'conversation:1');
        $service->notify([$user], $tenant->id, NotificationType::MESSAGE_RECEIVED, ['preview' => 'sigo acá'], 'conversation:1');

        $notification = UserNotification::withoutGlobalScopes()->sole();
        $this->assertSame(2, $notification->count);
        $this->assertSame('sigo acá', $notification->data['preview']);
        Event::assertDispatchedTimes(UserNotificationCreated::class, 2);

        // Una vez leída, el próximo evento abre una fila nueva.
        $notification->update(['read_at' => now()]);
        $service->notify([$user], $tenant->id, NotificationType::MESSAGE_RECEIVED, ['preview' => 'otra'], 'conversation:1');
        $this->assertSame(2, UserNotification::withoutGlobalScopes()->count());
    }

    public function test_concurrent_first_grouped_notification_is_merged_not_duplicated(): void
    {
        Event::fake([UserNotificationCreated::class]);
        $tenant = $this->createWorkspace();
        $user = $this->addMember($tenant);

        // Simula otro job que inserta la fila agrupada entre el SELECT (sin
        // resultado) y el INSERT de este proceso.
        $raced = false;
        DB::listen(function ($query) use (&$raced, $user, $tenant) {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'user_notifications')) {
                return;
            }
            $raced = true;
            DB::table('user_notifications')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'type' => 'message.received',
                'group_key' => 'conversation:1',
                'data' => '{}',
                'count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        app(NotificationService::class)->notify([$user], $tenant->id, NotificationType::MESSAGE_RECEIVED, ['preview' => 'hola'], 'conversation:1');

        $notification = UserNotification::withoutGlobalScopes()->sole();
        $this->assertSame(2, $notification->count);
        $this->assertSame('hola', $notification->data['preview']);
    }

    public function test_skips_disabled_types_and_excluded_actor(): void
    {
        Event::fake([UserNotificationCreated::class]);
        $tenant = $this->createWorkspace();
        $muted = $this->addMember($tenant);
        $muted->forceFill(['preferences' => ['notifications' => ['types' => ['task.assigned' => false]]]])->save();
        $actor = $this->addMember($tenant);

        app(NotificationService::class)->notify([$muted, $actor], $tenant->id, NotificationType::TASK_ASSIGNED, [], null, $actor->id);

        $this->assertSame(0, UserNotification::withoutGlobalScopes()->count());
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_conversation_recipients_prefer_assignees(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant, 'Owner');
        $admin = $this->addMember($tenant, 'Admin');
        $member = $this->addMember($tenant, 'Member');
        $conversation = $this->createConversation($tenant, $owner);
        $recipients = app(NotificationRecipients::class);

        // Sin asignar: quienes pueden verla (Owner/Admin con view_any; el Member
        // no tiene la conversación asignada ni acceso al canal).
        $this->assertEqualsCanonicalizing(
            [$owner->id, $admin->id],
            $recipients->forConversation($conversation)->pluck('id')->all(),
        );

        $conversation->update(['assigned_to' => $member->id]);
        $this->assertSame([$member->id], $recipients->forConversation($conversation->fresh())->pluck('id')->all());
    }

    public function test_conversation_recipients_fall_back_when_assignee_cannot_receive(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant, 'Owner');
        $leaver = $this->addMember($tenant, 'Member');
        $conversation = $this->createConversation($tenant, $owner);
        $conversation->update(['assigned_to' => $leaver->id]);
        $recipients = app(NotificationRecipients::class);

        // Asignado que dejó el workspace: avisa a quienes pueden verla.
        TenantMembership::query()->where('user_id', $leaver->id)->update(['removed_at' => now()]);
        $this->assertSame([$owner->id], $recipients->forConversation($conversation->fresh())->pluck('id')->all());

        // Asignado activo pero sin acceso (Member sin view_any de otra sucursal).
        TenantMembership::query()->where('user_id', $leaver->id)->update(['removed_at' => null]);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Norte']);
        $other = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Sur']);
        TenantMembership::query()->where('user_id', $leaver->id)->update(['branch_id' => $branch->id]);
        $conversation->forceFill(['branch_id' => $other->id])->save();
        $this->assertSame([$owner->id], $recipients->forConversation($conversation->fresh())->pluck('id')->all());
    }

    public function test_conversation_recipients_ignore_other_workspaces(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant);
        $other = $this->addMember($this->createWorkspace());
        $conversation = $this->createConversation($tenant, $owner);

        $ids = app(NotificationRecipients::class)->forConversation($conversation)->pluck('id')->all();

        $this->assertSame([$owner->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_owners_returns_only_tenant_owners(): void
    {
        $tenant = $this->createWorkspace();
        $owner = $this->addMember($tenant, 'Owner');
        $this->addMember($tenant, 'Admin');

        $this->assertSame([$owner->id], app(NotificationRecipients::class)->owners($tenant->id)->pluck('id')->all());
    }
}
