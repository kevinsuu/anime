<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AnimeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'source' => $this->source,
            'season_year' => $this->season_year,
            'season_code' => $this->season_code,
            'air_date' => $this->air_date,
            'air_date_text' => $this->air_date_text,
            'episode_count' => $this->episode_count,
            'status' => $this->status,
            'tags' => $this->tags ?? [],
            'aliases' => $this->whenLoaded('aliases', fn () => $this->aliases->pluck('alias')->all()),
            'streams' => $this->whenLoaded('streams', fn () => $this->streams->map(fn ($stream) => [
                'region' => $stream->region,
                'platform' => $stream->platform,
                'url' => $stream->url,
            ])->all()),
            'titles' => $this->whenLoaded('titles', fn () => $this->titles->map(fn ($title) => [
                'locale' => $title->locale,
                'title' => $title->title,
                'is_primary' => (bool) $title->is_primary,
            ])->all()),
            'external_ids' => $this->whenLoaded('externalIds', fn () => $this->externalIds->map(fn ($externalId) => [
                'provider' => $externalId->provider,
                'external_id' => $externalId->external_id,
                'url' => $externalId->url,
            ])->all()),
            'themes' => $this->whenLoaded('themes', fn () => $this->themes->map(fn ($theme) => [
                'type' => $theme->type,
                'title' => $theme->title,
                'artist' => $theme->artist,
            ])->all()),
            'trailers' => $this->whenLoaded('trailers', fn () => $this->trailers->map(fn ($trailer) => [
                'url' => $trailer->url,
                'thumbnail' => $trailer->thumbnail,
            ])->all()),
            'cast' => $this->whenLoaded('cast', fn () => $this->cast->map(fn ($castMember) => [
                'character' => $castMember->character,
                'actor' => $castMember->actor,
            ])->all()),
            'staff' => $this->whenLoaded('staffMembers', fn () => $this->staffMembers->map(fn ($staffMember) => [
                'role' => $staffMember->role,
                'name' => $staffMember->name,
            ])->all()),
            'links' => $this->whenLoaded('links', fn () => $this->links->map(fn ($link) => [
                'category' => $link->category,
                'label' => $link->label,
                'url' => $link->url,
            ])->all()),
        ];
    }
}
