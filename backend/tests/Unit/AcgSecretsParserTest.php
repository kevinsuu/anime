<?php

namespace Tests\Unit;

use App\Services\AnimeCatalog\AcgSecretsParser;
use PHPUnit\Framework\TestCase;

final class AcgSecretsParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../fixtures/' . $name;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Missing fixture: {$name}");

        return $contents;
    }

    public function test_parse_season_index_extracts_deduped_sorted_codes(): void
    {
        $parser = new AcgSecretsParser();
        $codes = $parser->parseSeasonIndex($this->fixture('acgsecrets_index.html'));

        $this->assertContains('202604', $codes);
        $this->assertContains('201601', $codes);

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }

        // Deduped.
        $this->assertSame(array_values(array_unique($codes)), $codes);

        // Sorted ascending.
        $sorted = $codes;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $codes);
    }

    public function test_parse_anime_block_extracts_core_fields(): void
    {
        $parser = new AcgSecretsParser();
        $record = $parser->parseAnimeBlock($this->fixture('acgsecrets_block.html'), '202604');

        $this->assertSame('202604', $record['season']);
        $this->assertSame(2026, $record['season_year']);
        $this->assertSame('spring', $record['season_code']);

        // Traditional Chinese primary name.
        $this->assertNotSame('', $record['title_zh']);
        $this->assertMatchesRegularExpression('/\p{Han}/u', $record['title_zh']);

        // Japanese original name.
        $this->assertNotSame('', $record['title_ja']);

        $this->assertNotSame('', $record['summary']);

        $this->assertIsString($record['cover_image']);
        $this->assertStringStartsWith('http', $record['cover_image']);

        $this->assertIsArray($record['aliases']);
        $this->assertIsArray($record['tags']);

        $this->assertNotSame('', $record['air_date_text']);
        // "4月4日起" with season year 2026 -> 2026-04-04.
        $this->assertSame('2026-04-04', $record['air_date']);

        // The onair area repeats time_today and a hidden time_tomorrow div with identical
        // text; air_date_text must capture it once, not doubled.
        $this->assertSame(
            1,
            substr_count($record['air_date_text'], '4月4日起'),
            'air_date_text should not duplicate the onair text'
        );
    }

    public function test_parse_anime_block_extracts_streams(): void
    {
        $parser = new AcgSecretsParser();
        $record = $parser->parseAnimeBlock($this->fixture('acgsecrets_block.html'), '202604');

        $this->assertIsArray($record['streams']);
        $this->assertNotEmpty($record['streams']);

        $first = $record['streams'][0];
        $this->assertArrayHasKey('region', $first);
        $this->assertArrayHasKey('platform', $first);
        $this->assertArrayHasKey('url', $first);
        $this->assertNotSame('', $first['region']);
        $this->assertNotSame('', $first['platform']);

        $regions = array_column($record['streams'], 'region');
        $this->assertContains('香港', $regions);
        $this->assertContains('台灣', $regions);
    }

    public function test_parse_anime_block_extracts_external_ids(): void
    {
        $parser = new AcgSecretsParser();
        $record = $parser->parseAnimeBlock($this->fixture('acgsecrets_block.html'), '202604');

        $this->assertIsArray($record['external_ids']);
        $this->assertNotEmpty($record['external_ids']);

        foreach (array_keys($record['external_ids']) as $key) {
            $this->assertContains($key, ['mal', 'bangumi']);
        }

        $this->assertSame('62001', $record['external_ids']['mal'] ?? null);
        $this->assertSame('568572', $record['external_ids']['bangumi'] ?? null);
    }

    public function test_parse_anime_block_handles_missing_fields_with_safe_defaults(): void
    {
        $parser = new AcgSecretsParser();
        $record = $parser->parseAnimeBlock('<div class="acgs-anime-block"></div>', '202010');

        $this->assertSame('202010', $record['season']);
        $this->assertSame(2020, $record['season_year']);
        $this->assertSame('fall', $record['season_code']);

        $this->assertSame('', $record['title_zh']);
        $this->assertSame('', $record['title_ja']);
        $this->assertSame('', $record['summary']);
        $this->assertSame('', $record['cover_image']);
        $this->assertSame('', $record['air_date_text']);
        $this->assertNull($record['air_date']);

        $this->assertSame([], $record['aliases']);
        $this->assertSame([], $record['tags']);
        $this->assertSame([], $record['streams']);
        $this->assertSame([], $record['external_ids']);
        $this->assertNull($record['episode_count']);
    }

    public function test_parse_anime_block_leaves_episode_count_null_when_not_published(): void
    {
        $parser = new AcgSecretsParser();
        $record = $parser->parseAnimeBlock($this->fixture('acgsecrets_block.html'), '202604');

        // The fixture's episode_data tag is "2季度" with no "(N集)" suffix, and it
        // has no air-time remark either, so there is nothing to extract.
        $this->assertNull($record['episode_count']);
    }

    public function test_parse_anime_block_extracts_episode_count_from_episode_data_tag(): void
    {
        $parser = new AcgSecretsParser();
        $html = '<div class="acgs-anime-block"><div class="anime_tag">'
            . '<tags class="episode_data sub_tags">2季度(19集)</tags>'
            . '</div></div>';

        $record = $parser->parseAnimeBlock($html, '202604');

        $this->assertSame(19, $record['episode_count']);
    }

    public function test_parse_anime_block_extracts_episode_count_from_air_time_remark(): void
    {
        $parser = new AcgSecretsParser();
        $html = '<div class="acgs-anime-block"><div class="time_today main_time">'
            . '4月8日起／每週三／22時0分<span class="remark">(喪失篇全11話)</span>'
            . '</div></div>';

        $record = $parser->parseAnimeBlock($html, '202604');

        $this->assertSame(11, $record['episode_count']);
    }

    public function test_parse_anime_block_has_exactly_the_contract_keys(): void
    {
        $parser = new AcgSecretsParser();
        $record = $parser->parseAnimeBlock($this->fixture('acgsecrets_block.html'), '202604');

        $expected = [
            'season', 'season_year', 'season_code',
            'title_zh', 'title_ja', 'aliases', 'summary',
            'cover_image', 'air_date_text', 'air_date', 'episode_count',
            'tags', 'streams', 'external_ids',
            'cast', 'staff', 'themes', 'trailers', 'links',
        ];
        sort($expected);
        $keys = array_keys($record);
        sort($keys);
        $this->assertSame($expected, $keys);
    }

    public function test_parse_season_page_returns_a_record_per_block(): void
    {
        $parser = new AcgSecretsParser();
        $block = $this->fixture('acgsecrets_block.html');
        // Two blocks concatenated.
        $page = '<body>' . $block . $block . '</body>';

        $records = $parser->parseSeasonPage($page, '202604');

        $this->assertCount(2, $records);
        $this->assertNotSame('', $records[0]['title_zh']);
        $this->assertNotSame('', $records[1]['title_zh']);
    }
}
