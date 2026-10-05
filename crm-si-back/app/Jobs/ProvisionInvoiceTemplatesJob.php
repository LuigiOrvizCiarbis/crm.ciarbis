<?php

namespace App\Jobs;

use App\Enums\TemplateCategory;
use App\Models\InvoiceTemplateProvisioning;
use App\Models\Scopes\TenantScope;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionInvoiceTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $provisioningId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function failed(\Throwable $exception): void
    {
        $provisioning = InvoiceTemplateProvisioning::withoutGlobalScope(TenantScope::class)->find($this->provisioningId);
        if (! $provisioning) {
            return;
        }
        $message = mb_substr($exception->getMessage(), 0, 4000);
        $changes = ['state' => 'partial'];
        if (! $provisioning->invoice_template_id && ! $provisioning->invoice_error) {
            $changes['invoice_error'] = $message;
        }
        if (! $provisioning->reminder_template_id && ! $provisioning->reminder_error) {
            $changes['reminder_error'] = $message;
        }
        $provisioning->forceFill($changes)->save();
    }

    public function handle(WhatsAppTemplateService $templates): void
    {
        $provisioning = InvoiceTemplateProvisioning::withoutGlobalScope(TenantScope::class)->with(['invoiceTemplate', 'reminderTemplate'])->find($this->provisioningId);
        if (! $provisioning || in_array($provisioning->state, ['ready', 'rejected'], true)) {
            return;
        }

        $provisioning->forceFill(['state' => 'creating'])->save();
        $config = $provisioning->whatsappConfig()->first();
        $templates->syncTemplates($config, $provisioning->tenant_id);

        foreach (['invoice', 'reminder'] as $role) {
            $idColumn = $role.'_template_id';
            $errorColumn = $role.'_error';
            if ($provisioning->$idColumn) {
                continue;
            }

            $name = $role === 'invoice'
                ? "si_invoice_{$provisioning->tenant_id}_cobro_v{$provisioning->version}"
                : "si_invoice_{$provisioning->tenant_id}_recordatorio_v{$provisioning->version}";

            try {
                // Stable name lets a retry recover a create accepted by Meta before a worker/network failure.
                $template = WhatsAppTemplate::withoutGlobalScope(TenantScope::class)
                    ->where('whatsapp_config_id', $config->id)->where('name', $name)->where('language', 'es_AR')->first();
                if (! $template) {
                    $components = $role === 'invoice' ? $this->invoiceComponents($config, $templates) : $this->reminderComponents();
                    $template = $templates->createTemplate($config, $provisioning->tenant_id, [
                        'name' => $name,
                        'language' => 'es_AR',
                        'category' => TemplateCategory::Utility->value,
                        'components' => $components,
                    ]);
                }
                $provisioning->forceFill([$idColumn => $template->id, $errorColumn => null])->save();
            } catch (\Throwable $exception) {
                $provisioning->forceFill([$errorColumn => mb_substr($exception->getMessage(), 0, 4000)])->save();
                report($exception);
            }
        }

        $provisioning->refresh()->refreshState();
    }

    private function invoiceComponents($config, WhatsAppTemplateService $templates): array
    {
        $pdf = Pdf::loadView('invoices.template-sample')->output();
        $path = tempnam(sys_get_temp_dir(), 'invoice-template-');
        file_put_contents($path, $pdf);
        try {
            $file = new UploadedFile($path, 'ejemplo-cobro.pdf', 'application/pdf', null, true);
            $handle = $templates->uploadTemplateHeaderHandle($config, $file);
        } finally {
            @unlink($path);
        }

        return [
            ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => [$handle]]],
            ['type' => 'BODY', 'text' => 'Hola {{cliente}}, te enviamos el cobro {{numero}} por {{importe}}. Vence el {{vencimiento}}. Encontrás el detalle y las instrucciones de pago en el PDF adjunto.', 'example' => ['body_text_named_params' => [
                ['param_name' => 'cliente', 'example' => 'María'],
                ['param_name' => 'numero', 'example' => 'COB-000001'],
                ['param_name' => 'importe', 'example' => '$ 10.000,00'],
                ['param_name' => 'vencimiento', 'example' => '15/10/2026'],
            ]]],
        ];
    }

    private function reminderComponents(): array
    {
        return [[
            'type' => 'BODY',
            'text' => 'Hola {{cliente}}, te recordamos que el cobro {{numero}} vence el {{vencimiento}} y tiene un saldo pendiente de {{saldo}}. Si ya pagaste, desestimá este mensaje.',
            'example' => ['body_text_named_params' => [
                ['param_name' => 'cliente', 'example' => 'María'],
                ['param_name' => 'numero', 'example' => 'COB-000001'],
                ['param_name' => 'vencimiento', 'example' => '15/10/2026'],
                ['param_name' => 'saldo', 'example' => '$ 10.000,00'],
            ]],
        ]];
    }
}
