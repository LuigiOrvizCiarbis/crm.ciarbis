<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtros del encabezado del Panel ya resueltos (null = sin filtro), con cómo
 * se aplican a cada entidad. Así cada métrica filtra igual que las demás.
 */
final class DashboardScope
{
    public function __construct(
        public readonly ?int $canalId = null,
        public readonly ?int $ownerId = null,
        public readonly ?int $pipelineId = null,
        public readonly ?int $branchId = null,
    ) {}

    /** Para desgloses por canal: filtrar por canal dejaría una sola fila. */
    public function withoutCanal(): self
    {
        return new self(null, $this->ownerId, $this->pipelineId, $this->branchId);
    }

    /** Para la IA y las automatizaciones: trabajan sin vendedor asignado. */
    public function withoutOwner(): self
    {
        return new self($this->canalId, null, $this->pipelineId, $this->branchId);
    }

    /** Para la comparativa: cada fila ya es una sucursal. */
    public function withoutBranch(): self
    {
        return new self($this->canalId, $this->ownerId, $this->pipelineId, null);
    }

    public function opportunities(Builder $query): Builder
    {
        return $query
            ->when($this->pipelineId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('pipeline_id'), $this->pipelineId))
            ->when($this->ownerId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('assigned_to'), $this->ownerId))
            ->when($this->branchId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('branch_id'), $this->branchId))
            ->when($this->canalId !== null, fn (Builder $q) => $q->whereHas(
                'conversation',
                fn (Builder $conversation) => $conversation->where('channel_id', $this->canalId),
            ));
    }

    public function conversations(Builder $query): Builder
    {
        return $query
            ->when($this->ownerId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('assigned_to'), $this->ownerId))
            ->when($this->canalId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('channel_id'), $this->canalId))
            ->when($this->branchId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('branch_id'), $this->branchId));
    }

    /** Los contactos no tienen vendedor ni canal propios: sólo los acota la sucursal. */
    public function contacts(Builder $query): Builder
    {
        return $query->when($this->branchId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('branch_id'), $this->branchId));
    }

    /**
     * Las tareas no tienen sucursal: cuentan para la de quien las tiene asignadas,
     * según su membresía al workspace (users.branch_id es la columna heredada).
     */
    public function tasks(Builder $query): Builder
    {
        return $query
            ->when($this->ownerId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('assigned_to'), $this->ownerId))
            ->when($this->branchId !== null, fn (Builder $q) => $q->whereExists(
                fn ($membership) => $membership->selectRaw('1')
                    ->from('tenant_memberships')
                    ->whereColumn('tenant_memberships.user_id', $q->qualifyColumn('assigned_to'))
                    ->whereColumn('tenant_memberships.tenant_id', $q->qualifyColumn('tenant_id'))
                    ->where('tenant_memberships.branch_id', $this->branchId),
            ));
    }

    /** Las derivaciones heredan la sucursal de su conversación. */
    public function handoffs(Builder $query): Builder
    {
        return $query
            ->when($this->ownerId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('assigned_to'), $this->ownerId))
            ->when($this->canalId !== null, fn (Builder $q) => $q->where($q->qualifyColumn('channel_id'), $this->canalId))
            ->when($this->branchId !== null, fn (Builder $q) => $q->whereIn(
                $q->qualifyColumn('conversation_id'),
                Conversation::query()->select('conversations.id')->where('conversations.branch_id', $this->branchId)->toBase(),
            ));
    }

    /**
     * Una ejecución hereda canal y sucursal de lo que la disparó. Con canal sólo
     * cuentan las de conversaciones; los comentarios de Instagram no tienen sucursal.
     */
    public function automationRuns(Builder $query): Builder
    {
        if ($this->canalId === null && $this->branchId === null) {
            return $query;
        }

        return $query->where(function (Builder $q): void {
            $q->where(fn (Builder $subject) => $subject
                ->where($q->qualifyColumn('subject_type'), 'conversation')
                ->whereIn($q->qualifyColumn('subject_id'), $this->conversations(Conversation::query()->select('conversations.id'))->toBase()));

            if ($this->canalId === null) {
                $q->orWhere(fn (Builder $subject) => $subject
                    ->where($q->qualifyColumn('subject_type'), 'contact')
                    ->whereIn($q->qualifyColumn('subject_id'), $this->contacts(Contact::query()->select('contacts.id'))->toBase()));
            }
        });
    }
}
