<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnimeStaff extends Model
{
    public $timestamps = false;

    protected $table = 'anime_staff';

    protected $fillable = ['anime_id', 'role', 'name', 'sort_order'];
}
