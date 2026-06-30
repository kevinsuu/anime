<?php

namespace App\Services\Shared;

use App\Models\User;

final class SlugGenerator
{
    public function uniqueUserSlug(): string
    {
        do {
            $slug = bin2hex(random_bytes(4));
        } while (User::query()->where('public_slug', $slug)->exists());

        return $slug;
    }
}
