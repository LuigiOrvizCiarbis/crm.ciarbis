<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Enums\WhatsAppFlowStatus;
use App\Models\Channel;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Services\Commerce\WhatsAppCheckoutInstaller;
use App\Services\Commerce\WhatsAppCheckoutTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppCheckoutInstallerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WhatsAppConfig $config;

    private bool $published = false;

    private bool $commerceFails = false;

    private bool $copyPublished = false;

    private bool $copyPublishFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->createTenantWithRoles();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $this->user->assignRole('Owner');
        $this->config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);
        Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $this->user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $this->config->id,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://graph.facebook.com/*/123456789/whatsapp_commerce_settings*' => fn () => $this->commerceFails
                ? Http::response(['error' => ['message' => 'Permission denied']], 403)
                : Http::response(['success' => true]),
            'https://graph.facebook.com/*/123456789/whatsapp_business_encryption' => Http::response(['success' => true]),
            // Como Meta: una variable no puede abrir ni cerrar el texto.
            'https://graph.facebook.com/*/waba-test/message_templates' => fn (Request $request) => preg_match('/^\s*\{\{|\}\}\s*$/', (string) $request['components'][0]['text']) === 1
                ? Http::response(['error' => ['message' => 'Variables can’t be at the start or end of the template.']], 400)
                : Http::response(['id' => 'tpl-'.$request['name'], 'status' => 'PENDING', 'category' => 'UTILITY']),
            // Como Meta: no admite dos formularios con el mismo nombre en la cuenta.
            'https://graph.facebook.com/*/waba-test/flows' => fn (Request $request) => isset($request['clone_flow_id']) && $request['name'] === WhatsAppCheckoutInstaller::FLOW_NAME
                ? Http::response(['error' => ['code' => 100, 'message' => 'Flow name must be unique within the WhatsApp Business Account.']], 400)
                : Http::response(['id' => isset($request['clone_flow_id']) ? 'flow-copy' : 'flow-checkout', 'success' => true]),
            'https://graph.facebook.com/*/flow-copy/publish' => function () {
                if ($this->copyPublishFails) {
                    return Http::response(['error' => ['message' => 'Invalid Flow JSON']], 400);
                }
                $this->copyPublished = true;

                return Http::response(['success' => true]);
            },
            'https://graph.facebook.com/*/flow-copy*' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response([
                    'id' => 'flow-copy',
                    'name' => WhatsAppCheckoutInstaller::FLOW_NAME,
                    'status' => $this->copyPublished ? 'PUBLISHED' : 'DRAFT',
                    'categories' => ['SHOPPING'],
                    'validation_errors' => [],
                    'json_version' => '7.3',
                    'data_api_version' => '3.0',
                ])
                : Http::response(['success' => true]),
            'https://graph.facebook.com/*/flow-checkout/publish' => function () {
                $this->published = true;

                return Http::response(['success' => true]);
            },
            'https://graph.facebook.com/*/flow-checkout*' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response([
                    'id' => 'flow-checkout',
                    'name' => WhatsAppCheckoutInstaller::FLOW_NAME,
                    'status' => $this->published ? 'PUBLISHED' : 'DRAFT',
                    'categories' => ['SHOPPING'],
                    'validation_errors' => [],
                    'json_version' => '7.3',
                    'data_api_version' => '3.0',
                ])
                : Http::response(['success' => true]),
        ]);
    }

    public function test_install_creates_publishes_the_checkout_flow_and_enables_the_cart(): void
    {
        $response = $this->installRequest();

        $flow = WhatsAppFlow::withoutGlobalScopes()->sole();
        $response->assertOk()
            ->assertJsonPath('data.0.whatsapp_config_id', $this->config->id)
            ->assertJsonPath('data.0.flow_id', $flow->id)
            ->assertJsonPath('data.0.status', 'PUBLISHED');

        $this->assertSame(WhatsAppFlow::SYSTEM_CHECKOUT, $flow->system_key);
        $this->assertSame(WhatsAppFlowStatus::Published, $flow->status);
        $this->assertSame(
            json_decode(file_get_contents(resource_path('flows/whatsapp-checkout.json')), true),
            json_decode($flow->published_version['flow_json'], true),
        );

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/waba-test/flows')
            && $request['categories'] === ['SHOPPING']
            && json_decode($request['flow_json'], true)['version'] === '7.3');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/123456789/whatsapp_commerce_settings')
            && str_contains($request->url(), 'is_cart_enabled=true')
            && str_contains($request->url(), 'is_catalog_visible=true'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/flow-checkout/publish'));

        $this->assertTrue(app(WhatsAppCheckoutInstaller::class)->hasPublishedFlow($this->user->tenant_id));
    }

    public function test_install_creates_the_paid_and_shipped_templates_once(): void
    {
        $this->installRequest()
            ->assertOk()
            ->assertJsonPath('data.0.templates', [WhatsAppCheckoutTemplates::PAID => 'PENDING', WhatsAppCheckoutTemplates::SHIPPED => 'PENDING'])
            ->assertJsonPath('data.0.template_error', null);

        $created = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/waba-test/message_templates'))->map(fn (array $pair) => $pair[0]);
        $this->assertSame([WhatsAppCheckoutTemplates::PAID, WhatsAppCheckoutTemplates::SHIPPED], $created->map(fn (Request $r) => $r['name'])->values()->all());
        $paid = $created->first();
        $this->assertSame('UTILITY', $paid['category']);
        $this->assertSame('es_AR', $paid['language']);
        $this->assertSame(['pedido', 'total'], array_column($paid['components'][0]['example']['body_text_named_params'], 'param_name'));

        // Reinstalar no las vuelve a pedir.
        $this->installRequest()->assertOk();
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/waba-test/message_templates')));
    }

    public function test_second_install_with_the_same_json_does_not_touch_the_flow(): void
    {
        $this->installRequest()->assertOk();

        $this->installRequest()->assertOk()->assertJsonPath('data.0.status', 'PUBLISHED');

        $this->assertSame(1, WhatsAppFlow::withoutGlobalScopes()->count());
        $this->assertCount(1, Http::recorded(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/waba-test/flows')));
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/flow-checkout/publish')));
        $this->assertCount(0, Http::recorded(fn (Request $request) => str_contains($request->url(), '/flow-checkout/assets')));
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), '/whatsapp_commerce_settings')));
    }

    public function test_commerce_settings_error_is_reported_without_stopping_the_install(): void
    {
        $this->commerceFails = true;

        $this->installRequest()
            ->assertOk()
            ->assertJsonPath('data.0.status', 'PUBLISHED')
            ->assertJsonPath('data.0.commerce_error', fn (string $error) => str_contains($error, 'Permission denied'));
    }

    public function test_a_changed_form_is_republished_and_takes_over_only_once_published(): void
    {
        $old = $this->installedWithOutdatedJson();

        $this->installRequest()->assertOk()->assertJsonPath('data.0.status', 'PUBLISHED');

        $copy = WhatsAppFlow::withoutGlobalScopes()->where('meta_flow_id', 'flow-copy')->sole();
        $this->assertSame(WhatsAppFlow::SYSTEM_CHECKOUT, $copy->system_key);
        $this->assertSame(WhatsAppFlowStatus::Published, $copy->status);
        $this->assertNull($old->fresh()->system_key);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/waba-test/flows')
            && isset($request['clone_flow_id'])
            && str_starts_with($request['name'], WhatsAppCheckoutInstaller::FLOW_NAME.' ('));
    }

    public function test_a_rejected_new_form_keeps_the_published_one_selling(): void
    {
        $old = $this->installedWithOutdatedJson();
        $this->copyPublishFails = true;

        $this->installRequest()->assertStatus(422);

        $this->assertSame(WhatsAppFlow::SYSTEM_CHECKOUT, $old->fresh()->system_key);
        $this->assertTrue(app(WhatsAppCheckoutInstaller::class)->hasPublishedFlow($this->user->tenant_id));
        $this->assertNull(WhatsAppFlow::withoutGlobalScopes()->where('meta_flow_id', 'flow-copy')->value('system_key'));
    }

    /** Formulario instalado y publicado con un JSON anterior al del repo. */
    private function installedWithOutdatedJson(): WhatsAppFlow
    {
        $this->installRequest()->assertOk();
        $flow = WhatsAppFlow::withoutGlobalScopes()->sole();
        $version = $flow->published_version;
        $json = json_decode($version['flow_json'], true);
        $json['screens'][0]['title'] = 'Versión anterior';
        $version['flow_json'] = json_encode($json);
        $flow->forceFill(['published_version' => $version])->save();

        return $flow;
    }

    private function installRequest()
    {
        Sanctum::actingAs($this->user);

        return $this->postJson('/api/woocommerce-config/whatsapp-checkout/install');
    }
}
