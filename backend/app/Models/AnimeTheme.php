<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnimeTheme extends Model
{
    public $timestamps = false;

    protected $fillable = ['anime_id', 'type', 'title', 'artist', 'sort_order'];
}
