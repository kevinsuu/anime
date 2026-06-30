<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AnimeExternalId extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'anime_id',
        'provider',
        'external_id',
        'url',
        'last_synced_at',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
