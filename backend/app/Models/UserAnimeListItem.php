<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserAnimeListItem extends Model
{
    protected $fillable = [
        'user_id',
        'anime_id',
        'watched',
        'rating',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'watched' => 'boolean',
            'rating' => 'integer',
        ];
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
