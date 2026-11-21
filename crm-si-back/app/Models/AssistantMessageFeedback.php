<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Valoración de un usuario sobre una respuesta del asistente interno.
 *
 * @property string $message_id
 * @property int $rating 1 (útil) o -1 (no útil)
 */
class AssistantMessageFeedback extends Model
{
    use BelongsToTenant;

    public const UP = 1;

    public const DOWN = -1;

    protected $table = 'assistant_message_feedback';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'message_id',
        'rating',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }
}
