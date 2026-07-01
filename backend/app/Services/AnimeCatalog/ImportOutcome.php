<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;

final class ImportOutcome
{
    public function __construct(
        public readonly Anime $anime,
        public readonly bool $wasUnchanged,
    ) {
    }
}
