<?php

namespace Tests\Feature\Concerns;

use App\Enums\AiBookingStatus;
use App\Enums\ChannelType;
use App\Models\AiSchedulingConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\GoogleCalendarConnection;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GoogleCalendarClient;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant, vendedor con Google conectado, conversación de WhatsApp y config de
 * agenda para los tests de turnos de la IA. "Ahora" es el lunes 20/07/2026
 * 08:00 en Buenos Aires (ver freezeMonday()).
 */
trait AiSchedulingFixtures
{
    protected const TZ = 'America/Argentina/Buenos_Aires';

    protected function freezeMonday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 08:00:00', self::TZ));
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00', self::TZ));

        config()->set('services.google_calendar.client_id', 'test-client-id');
        config()->set('services.google_calendar.client_secret', 'test-client-secret');
        config()->set('services.google_calendar.redirect_uri', 'http://localhost/api/google-calendar/callback');
    }

    protected function resetSchedulingFakes(): void
    {
        GoogleCalendarClient::$testHttpClient = null;
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    protected function makeTenantWithOwner(string $role = 'Owner'): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    protected function makeUser(Tenant $tenant): User
    {
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    protected function connectGoogle(User $user, string $status = 'connected'): GoogleCalendarConnection
    {
        $connection = GoogleCalendarConnection::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'google_email' => "user{$user->id}@example.com",
            'access_token' => 'placeholder',
            'refresh_token' => 'placeholder',
            // El SDK compara el vencimiento contra time() real, no contra la
            // hora congelada del test: con un vencimiento corto el token
            // "venció" y el cliente intentaría un refresh contra el mock.
            'token_expires_at' => now()->addYears(10),
            'scopes' => GoogleCalendarClient::SCOPES,
            'status' => $status,
        ]);
        $connection->setEncryptedAccessToken('access-token');
        $connection->setEncryptedRefreshToken('refresh-token');
        $connection->save();

        return $connection;
    }

    protected function makeConversation(Tenant $tenant, User $channelOwner, ?int $assignedTo = null, bool $aiEnabled = true): Conversation
    {
        $channel = Channel::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $channelOwner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);

        $contact = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'assigned_to' => $assignedTo,
            'status' => 'open',
            'ai_autoreply_enabled' => $aiEnabled,
        ]);
    }

    /**
     * Config habilitada: lunes a viernes 09:00-12:00, turnos de 30 min cada
     * 30 min, 2 h de anticipación.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeSchedulingConfig(Tenant $tenant, array $overrides = []): AiSchedulingConfig
    {
        $weekday = [['start' => '09:00', 'end' => '12:00']];

        return AiSchedulingConfig::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'mode' => 'approval',
            'timezone' => self::TZ,
            'meeting_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'min_notice_hours' => 2,
            'max_days_ahead' => 14,
            'working_hours' => [
                'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday, 'thu' => $weekday, 'fri' => $weekday,
                'sat' => [], 'sun' => [],
            ],
            ...$overrides,
        ]);
    }

    protected function makePendingBooking(Conversation $conversation, User $owner, string $start = '2026-07-21 11:00', AiBookingStatus $status = AiBookingStatus::PENDING): Task
    {
        $startsAt = CarbonImmutable::parse($start, self::TZ);

        return Task::withoutGlobalScopes()->create([
            'tenant_id' => $conversation->tenant_id,
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->id,
            'assigned_to' => $owner->id,
            'name' => 'Reunión con Jane Doe',
            'status' => 'nuevo',
            'priority' => 'media',
            'type' => 'reunion',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'deadline' => $startsAt,
            'meeting_timezone' => self::TZ,
            'ai_booking_status' => $status,
        ]);
    }

    /**
     * Respuesta de events.list con los eventos dados.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function googleEvents(array $items = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['items' => $items]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function googleEvent(string $start, string $end, array $extra = []): array
    {
        return [
            'status' => 'confirmed',
            'start' => ['dateTime' => CarbonImmutable::parse($start, self::TZ)->toRfc3339String()],
            'end' => ['dateTime' => CarbonImmutable::parse($end, self::TZ)->toRfc3339String()],
            ...$extra,
        ];
    }

    /**
     * @param  list<Response>  $responses
     */
    protected function mockGoogle(array $responses): MockHandler
    {
        $mock = new MockHandler($responses);
        GoogleCalendarClient::$testHttpClient = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        return $mock;
    }
}
