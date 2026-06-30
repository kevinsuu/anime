<?php

namespace App\Services\AnimeCatalog;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Pure-function HTML parser for acgsecrets.hk bangumi pages.
 *
 * No IO is performed here; callers supply the raw HTML. The output of
 * {@see self::parseAnimeBlock()} is a stable contract consumed by the
 * import service, so the returned key set must not change without
 * updating that consumer.
 */
final class AcgSecretsParser
{
    /**
     * Extract unique, ascending YYYYMM season codes from the index page.
     *
     * @return list<string>
     */
    public function parseSeasonIndex(string $html): array
    {
        preg_match_all('#/bangumi/(\d{6})/#', $html, $matches);

        $codes = array_values(array_unique($matches[1]));
        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * Split a season page into per-anime blocks and parse each one.
     *
     * @return list<array<string, mixed>>
     */
    public function parseSeasonPage(string $html, string $yyyymm): array
    {
        $records = [];
        foreach ($this->splitBlocks($html) as $block) {
            $records[] = $this->parseAnimeBlock($block, $yyyymm);
        }

        return $records;
    }

    /**
     * Parse a single anime block into a structured, tolerant record.
     *
     * Missing fields never throw; they resolve to '' / [] / null.
     *
     * @return array<string, mixed>
     */
    public function parseAnimeBlock(string $blockHtml, string $yyyymm): array
    {
        $year = (int) substr($yyyymm, 0, 4);
        $month = (int) substr($yyyymm, 4, 2);

        $xpath = $this->xpath($blockHtml);

        $titleZh = $this->firstNonEmpty($xpath, './/div[contains(@class,"entity_localized_name")] | .//h3[contains(@class,"entity_localized_name")]');
        $titleJa = $this->firstNonEmpty($xpath, './/div[contains(@class,"entity_original_name")]');

        $summary = $this->firstNonEmpty($xpath, './/div[contains(@class,"anime_story")]');
        if ($summary === '') {
            $summary = $this->firstNonEmpty($xpath, './/div[contains(@class,"anime_summary")]');
        }

        $coverImage = $this->coverImage($xpath);

        $airDateText = $this->firstNonEmpty($xpath, './/div[contains(@class,"onair_times")]');
        $airDate = $this->parseAirDate($airDateText, $year);

        return [
            'season' => $yyyymm,
            'season_year' => $year,
            'season_code' => $this->seasonCode($month),
            'title_zh' => $titleZh,
            'title_ja' => $titleJa,
            'aliases' => $this->aliases($xpath),
            'summary' => $summary,
            'cover_image' => $coverImage,
            'air_date_text' => $airDateText,
            'air_date' => $airDate,
            'tags' => $this->tags($xpath),
            'streams' => $this->streams($xpath),
            'external_ids' => $this->externalIds($xpath),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitBlocks(string $html): array
    {
        // Match each anime block from its opening div up to (but not including)
        // the next anime block, the footer, or the end of the body/document.
        $pattern = '#<div class="clear-both acgs-anime-block.*?(?=<div class="clear-both acgs-anime-block|<div class="clear-both site-footer|</body>|$)#su';

        if (! preg_match_all($pattern, $html, $matches)) {
            return [];
        }

        return $matches[0];
    }

    private function xpath(string $blockHtml): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Prefix with an XML encoding hint so DOMDocument treats the bytes as
        // UTF-8 rather than mangling them into ISO-8859-1.
        $dom->loadHTML('<?xml encoding="UTF-8">' . $blockHtml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    private function seasonCode(int $month): string
    {
        return match ($month) {
            1 => 'winter',
            4 => 'spring',
            7 => 'summer',
            default => 'fall',
        };
    }

    private function firstNonEmpty(DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return '';
        }

        foreach ($nodes as $node) {
            $text = $this->clean($node->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function clean(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim($collapsed ?? $text);
    }

    private function coverImage(DOMXPath $xpath): string
    {
        $imgs = $xpath->query('.//div[contains(@class,"anime_cover_image")]//img');
        if ($imgs === false) {
            return '';
        }

        foreach ($imgs as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }
            foreach (['src', 'data-src', 'acgs-img-data-url'] as $attr) {
                $value = trim($img->getAttribute($attr));
                if (str_starts_with($value, 'http')) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function aliases(DOMXPath $xpath): array
    {
        $aliases = [];

        // Dedicated alternative-name nodes.
        $altNodes = $xpath->query('.//*[contains(@class,"entity_alternative_name")]');
        if ($altNodes !== false) {
            foreach ($altNodes as $node) {
                foreach ($this->splitNames($this->clean($node->textContent)) as $name) {
                    $aliases[] = $name;
                }
            }
        }

        // "其他名稱：..." line carried inside a summary block.
        $summaryNodes = $xpath->query('.//div[contains(@class,"anime_summary")]//i | .//div[contains(@class,"anime_summary")]');
        if ($summaryNodes !== false) {
            foreach ($summaryNodes as $node) {
                $text = $this->clean($node->textContent);
                if (! str_contains($text, '其他名稱')) {
                    continue;
                }
                $text = preg_replace('/^.*?其他名稱[：:]\s*/u', '', $text) ?? $text;
                foreach ($this->splitNames($text) as $name) {
                    $aliases[] = $name;
                }
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @return list<string>
     */
    private function splitNames(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/[、,，]/u', $text) ?: [];
        $names = [];
        foreach ($parts as $part) {
            $part = $this->clean($part);
            if ($part !== '') {
                $names[] = $part;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function tags(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('.//div[contains(@class,"anime_tag")]//tags');
        if ($nodes === false) {
            return [];
        }

        $tags = [];
        foreach ($nodes as $node) {
            $text = $this->clean($node->textContent);
            if ($text !== '') {
                $tags[] = $text;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return list<array{region: string, platform: string, url: string|null}>
     */
    private function streams(DOMXPath $xpath): array
    {
        $areas = $xpath->query('.//div[contains(@class,"stream-area")]');
        if ($areas === false) {
            return [];
        }

        $streams = [];
        foreach ($areas as $area) {
            if (! $area instanceof DOMElement) {
                continue;
            }
            $region = $this->clean($area->textContent);

            // The site links live in the sibling stream-site-groups container.
            $group = $xpath->query('following-sibling::div[contains(@class,"stream-site-groups")][1]', $area);
            $container = ($group !== false && $group->length > 0) ? $group->item(0) : $area->parentNode;

            $found = false;
            $siteNodes = $container !== null
                ? $xpath->query('.//a[contains(@class,"stream-site")]', $container)
                : false;

            if ($siteNodes !== false) {
                foreach ($siteNodes as $site) {
                    if (! $site instanceof DOMElement) {
                        continue;
                    }
                    $platform = $this->clean($site->textContent);
                    if ($platform === '') {
                        continue;
                    }
                    $href = trim($site->getAttribute('href'));
                    $streams[] = [
                        'region' => $region,
                        'platform' => $platform,
                        'url' => $href !== '' ? $href : null,
                    ];
                    $found = true;
                }
            }

            // Record the region even when no concrete site link was found.
            if (! $found && $region !== '') {
                $streams[] = [
                    'region' => $region,
                    'platform' => '',
                    'url' => null,
                ];
            }
        }

        return $streams;
    }

    /**
     * @return array<string, string>
     */
    private function externalIds(DOMXPath $xpath): array
    {
        $links = $xpath->query('.//div[contains(@class,"anime_links")]//a');
        if ($links === false) {
            return [];
        }

        $ids = [];
        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $href = $link->getAttribute('href');
            if ($href === '') {
                continue;
            }

            if (! isset($ids['mal']) && preg_match('#myanimelist\.net/anime/(\d+)#', $href, $m)) {
                $ids['mal'] = $m[1];
            }
            if (! isset($ids['bangumi']) && preg_match('#(?:bgm|bangumi)\.tv/subject/(\d+)#', $href, $m)) {
                $ids['bangumi'] = $m[1];
            }
        }

        return $ids;
    }

    private function parseAirDate(string $airDateText, int $year): ?string
    {
        if ($airDateText === '') {
            return null;
        }

        if (! preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $airDateText, $m)) {
            return null;
        }

        $month = (int) $m[1];
        $day = (int) $m[2];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
