<?php

namespace App\Http\Requests\Api;

use App\Models\Pipeline;
use App\Support\DashboardScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardMetricsRequest extends FormRequest
{
    private const ALL = ['todos', 'all'];

    private ?Pipeline $resolvedPipeline = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $pipeline = $this->input('pipeline');
        $sucursal = $this->input('sucursal');
        $tenantId = $this->user()->tenant_id;

        return [
            'periodo' => ['nullable', 'string', Rule::in(['hoy', 'esta-semana', 'este-mes', 'este-trimestre', 'este-ano'])],
            'canal' => ['nullable'],
            'owner' => ['nullable'],
            'pipeline' => is_numeric($pipeline)
                ? ['integer', Rule::exists('pipelines', 'id')->where('tenant_id', $tenantId)]
                : ['nullable', 'string', Rule::in(['default', ...self::ALL])],
            'sucursal' => is_numeric($sucursal)
                ? ['integer', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)]
                : ['nullable', 'string', Rule::in(self::ALL)],
        ];
    }

    /**
     * Filtros listos para consultar. Sin `analytics.view_team` cada uno ve lo suyo,
     * pida el vendedor que pida. Quien está atado a una sucursal ya queda acotado
     * por el BranchScope: pedir otra devuelve vacío, no datos ajenos.
     */
    public function dashboardScope(): DashboardScope
    {
        $user = $this->user();
        $sucursal = $this->validated('sucursal');

        return new DashboardScope(
            canalId: $this->canalId(),
            ownerId: $user->can('analytics.view_team') ? $this->ownerId() : $user->id,
            pipelineId: $this->pipelineSelection(),
            branchId: is_numeric($sucursal) ? (int) $sucursal : null,
        );
    }

    public function periodo(): string
    {
        return (string) ($this->validated('periodo') ?? 'este-mes');
    }

    public function canalId(): ?int
    {
        $canal = $this->validated('canal');

        if ($canal === null || $canal === '' || $canal === 'todos' || $canal === 'all') {
            return null;
        }

        return is_numeric($canal) ? (int) $canal : null;
    }

    public function ownerId(): ?int
    {
        $owner = $this->validated('owner');

        if ($owner === null || $owner === '' || $owner === 'todos' || $owner === 'all' || $owner === 'dueno') {
            return null;
        }

        return is_numeric($owner) ? (int) $owner : null;
    }

    /**
     * Pipeline elegido, ya resuelto (`default` → predeterminado del tenant).
     * Null significa todos los pipelines.
     */
    public function selectedPipeline(): ?Pipeline
    {
        $pipeline = $this->validated('pipeline');

        if (in_array($pipeline, self::ALL, true)) {
            return null;
        }

        $tenantId = $this->user()->tenant_id;

        return $this->resolvedPipeline ??= is_numeric($pipeline)
            ? Pipeline::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail((int) $pipeline)
            : Pipeline::defaultFor($tenantId);
    }

    public function pipelineSelection(): ?int
    {
        return $this->selectedPipeline()?->id;
    }
}
