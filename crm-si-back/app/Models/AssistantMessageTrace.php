<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trace de Langfuse de una respuesta del asistente interno.
 *
 * Sin BelongsToTenant: se consulta por message_id después de que el
 * controller verificó que el mensaje es del usuario.
 */
class AssistantMessageTrace extends Model
{
    protected $primaryKey = 'message_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['message_id', 'tenant_id', 'trace_id', 'observation_id'];
}
