<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Valoración de un operador sobre una respuesta de la IA en el chat.
 *
 * @property int $message_id
 * @property int $rating 1 (buena) o -1 (mala)
 */
class AiMessageFeedback extends Model
{
    use BelongsToTenant;

    public const UP = 1;

    public const DOWN = -1;

    protected $table = 'ai_message_feedback';

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
