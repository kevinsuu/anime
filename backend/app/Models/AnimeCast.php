<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnimeCast extends Model
{
    public $timestamps = false;

    protected $table = 'anime_cast';

    protected $fillable = ['anime_id', 'character', 'actor', 'sort_order'];
}
