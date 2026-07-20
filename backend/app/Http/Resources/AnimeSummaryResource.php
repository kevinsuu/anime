<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AnimeSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'season_year' => $this->season_year,
            'season_code' => $this->season_code,
            'air_date' => $this->air_date,
            'air_date_text' => $this->air_date_text,
            'episode_count' => $this->episode_count,
            'tags' => $this->tags ?? [],
            'stream_count' => (int) ($this->streams_count ?? 0),
            'actors' => $this->whenLoaded('cast', fn () => $this->cast
                ->pluck('actor')
                ->filter(fn (?string $actor): bool => $actor !== null && $actor !== '' && $actor !== '？？？')
                ->unique()
                ->values()
                ->all()),
        ];
    }
}
