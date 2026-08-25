<?php

namespace App\Models;

use App\Enums\ExtractionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una corrida de extracción de datos sobre un documento PDF de un contacto.
 *
 * El estado se persiste (en vez de vivir sólo en la cola) porque el usuario
 * consulta el progreso por polling y necesita ver el error si algo falló. Las
 * transiciones son compare-and-set: dos jobs concurrentes no pueden llamar al
 * proveedor sobre la misma fila, y un failed() tardío no pisa un completed.
 *
 * @property int $tenant_id
 * @property int $contact_id
 * @property int $media_asset_id
 * @property ExtractionStatus $status
 * @property array<string, mixed>|null $result
 * @property array<string, string>|null $fields_snapshot
 * @property list<int>|null $pages_without_text
 */
class DocumentExtraction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'media_asset_id',
        'requested_by',
        'status',
        'result',
        'fields_snapshot',
        'document_text',
        'text_coverage',
        'pages_without_text',
        'processing_started_at',
        'contact_lock_version',
        'error_code',
        'error_message',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtractionStatus::class,
            'result' => 'array',
            'fields_snapshot' => 'array',
            'pages_without_text' => 'array',
            'processing_started_at' => 'datetime',
            'contact_lock_version' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Toma la extracción para procesarla. Devuelve false si otro worker ya la
     * tomó: sin este chequeo, un job redelivered por retry_after llamaría al
     * proveedor una segunda vez y se pagaría dos veces el mismo documento.
     *
     * Sella processing_started_at para que el watchdog pueda recuperar la fila
     * si este worker muere antes de terminar.
     */
    public function claim(): bool
    {
        $claimed = static::withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->where('status', ExtractionStatus::Queued->value)
            ->update([
                'status' => ExtractionStatus::Processing->value,
                'processing_started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $this->refresh();

        return true;
    }

    /**
     * Cierra la extracción con éxito, sólo si sigue en processing.
     *
     * @param  array<string, mixed>  $result
     */
    public function markCompleted(array $result, ?int $inputTokens, ?int $outputTokens): bool
    {
        return $this->transitionFromProcessing([
            'status' => ExtractionStatus::Completed->value,
            'result' => json_encode($result),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);
    }

    /**
     * Cierra la extracción con error, sólo si sigue en processing o queued.
     *
     * Acepta queued porque una falla puede ocurrir antes del claim (por ejemplo
     * si el asset ya no existe), y el estado terminal se chequea igual para que
     * un failed() tardío del worker no degrade un completed ya escrito.
     */
    public function markFailed(string $errorCode, ?string $errorMessage = null): bool
    {
        $updated = static::withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->whereIn('status', [ExtractionStatus::Queued->value, ExtractionStatus::Processing->value])
            ->update([
                'status' => ExtractionStatus::Failed->value,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'updated_at' => now(),
            ]);

        return $updated > 0;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transitionFromProcessing(array $attributes): bool
    {
        $updated = static::withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->where('status', ExtractionStatus::Processing->value)
            ->update($attributes + ['updated_at' => now()]);

        return $updated > 0;
    }

    /**
     * Busca una extracción dentro de un tenant, sin depender de TenantScope: en
     * un job no hay usuario autenticado y el scope global es un no-op.
     */
    public static function findForTenant(int $tenantId, int $id): ?self
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($id);
    }
}
