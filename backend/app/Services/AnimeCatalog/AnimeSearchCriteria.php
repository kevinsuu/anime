<?php

namespace App\Services\AnimeCatalog;

final readonly class AnimeSearchCriteria
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $query,
        public ?int $year,
        public string $season,
        public array $tags,
    ) {}

    public function isRecentMode(): bool
    {
        return $this->query === ''
            && $this->year === null
            && $this->season === ''
            && $this->tags === [];
    }

    /** @return array{q: string, year: int|null, season: string, tags: list<string>} */
    public function toArray(): array
    {
        return [
            'q' => $this->query,
            'year' => $this->year,
            'season' => $this->season,
            'tags' => $this->tags,
        ];
    }
}
