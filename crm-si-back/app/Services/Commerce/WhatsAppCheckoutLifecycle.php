<?php

namespace App\Services\Commerce;

use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\WhatsAppCheckout;
use App\Models\WhatsAppTemplate;
use App\Services\HumanHandoffService;
use App\Services\MessagingWindowService;
use App\Services\SystemMessageSender;
use App\Services\Templates\TemplateParameterRenderer;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ciclo de vida del pedido después de confirmarlo: pago, pago tardío y
 * cierre de pedidos sin pagar (vencimiento o reemplazo).
 */
class WhatsAppCheckoutLifecycle
{
    public function __construct(
        private readonly WooCommerceCheckoutGateway $gateway,
        private readonly MercadoPagoClient $mercadoPago,
        private readonly MessagingWindowService $window,
        private readonly SystemMessageSender $systemMessages,
        private readonly WhatsAppTemplateService $templates,
        private readonly TemplateParameterRenderer $renderer,
        private readonly HumanHandoffService $handoffs,
    ) {}

    /**
     * Mercado Pago manda varias notificaciones por pago, casi juntas: el lock
     * evita marcar el pedido pagado (y avisar) dos veces.
     */
    public function paymentUpdated(WhatsAppCheckout $checkout, array $payment): void
    {
        $this->exclusively($checkout, fn (WhatsAppCheckout $current) => $this->applyPayment($current, $payment));
    }

    private function applyPayment(WhatsAppCheckout $checkout, array $payment): void
    {
        $status = $payment['status'] ?? null;

        if ($status === 'approved') {
            if ($checkout->status === WhatsAppCheckout::AWAITING_PAYMENT) {
                $this->markPaid($checkout, (string) $payment['id']);
            } elseif (in_array($checkout->status, [WhatsAppCheckout::EXPIRED, WhatsAppCheckout::CANCELLED], true) && $checkout->paid_at === null) {
                $this->markPaid($checkout, (string) $payment['id'], late: true);
            }

            return;
        }

        if (in_array($status, ['rejected', 'cancelled'], true)
            && $checkout->status === WhatsAppCheckout::AWAITING_PAYMENT
            && $checkout->payment_failed_notified_at === null) {
            $conversation = $checkout->conversationUnscoped();
            if ($conversation !== null && $this->window->isOpen($conversation)) {
                $this->notify($conversation, "El pago del pedido #{$checkout->external_order_number} no se acreditó. Podés intentar de nuevo con el mismo botón de pago.", $checkout);
            }
            $checkout->update(['payment_failed_notified_at' => now()]);
        }
    }

    /**
     * Marca el pedido pagado en Woo y en el CRM. Un error de Woo se relanza
     * para que el job reintente: el estado todavía no cambió. Con $updateWoo
     * en false el pedido ya figura pagado en Woo (llegó por su webhook).
     */
    public function markPaid(WhatsAppCheckout $checkout, string $paymentId, bool $late = false, bool $updateWoo = true): void
    {
        $config = $checkout->wooConfig();

        if ($updateWoo) {
            $this->gateway->updateOrder($config, (int) $checkout->external_order_id, [
                'status' => 'processing',
                'transaction_id' => $paymentId,
                'meta_data' => [['key' => 'crm_mercadopago_payment_id', 'value' => $paymentId]],
            ]);
        }

        $conversation = $checkout->conversationUnscoped();

        DB::transaction(function () use ($checkout, $paymentId, $conversation): void {
            $checkout->update([
                'status' => WhatsAppCheckout::PAID,
                'paid_at' => now(),
                'payment_id' => $paymentId,
                'reminded_at' => null,
            ]);

            if ($conversation !== null && ! $conversation->isGroup()) {
                $checkout->update(['opportunity_id' => $this->winOpportunity($checkout, $conversation)->id]);
            }
        });

        if ($conversation === null) {
            return;
        }

        $number = (string) $checkout->external_order_number;

        if ($late) {
            $this->handoffs->create(
                $conversation,
                null,
                'whatsapp_checkout_late_payment',
                "Pago recibido después del vencimiento del pedido #{$number}. Revisá el stock antes de despacharlo.",
            );
        }

        $amount = WhatsAppCheckout::money((float) $checkout->total, $checkout->minorUnit());

        if ($this->window->isOpen($conversation)) {
            $this->notify($conversation, "¡Listo, recibimos tu pago! Pedido #{$number} por {$amount}. Te avisamos por acá cuando lo despachemos.", $checkout);

            return;
        }

        $this->sendTemplate($conversation, $checkout, $config?->paid_template_id, [
            ['component' => 'body', 'name' => 'pedido', 'source' => 'literal', 'value' => $number],
            ['component' => 'body', 'name' => 'total', 'source' => 'literal', 'value' => $amount],
        ]);
    }

    /**
     * Cambio de estado del pedido en Woo (webhook order.updated). Comparte el
     * lock con el webhook de pago para no marcar ni avisar dos veces.
     *
     * @param  array<string, mixed>  $wooOrder
     */
    public function orderUpdated(WhatsAppCheckout $checkout, array $wooOrder): void
    {
        $this->exclusively($checkout, function (WhatsAppCheckout $current) use ($wooOrder): void {
            $status = $wooOrder['status'] ?? null;

            if (in_array($status, ['processing', 'completed'], true) && $current->status === WhatsAppCheckout::AWAITING_PAYMENT) {
                $this->markPaid($current, (string) ($wooOrder['transaction_id'] ?? ''), updateWoo: false);
            }

            if ($status === 'completed' && $current->status === WhatsAppCheckout::PAID && $current->shipped_notified_at === null) {
                $this->notifyShipped($current, $wooOrder);

                return;
            }

            if ($status === 'cancelled' && $current->status === WhatsAppCheckout::AWAITING_PAYMENT) {
                $mpConfig = $current->mercadoPagoConfig();
                if ($mpConfig !== null && $current->payment_preference_id !== null) {
                    $this->mercadoPago->expirePreference($mpConfig, $current->payment_preference_id);
                }
                $current->update(['status' => WhatsAppCheckout::CANCELLED, 'closed_at' => now()]);
            }
        });
    }

    /**
     * Avisa que el pedido salió, con el número de seguimiento si la tienda lo
     * guarda en el meta configurado.
     *
     * @param  array<string, mixed>  $wooOrder
     */
    private function notifyShipped(WhatsAppCheckout $checkout, array $wooOrder): void
    {
        $config = $checkout->wooConfig();
        $tracking = null;

        if (! empty($config?->tracking_meta_key)) {
            foreach ($wooOrder['meta_data'] ?? [] as $meta) {
                if (is_array($meta) && ($meta['key'] ?? null) === $config->tracking_meta_key && is_scalar($meta['value'] ?? null)) {
                    $tracking = trim((string) $meta['value']) ?: null;
                    break;
                }
            }
        }

        $number = (string) $checkout->external_order_number;
        $conversation = $checkout->conversationUnscoped();

        if ($conversation !== null) {
            if ($this->window->isOpen($conversation)) {
                $this->notify(
                    $conversation,
                    "Tu pedido #{$number} ya está en camino 🚚".($tracking !== null ? "\nNúmero de seguimiento: {$tracking}" : ''),
                    $checkout,
                );
            } else {
                $this->sendTemplate($conversation, $checkout, $config?->shipped_template_id, [
                    ['component' => 'body', 'name' => 'pedido', 'source' => 'literal', 'value' => $number],
                    ['component' => 'body', 'name' => 'seguimiento', 'source' => 'literal', 'value' => $tracking ?? '-'],
                ]);
            }
        }

        $checkout->update(['tracking_number' => $tracking, 'shipped_notified_at' => now()]);
    }

    /**
     * Cierra un pedido sin pagar: libera el stock en Woo y vence el link.
     * Devuelve false si el pedido ya se pagó o dejó de estar sin pagar.
     *
     * @throws CheckoutException
     */
    public function closeUnpaid(WhatsAppCheckout $checkout, string $status): bool
    {
        return $this->exclusively($checkout, fn (WhatsAppCheckout $current) => $this->closeIfStillUnpaid($current, $status));
    }

    private function closeIfStillUnpaid(WhatsAppCheckout $checkout, string $status): bool
    {
        if ($checkout->status !== WhatsAppCheckout::AWAITING_PAYMENT) {
            return false;
        }

        $mpConfig = $checkout->mercadoPagoConfig();

        if ($mpConfig !== null) {
            foreach ($this->mercadoPago->searchPayments($mpConfig, "CRM-{$checkout->id}") as $payment) {
                if (($payment['status'] ?? null) === 'approved') {
                    $this->applyPayment($checkout, $payment);

                    return false;
                }
            }
        }

        if ($checkout->external_order_id !== null
            && ! $this->gateway->cancelIfUnpaid($checkout->wooConfig(), (int) $checkout->external_order_id)) {
            return false;
        }

        if ($mpConfig !== null && $checkout->payment_preference_id !== null) {
            $this->mercadoPago->expirePreference($mpConfig, $checkout->payment_preference_id);
        }

        $checkout->update(['status' => $status, 'closed_at' => now()]);

        return true;
    }

    /**
     * Ejecuta $callback con el checkout recién leído y el lock del checkout
     * tomado, para que el webhook de pago y el vencimiento no se pisen.
     */
    private function exclusively(WhatsAppCheckout $checkout, callable $callback): mixed
    {
        return Cache::lock("whatsapp-checkout:{$checkout->id}", 120)->block(60, function () use ($checkout, $callback) {
            return $callback(WhatsAppCheckout::withoutGlobalScopes()->findOrFail($checkout->id));
        });
    }

    private function winOpportunity(WhatsAppCheckout $checkout, Conversation $conversation): Opportunity
    {
        $opportunity = Opportunity::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('status', 'open')
            ->latest()
            ->first();

        if ($opportunity === null) {
            $pipeline = Pipeline::defaultFor($conversation->tenant_id);
            $opportunity = new Opportunity([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'pipeline_id' => $pipeline->id,
                'pipeline_stage_id' => $pipeline->entryStage()?->id,
                'title' => 'Oportunidad - '.($conversation->contact?->name ?? 'Sin nombre'),
                'status' => 'open',
                'source_type' => 'conversation',
                'last_activity_at' => now(),
            ]);
        }

        $won = PipelineStage::withoutGlobalScopes()
            ->where('pipeline_id', $opportunity->pipeline_id ?? Pipeline::defaultFor($conversation->tenant_id)->id)
            ->where('outcome', PipelineStage::OUTCOME_WON)
            ->orderBy('sort_order')
            ->first();

        $opportunity->fill([
            'value' => $checkout->total,
            'title' => "Pedido #{$checkout->external_order_number}",
            'last_activity_at' => now(),
        ]);

        if ($won !== null) {
            $opportunity->pipeline_stage_id = $won->id;
        } else {
            $opportunity->status = 'won';
        }

        $opportunity->save();

        return $opportunity;
    }

    /** Fuera de la ventana sólo se avisa con una plantilla aprobada del mismo número. */
    private function sendTemplate(Conversation $conversation, WhatsAppCheckout $checkout, ?int $templateId, array $parameters): void
    {
        if ($templateId === null) {
            return;
        }

        $template = WhatsAppTemplate::withoutGlobalScopes()->find($templateId);
        if ($template === null) {
            return;
        }

        if ($template->whatsapp_config_id !== $conversation->channel?->whatsapp_config_id) {
            Log::warning('Compra por WhatsApp: la plantilla es de otro número', [
                'checkout_id' => $checkout->id,
                'template_id' => $template->id,
            ]);

            return;
        }

        try {
            $this->templates->sendSystemTemplateMessage(
                $conversation,
                $template,
                $this->renderer->render($template, $parameters, [], $checkout->tenant_id),
            );
        } catch (Throwable $e) {
            Log::warning('Compra por WhatsApp: no se pudo enviar la plantilla', [
                'checkout_id' => $checkout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notify(Conversation $conversation, string $text, WhatsAppCheckout $checkout): void
    {
        try {
            $this->systemMessages->send($conversation, $text);
        } catch (Throwable $e) {
            Log::warning('Compra por WhatsApp: no se pudo enviar el aviso', [
                'checkout_id' => $checkout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
