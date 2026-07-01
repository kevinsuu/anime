<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnimeTrailer extends Model
{
    public $timestamps = false;

    protected $fillable = ['anime_id', 'url', 'thumbnail', 'sort_order'];
}
