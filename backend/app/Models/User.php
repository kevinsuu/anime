<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class User extends Model
{
    protected $fillable = [
        'google_sub',
        'email',
        'display_name',
        'avatar_url',
        'public_slug',
    ];

    public function animeListItems(): HasMany
    {
        return $this->hasMany(UserAnimeListItem::class);
    }
}
