<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tokens del asistente interno por workspace y día.
 *
 * Sin BelongsToTenant a propósito: se escribe al terminar el stream de la
 * respuesta, siempre con tenant_id explícito. `date` queda como string Y-m-d
 * (sin cast) para que createOrFirst compare igual en sqlite y Postgres.
 *
 * @property int $tenant_id
 * @property string $date
 * @property int $input_tokens
 * @property int $output_tokens
 */
class AssistantUsage extends Model
{
    protected $table = 'assistant_usage';

    protected $fillable = [
        'tenant_id',
        'date',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }
}
