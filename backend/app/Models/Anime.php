<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

final class Anime extends Model
{
    protected $table = 'anime';

    protected $fillable = [
        'name',
        'description',
        'image_url',
        'cover_image_path',
        'source',
        'created_by_user_id',
        'season_year',
        'season_code',
        'air_date',
        'air_date_text',
        'episode_count',
        'status',
        'tags',
        'import_hash',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }

    /**
     * cover_image_path 有值時優先回傳縮圖 URL（全站共用的靜態檔案，
     * 由 import 時的 ThumbnailService 產生），否則 fallback 回原始
     * acgsecrets 圖片網址 —— 縮圖產生失敗或尚未 backfill 的舊資料
     * 都會走這個 fallback，圖片顯示絕不會比現況更差。
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => $attributes['cover_image_path'] !== null
                ? Storage::disk('public')->url($attributes['cover_image_path'])
                : $value,
        );
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
