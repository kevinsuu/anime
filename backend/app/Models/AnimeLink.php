<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnimeLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['anime_id', 'category', 'label', 'url'];
}
