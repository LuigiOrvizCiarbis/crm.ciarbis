<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Events\WhatsAppCheckoutUpdated;
use App\Models\Message;
use App\Models\Product;
use App\Models\User;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\WhatsAppCheckoutFixtures;
use Tests\TestCase;

/** Tarjeta del pedido y envío del catálogo desde el chat. */
class WhatsAppCheckoutCardTest extends TestCase
{
    use RefreshDatabase;
    use WhatsAppCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCheckoutWorld();
        app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user->assignRole('Owner');
        $this->fakeCommerce();
    }

    public function test_returns_the_latest_checkout_of_the_conversation(): void
    {
        $checkout = $this->awaitingPaymentCheckout();
        Sanctum::actingAs($this->user);

        $this->getJson("/api/conversations/{$this->conversation->id}/whatsapp-checkout")
            ->assertOk()
            ->assertJsonPath('data.id', $checkout->id)
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.order_number', '1001')
            ->assertJsonPath('data.currency', 'ARS')
            ->assertJsonPath('data.items.0.name', 'Conjunto bebé futbol boca')
            ->assertJsonPath('data.items.0.variant_label', '2')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.payment_url', $checkout->payment_url)
            ->assertJsonPath('data.order_admin_url', fn (string $url) => str_ends_with($url, 'post.php?post=501&action=edit'));
    }

    public function test_without_checkout_returns_null(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson("/api/conversations/{$this->conversation->id}/whatsapp-checkout")
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_other_tenant_gets_404(): void
    {
        $this->awaitingPaymentCheckout();
        $other = $this->createTenantWithRoles('Otra');
        $outsider = User::factory()->create(['tenant_id' => $other->id, 'role' => UserRole::ADMIN]);
        $outsider->assignRole('Owner');
        Sanctum::actingAs($outsider);

        $this->getJson("/api/conversations/{$this->conversation->id}/whatsapp-checkout")->assertNotFound();
    }

    public function test_sends_the_whatsapp_catalog(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson("/api/conversations/{$this->conversation->id}/whatsapp-catalog")
            ->assertCreated()
            ->assertJsonPath('data.interactive.kind', 'catalog');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['interactive']['type'] === 'catalog_message'
            && $request['interactive']['action']['name'] === 'catalog_message'
            && $request['interactive']['body']['text'] === 'Mirá nuestro catálogo y armá tu carrito acá mismo.');
        $this->assertFalse((bool) $this->conversation->fresh()->ai_autoreply_enabled);
    }

    public function test_catalog_with_closed_window_is_422(): void
    {
        Message::withTrashed()->forceDelete();
        Sanctum::actingAs($this->user);

        $this->postJson("/api/conversations/{$this->conversation->id}/whatsapp-catalog", ['body' => 'Mirá'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
        Http::assertNothingSent();
    }

    public function test_catalog_without_products_in_meta_explains_the_cause(): void
    {
        $this->catalogProductsMissing = true;
        Sanctum::actingAs($this->user);

        $this->postJson("/api/conversations/{$this->conversation->id}/whatsapp-catalog")
            ->assertStatus(422)
            ->assertJsonPath('message', 'WhatsApp no encuentra los productos del catálogo conectado a este número. Revisá en Commerce Manager que el catálogo esté habilitado para WhatsApp.');
    }

    public function test_catalog_uses_an_enabled_product_as_thumbnail_skipping_rejected_ones(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Body River', 'price' => 23140, 'is_active' => true, 'source' => 'woocommerce', 'external_id' => '3798']);
        Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Llavero', 'price' => 1815, 'is_active' => true, 'source' => 'woocommerce', 'external_id' => '2816']);
        $this->missingRetailerIds = ['2816'];
        Sanctum::actingAs($this->user);

        $this->postJson("/api/conversations/{$this->conversation->id}/whatsapp-catalog")->assertCreated();

        $thumbnails = Http::recorded(fn (Request $request) => ($request['interactive']['type'] ?? null) === 'catalog_message')
            ->map(fn (array $pair) => $pair[0]['interactive']['action']['parameters']['thumbnail_product_retailer_id'] ?? null)
            ->values()
            ->all();
        // El más nuevo (2816) no está habilitado: se prueba con el siguiente.
        $this->assertSame(['2816', '3798'], $thumbnails);

        // La próxima vez arranca por el que Meta aceptó.
        $this->postJson("/api/conversations/{$this->conversation->id}/whatsapp-catalog")->assertCreated();
        $this->assertSame('3798', Http::recorded(fn (Request $request) => ($request['interactive']['type'] ?? null) === 'catalog_message')
            ->last()[0]['interactive']['action']['parameters']['thumbnail_product_retailer_id']);
    }

    public function test_catalog_thumbnail_starts_with_a_product_customers_sent_in_a_cart(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Llavero', 'price' => 1815, 'is_active' => true, 'source' => 'woocommerce', 'external_id' => '2816']);
        Message::create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $this->conversation->contact_id,
            'content' => '🛒 Carrito: 1 productos',
            'message_type' => MessageType::Order,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'in.order',
            'interactive' => ['kind' => 'order', 'catalog_id' => 'catalog-1', 'product_items' => [['product_retailer_id' => '3798', 'quantity' => 1]]],
        ]);
        Sanctum::actingAs($this->user);

        $this->postJson("/api/conversations/{$this->conversation->id}/whatsapp-catalog")->assertCreated();

        $this->assertSame(['3798'], Http::recorded(fn (Request $request) => ($request['interactive']['type'] ?? null) === 'catalog_message')
            ->map(fn (array $pair) => $pair[0]['interactive']['action']['parameters']['thumbnail_product_retailer_id'])
            ->values()
            ->all());
    }

    public function test_catalog_without_enabled_thumbnails_says_meta_is_still_reviewing_products(): void
    {
        Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Llavero', 'price' => 1815, 'is_active' => true, 'source' => 'woocommerce', 'external_id' => '2816']);
        $this->missingRetailerIds = ['2816'];
        Sanctum::actingAs($this->user);

        $this->postJson("/api/conversations/{$this->conversation->id}/whatsapp-catalog")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'todavía no habilitó'));
    }

    public function test_an_abandoned_cart_expiring_without_a_message_still_refreshes_the_card(): void
    {
        $checkout = $this->makeCheckout();
        $checkout->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->saveQuietly();
        Event::fake([WhatsAppCheckoutUpdated::class]);

        $this->artisan('whatsapp-checkouts:follow-up')->assertSuccessful();

        Event::assertDispatched(
            WhatsAppCheckoutUpdated::class,
            fn (WhatsAppCheckoutUpdated $event) => $event->checkout->is($checkout)
                && $event->broadcastWith()['status'] === 'expired'
                && $event->broadcastOn()[0]->name === "private-conversations.{$this->conversation->id}",
        );
    }

    public function test_bookkeeping_changes_do_not_refresh_the_card(): void
    {
        $checkout = $this->awaitingPaymentCheckout();
        Event::fake([WhatsAppCheckoutUpdated::class]);

        $checkout->update(['reminded_at' => now()]);

        Event::assertNotDispatched(WhatsAppCheckoutUpdated::class);
    }
}
