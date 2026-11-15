<?php

namespace App\Services\Flows;

use App\Enums\TemplateStatus;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppTemplate;
use App\Services\Templates\TemplateParameterRenderer;
use Illuminate\Support\Collection;

/**
 * Plantillas que abren un formulario cuando la ventana de 24 h está cerrada:
 * las de su cuenta cuyo botón FLOW apunta a él (por `flow_id`, o por nombre
 * si Meta no lo devolvió; igual que `WhatsAppFlowSessionService::attachToTemplate`).
 *
 * La predeterminada es la elegida si está lista; si no, la primera lista.
 * "Lista" = aprobada y con sus variables completas.
 */
class FlowOpeningTemplates
{
    public const ISSUE_NOT_APPROVED = 'not_approved';

    public const ISSUE_PARAMETERS = 'parameters';

    private const GONE = [TemplateStatus::Deleted, TemplateStatus::PendingDeletion];

    public function __construct(private readonly TemplateParameterRenderer $parameters) {}

    /**
     * Plantillas de la cuenta del formulario. Una lista de formularios de la
     * misma cuenta la carga una vez y la pasa a `linked`/`summary`.
     *
     * @return Collection<int, WhatsAppTemplate>
     */
    public function accountTemplates(WhatsAppFlow $flow): Collection
    {
        return WhatsAppTemplate::withoutGlobalScopes()
            ->where('tenant_id', $flow->tenant_id)
            ->where('whatsapp_config_id', $flow->whatsapp_config_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, WhatsAppTemplate>|null  $accountTemplates
     * @return Collection<int, WhatsAppTemplate>
     */
    public function linked(WhatsAppFlow $flow, ?Collection $accountTemplates = null): Collection
    {
        return ($accountTemplates ?? $this->accountTemplates($flow))
            ->filter(fn (WhatsAppTemplate $template) => ! in_array($template->status, self::GONE, true) && self::opens($template, $flow))
            ->values();
    }

    public static function opens(WhatsAppTemplate $template, WhatsAppFlow $flow): bool
    {
        $button = $template->flowButton();

        if ($button === null) {
            return false;
        }

        return $button['flow_id'] !== null ? $button['flow_id'] === $flow->meta_flow_id : $button['flow_name'] === $flow->name;
    }

    /** Null si se puede enviar; si no, el motivo. */
    public function issue(WhatsAppFlow $flow, WhatsAppTemplate $template): ?string
    {
        if (! $template->isApproved()) {
            return self::ISSUE_NOT_APPROVED;
        }

        return $this->parameters->validate($template, $flow->openingParameters($template->id), $flow->tenant_id) === []
            ? null
            : self::ISSUE_PARAMETERS;
    }

    /** @param  Collection<int, WhatsAppTemplate>|null  $linked */
    public function effective(WhatsAppFlow $flow, ?Collection $linked = null): ?WhatsAppTemplate
    {
        $linked ??= $this->linked($flow);

        return $this->pick($flow, $linked->filter(fn (WhatsAppTemplate $template) => $this->issue($flow, $template) === null));
    }

    /**
     * Resumen para el recurso: cada plantilla vinculada con su estado y la
     * predeterminada efectiva.
     *
     * @param  Collection<int, WhatsAppTemplate>|null  $accountTemplates
     * @return array{templates: list<array{id: int, name: string, language: string, status: string, ready: bool, issue: ?string}>, effective_template_id: ?int}
     */
    public function summary(WhatsAppFlow $flow, ?Collection $accountTemplates = null): array
    {
        $linked = $this->linked($flow, $accountTemplates);
        $issues = $linked->mapWithKeys(fn (WhatsAppTemplate $template) => [$template->id => $this->issue($flow, $template)]);

        $templates = $linked->map(fn (WhatsAppTemplate $template) => [
            'id' => $template->id,
            'name' => $template->name,
            'language' => $template->language,
            'status' => $template->status->value,
            'ready' => $issues[$template->id] === null,
            'issue' => $issues[$template->id],
        ])->all();

        return [
            'templates' => $templates,
            'effective_template_id' => $this->pick($flow, $linked->filter(fn (WhatsAppTemplate $template) => $issues[$template->id] === null))?->id,
        ];
    }

    /**
     * La elegida si está lista; si no, la primera lista.
     *
     * @param  Collection<int, WhatsAppTemplate>  $ready
     */
    private function pick(WhatsAppFlow $flow, Collection $ready): ?WhatsAppTemplate
    {
        return $ready->firstWhere('id', $flow->openingConfig()['default_template_id']) ?? $ready->first();
    }
}
