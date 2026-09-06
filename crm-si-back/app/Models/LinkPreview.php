<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Caché de preview Open Graph de un link, deduplicada por url_hash entre
 * tenants (es contenido público, no dato de ningún tenant en particular).
 * Sin BelongsToTenant a propósito.
 */
class LinkPreview extends Model
{
    protected $fillable = [
        'url_hash',
        'url',
        'title',
        'description',
        'site_name',
        'image_path',
        'status',
        'fetched_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $appends = ['image_url'];

    /**
     * Storage::disk('public')->url() puede devolver una ruta relativa
     * ("/storage/..."), que el front resolvería contra su propio origen de
     * Next.js en vez del de Laravel cuando están en dominios separados —
     * mismo problema que Message::getMediaFullUrlAttribute() ya resuelve
     * prefijando con app.url cuando la URL no viene absoluta.
     */
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        $url = Storage::disk('public')->url($this->image_path);

        return str_starts_with($url, 'http') ? $url : rtrim(config('app.url'), '/').$url;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isFresh(int $maxAgeDays): bool
    {
        return $this->status === 'ok'
            && $this->fetched_at !== null
            && $this->fetched_at->greaterThan(now()->subDays($maxAgeDays));
    }
}
