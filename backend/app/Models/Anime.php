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
        'air_date_text',
        'episode_count',
        'status',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

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

    public function themes(): HasMany
    {
        return $this->hasMany(AnimeTheme::class)->orderBy('sort_order');
    }

    public function trailers(): HasMany
    {
        return $this->hasMany(AnimeTrailer::class)->orderBy('sort_order');
    }

    public function cast(): HasMany
    {
        return $this->hasMany(AnimeCast::class)->orderBy('sort_order');
    }

    public function staffMembers(): HasMany
    {
        return $this->hasMany(AnimeStaff::class)->orderBy('sort_order');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AnimeLink::class);
    }
}
