<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AnimeStream extends Model
{
    protected $fillable = ['anime_id', 'region', 'platform', 'url'];

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
