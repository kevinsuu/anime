<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Anime extends Model
{
    protected $table = 'anime';

    protected $fillable = [
        'name',
        'description',
        'image_url',
        'source',
        'created_by_user_id',
        'season_year',
        'season_code',
        'air_date',
        'episode_count',
        'status',
    ];

    public function titles(): HasMany
    {
        return $this->hasMany(AnimeTitle::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(AnimeAlias::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(AnimeExternalId::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(AnimeStream::class);
    }
}
