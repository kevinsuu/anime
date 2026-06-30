<?php

namespace App\Services\AnimeCatalog;

use InvalidArgumentException;

final class BangumiAnimeNormalizer
{
    public function __construct(private readonly ChineseTextConverter $converter)
    {
    }

    public function normalize(array $item): array
    {
        $externalId = trim((string) ($item['id'] ?? ''));
        if ($externalId === '') {
            throw new InvalidArgumentException('Bangumi subject id is required');
        }

        $japaneseTitle = trim((string) ($item['name'] ?? ''));
        $chineseTitle = trim((string) ($item['name_cn'] ?? ''));
        $traditionalTitle = $chineseTitle !== '' ? $this->converter->toTraditional($chineseTitle) : '';
        $primaryTitle = $traditionalTitle !== '' ? $traditionalTitle : $japaneseTitle;

        if ($primaryTitle === '') {
            throw new InvalidArgumentException('Bangumi subject title is required');
        }

        $airDate = $this->normalizeDate($item['air_date'] ?? $item['date'] ?? null);
        $season = SeasonResolver::fromAirDate($airDate);

        $titles = [];
        if ($traditionalTitle !== '') {
            $titles['zh-Hant'] = $traditionalTitle;
        }
        if ($chineseTitle !== '' && $chineseTitle !== $traditionalTitle) {
            $titles['zh-Hans'] = $chineseTitle;
        }
        if ($japaneseTitle !== '') {
            $titles['ja-JP'] = $japaneseTitle;
        }

        return [
            'provider' => 'bangumi',
            'externalId' => $externalId,
            'providerUrl' => $this->normalizeUrl($item['url'] ?? "https://bgm.tv/subject/{$externalId}"),
            'primaryTitle' => $primaryTitle,
            'description' => trim((string) ($item['summary'] ?? $item['short_summary'] ?? '')),
            'imageUrl' => $this->pickImageUrl($item['images'] ?? []),
            'seasonYear' => $season['year'],
            'seasonCode' => $season['code'],
            'airDate' => $airDate,
            'episodeCount' => $this->normalizeInt($item['eps'] ?? $item['eps_count'] ?? $item['total_episodes'] ?? null),
            'status' => null,
            'titles' => $titles,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);
        if (str_starts_with($url, 'http://bgm.tv/')) {
            return 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }

    private function pickImageUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }

        foreach (['large', 'common', 'medium', 'small', 'grid'] as $key) {
            if (! empty($images[$key]) && is_string($images[$key])) {
                return $this->normalizeUrl($images[$key]);
            }
        }

        return null;
    }
}
