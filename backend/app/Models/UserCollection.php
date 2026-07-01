<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class UserCollection extends Model
{
    protected $fillable = ['user_id', 'name', 'public_slug', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listItems(): BelongsToMany
    {
        return $this->belongsToMany(
            UserAnimeListItem::class,
            'collection_items',
            'collection_id',
            'list_item_id'
        )->withTimestamps();
    }
}
