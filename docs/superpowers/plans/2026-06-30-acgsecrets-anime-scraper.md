# ACGSecrets 新番爬蟲與每週同步 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 以 acgsecrets.hk 為來源,爬取歷年新番產生版控 JSON 檔,重建資料庫,每週自動同步,並串接既有前端顯示繁中名稱/大綱/圖片/串流平台。

**Architecture:** 三層解耦 — 爬蟲(`AcgSecretsClient` HTTP + `AcgSecretsParser` 純函式)產生 JSON;JSON 為資料來源真相並進 git;`AnimeImportService` 只讀 JSON 寫入 DB;兩個 artisan command(scrape / import)各司其職,排程串接。前端沿用現有 `GET /anime` 合約並補新欄位。

**Tech Stack:** PHP 8.3 / Laravel 13、PHPUnit(in-memory SQLite)、DOMDocument/DOMXPath、Nuxt 4 / Vue 3 / TypeScript、Vitest。

設計來源:`docs/superpowers/specs/2026-06-30-acgsecrets-anime-scraper-design.md`

---

## 檔案結構

**後端 — 新增**
- `backend/app/Services/AnimeCatalog/AcgSecretsParser.php` — 純函式 HTML→陣列
- `backend/app/Services/AnimeCatalog/AcgSecretsClient.php` — HTTP 取得(降速/重試)
- `backend/app/Console/Commands/ScrapeAcgSecrets.php` — `anime:scrape-acgsecrets`
- `backend/app/Console/Commands/ImportAcgSecrets.php` — `anime:import-acgsecrets`
- `backend/app/Models/AnimeStream.php`
- `backend/database/migrations/2026_06_30_000000_create_anime_streams_table.php`
- `backend/database/seed/acgsecrets/` — JSON 檔輸出目錄(版控)
- `backend/tests/Unit/AcgSecretsParserTest.php`
- `backend/tests/Unit/AnimeImportServiceTest.php`
- `backend/tests/fixtures/acgsecrets_block.html`、`acgsecrets_index.html`

**後端 — 修改**
- `backend/app/Services/AnimeCatalog/AnimeImportService.php` — 改吃 AnimeRecord
- `backend/app/Http/Controllers/Api/AnimeController.php` — index 擴充、移除 syncSeasonal
- `backend/app/Models/Anime.php` — 加 `streams()` 關聯
- `backend/config/services.php` — bangumi→acgsecrets
- `backend/routes/api.php` — 移除 sync-seasonal 路由
- `backend/routes/console.php` — 註冊排程

**後端 — 刪除**
- `backend/app/Services/AnimeCatalog/BangumiClient.php`
- `backend/app/Services/AnimeCatalog/BangumiAnimeNormalizer.php`
- `backend/app/Services/AnimeCatalog/ChineseTextConverter.php`
- `backend/app/Console/Commands/SyncSeasonalAnime.php`
- `backend/tests/Unit/BangumiAnimeNormalizerTest.php`

**前端 — 修改**
- `frontend/app/composables/useApi.ts` — 移除 syncSeasonalAnime
- `frontend/app/utils/normalize.ts` — Anime 加 streams/aliases/titleJa
- `frontend/app/pages/seasonal.vue` — 移除同步按鈕、顯示串流
- `frontend/app/pages/catalog.vue` — 顯示新欄位
- `frontend/test/normalize.spec.ts`(或既有測試檔)補測

---

## Task 1: 移除舊 Bangumi 程式碼

**Files:**
- Delete: `backend/app/Services/AnimeCatalog/BangumiClient.php`
- Delete: `backend/app/Services/AnimeCatalog/BangumiAnimeNormalizer.php`
- Delete: `backend/app/Services/AnimeCatalog/ChineseTextConverter.php`
- Delete: `backend/app/Console/Commands/SyncSeasonalAnime.php`
- Delete: `backend/tests/Unit/BangumiAnimeNormalizerTest.php`

- [ ] **Step 1: 確認哪些檔案引用了即將刪除的類別**

Run: `cd backend && grep -rln "BangumiClient\|BangumiAnimeNormalizer\|ChineseTextConverter\|SyncSeasonalAnime" app tests routes`
Expected: 列出 `AnimeImportService.php`(Task 6 重構)、`SyncSeasonalAnime.php`、`AnimeController.php`(Task 9 處理)。記下這些檔案,後續任務會清掉引用。

- [ ] **Step 2: 刪除檔案**

```bash
cd backend
rm app/Services/AnimeCatalog/BangumiClient.php
rm app/Services/AnimeCatalog/BangumiAnimeNormalizer.php
rm app/Services/AnimeCatalog/ChineseTextConverter.php
rm app/Console/Commands/SyncSeasonalAnime.php
rm tests/Unit/BangumiAnimeNormalizerTest.php
```

- [ ] **Step 3: 提交(此時程式碼會暫時無法跑,後續任務修復;單獨 commit 讓刪除清晰)**

```bash
cd backend
git add -A
git commit -m "chore: remove legacy Bangumi scraping system"
```

---

## Task 2: 擷取真實 HTML fixture

爬蟲已驗證可連線。先把真實的索引頁與單一動畫區塊存成 fixture,供 parser TDD 使用。

**Files:**
- Create: `backend/tests/fixtures/acgsecrets_index.html`
- Create: `backend/tests/fixtures/acgsecrets_block.html`

- [ ] **Step 1: 下載索引頁 fixture**

```bash
cd backend && mkdir -p tests/fixtures
curl -s -H "User-Agent: anime-tracker/1.0 (+https://github.com/anime-tracker)" \
  "https://acgsecrets.hk/bangumi/list/" -o tests/fixtures/acgsecrets_index.html
```

- [ ] **Step 2: 下載一個季度頁並切出第一個動畫區塊存成 fixture**

```bash
cd backend
curl -s -H "User-Agent: anime-tracker/1.0 (+https://github.com/anime-tracker)" \
  "https://acgsecrets.hk/bangumi/202604/" -o /tmp/season_202604.html
php -r '
$h = file_get_contents("/tmp/season_202604.html");
if (preg_match("/(<div class=\"clear-both acgs-anime-block.*?)(?=<div class=\"clear-both acgs-anime-block)/s", $h, $m)) {
    file_put_contents("tests/fixtures/acgsecrets_block.html", $m[1]);
    echo "block bytes: ".strlen($m[1]).PHP_EOL;
} else { echo "NO BLOCK FOUND".PHP_EOL; exit(1); }
'
```
Expected: 印出 block bytes（數千 bytes）。

- [ ] **Step 3: 人工檢視 fixture,確認含繁中名/日文名/大綱/圖URL/播放日期/串流/外部連結**

Run: `cd backend && grep -oE "anime_names|anime_story|anime_cover_image|onair_times|stream-site|hasicon mal|hasicon bgmtv|entity_localized_name|entity_original_name" tests/fixtures/acgsecrets_block.html | sort -u`
Expected: 上述 class 大多出現。若缺，換另一部區塊（手動編輯 fixture）。

- [ ] **Step 4: 提交 fixture**

```bash
cd backend && git add tests/fixtures/ && git commit -m "test: add acgsecrets HTML fixtures"
```

---

## Task 3: AcgSecretsParser — 索引解析

**Files:**
- Create: `backend/app/Services/AnimeCatalog/AcgSecretsParser.php`
- Test: `backend/tests/Unit/AcgSecretsParserTest.php`

- [ ] **Step 1: 寫失敗測試（索引→季度代碼）**

```php
<?php

namespace Tests\Unit;

use App\Services\AnimeCatalog\AcgSecretsParser;
use PHPUnit\Framework\TestCase;

final class AcgSecretsParserTest extends TestCase
{
    private function parser(): AcgSecretsParser
    {
        return new AcgSecretsParser();
    }

    private function indexHtml(): string
    {
        return file_get_contents(__DIR__.'/../fixtures/acgsecrets_index.html');
    }

    public function test_parse_season_index_returns_yyyymm_codes(): void
    {
        $codes = $this->parser()->parseSeasonIndex($this->indexHtml());

        $this->assertContains('202604', $codes);
        $this->assertContains('201601', $codes);
        // 全部都是 6 位數字
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
        // 去重且排序
        $this->assertSame(array_values(array_unique($codes)), $codes);
    }
}
```

- [ ] **Step 2: 執行確認失敗**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/AcgSecretsParserTest.php --filter test_parse_season_index_returns_yyyymm_codes`
Expected: FAIL — class `AcgSecretsParser` not found。

- [ ] **Step 3: 實作 parser 索引部分**

```php
<?php

namespace App\Services\AnimeCatalog;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class AcgSecretsParser
{
    /** @return array<int, string> 季度代碼 YYYYMM，去重升冪 */
    public function parseSeasonIndex(string $html): array
    {
        if (! preg_match_all('#/bangumi/(\d{6})/#', $html, $matches)) {
            return [];
        }

        $codes = array_values(array_unique($matches[1]));
        sort($codes);

        return $codes;
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }
}
```

- [ ] **Step 4: 執行確認通過**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/AcgSecretsParserTest.php --filter test_parse_season_index_returns_yyyymm_codes`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
cd backend && git add app/Services/AnimeCatalog/AcgSecretsParser.php tests/Unit/AcgSecretsParserTest.php && git commit -m "feat: parse acgsecrets season index"
```

---

## Task 4: AcgSecretsParser — 單一動畫區塊解析

**Files:**
- Modify: `backend/app/Services/AnimeCatalog/AcgSecretsParser.php`
- Test: `backend/tests/Unit/AcgSecretsParserTest.php`

- [ ] **Step 1: 寫失敗測試（區塊→AnimeRecord 各欄位）**

加到 `AcgSecretsParserTest`：

```php
    private function blockHtml(): string
    {
        return file_get_contents(__DIR__.'/../fixtures/acgsecrets_block.html');
    }

    public function test_parse_anime_block_extracts_core_fields(): void
    {
        $record = $this->parser()->parseAnimeBlock($this->blockHtml(), '202604');

        $this->assertSame('202604', $record['season']);
        $this->assertSame(2026, $record['season_year']);
        $this->assertSame('spring', $record['season_code']);

        // 繁中主名非空、含中文
        $this->assertNotSame('', $record['title_zh']);
        $this->assertMatchesRegularExpression('/\p{Han}/u', $record['title_zh']);

        // 日文原名非空
        $this->assertNotSame('', $record['title_ja']);

        // 大綱、封面圖
        $this->assertNotSame('', $record['summary']);
        $this->assertStringStartsWith('http', $record['cover_image']);

        // 結構欄位型別
        $this->assertIsArray($record['aliases']);
        $this->assertIsArray($record['tags']);
        $this->assertIsArray($record['streams']);
        $this->assertIsArray($record['external_ids']);
    }

    public function test_parse_anime_block_extracts_streams_and_external_ids(): void
    {
        $record = $this->parser()->parseAnimeBlock($this->blockHtml(), '202604');

        // 至少有一個串流平台，且每筆有 region/platform
        if (! empty($record['streams'])) {
            $this->assertArrayHasKey('region', $record['streams'][0]);
            $this->assertArrayHasKey('platform', $record['streams'][0]);
        }

        // external_ids 鍵只允許 mal / bangumi
        foreach (array_keys($record['external_ids']) as $key) {
            $this->assertContains($key, ['mal', 'bangumi']);
        }
    }

    public function test_parse_anime_block_tolerates_missing_fields(): void
    {
        // 空區塊不應拋例外，欄位給安全預設值
        $record = $this->parser()->parseAnimeBlock('<div class="acgs-anime-block"></div>', '202010');

        $this->assertSame('', $record['title_zh']);
        $this->assertSame('', $record['summary']);
        $this->assertSame([], $record['aliases']);
        $this->assertSame([], $record['streams']);
        $this->assertSame([], $record['external_ids']);
        $this->assertSame(2020, $record['season_year']);
        $this->assertSame('fall', $record['season_code']);
    }
```

- [ ] **Step 2: 執行確認失敗**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/AcgSecretsParserTest.php --filter test_parse_anime_block`
Expected: FAIL — `parseAnimeBlock` not defined。

- [ ] **Step 3: 實作 parseAnimeBlock 與輔助方法**

在 `AcgSecretsParser` 內新增（接在 `parseSeasonIndex` 後、`xpath` 前）。先加 `parseSeasonPage` 與季別轉換，再加 `parseAnimeBlock`：

```php
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseSeasonPage(string $html, string $yyyymm): array
    {
        if (! preg_match_all(
            '#(<div class="clear-both acgs-anime-block.*?)(?=<div class="clear-both acgs-anime-block|<footer|</body>)#s',
            $html,
            $matches
        )) {
            return [];
        }

        $records = [];
        foreach ($matches[1] as $block) {
            $records[] = $this->parseAnimeBlock($block, $yyyymm);
        }

        return $records;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseAnimeBlock(string $blockHtml, string $yyyymm): array
    {
        $xpath = $this->xpath($blockHtml);
        $year = (int) substr($yyyymm, 0, 4);
        $month = (int) substr($yyyymm, 4, 2);
        $seasonCode = match ($month) {
            1 => 'winter',
            4 => 'spring',
            7 => 'summer',
            default => 'fall',
        };

        return [
            'season' => $yyyymm,
            'season_year' => $year,
            'season_code' => $seasonCode,
            'title_zh' => $this->firstText($xpath, [
                './/div[contains(@class,"entity_localized_name")]',
                './/div[contains(@class,"anime_names")]//div[1]',
            ]),
            'title_ja' => $this->firstText($xpath, [
                './/div[contains(@class,"entity_original_name")]',
            ]),
            'aliases' => $this->parseAliases($xpath),
            'summary' => $this->firstText($xpath, [
                './/div[contains(@class,"anime_story")]',
                './/div[contains(@class,"anime_summary")]',
            ]),
            'cover_image' => $this->parseCover($xpath),
            'air_date_text' => $this->firstText($xpath, [
                './/div[contains(@class,"onair_times")]',
            ]),
            'air_date' => $this->parseAirDate(
                $this->firstText($xpath, ['.//div[contains(@class,"onair_times")]']),
                $year,
                $month
            ),
            'tags' => $this->parseTags($xpath),
            'streams' => $this->parseStreams($xpath),
            'external_ids' => $this->parseExternalIds($xpath),
        ];
    }

    /** @param array<int, string> $queries */
    private function firstText(DOMXPath $xpath, array $queries): string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                $text = trim((string) $nodes->item(0)->textContent);
                if ($text !== '') {
                    return $this->collapseWhitespace($text);
                }
            }
        }

        return '';
    }

    private function collapseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @return array<int, string> */
    private function parseAliases(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('.//*[contains(@class,"entity_alternative_name")]');
        $aliases = [];
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $text = trim((string) $node->textContent);
                $text = trim(preg_replace('/^其他名稱[:：]/u', '', $text));
                foreach (preg_split('/[、,，]/u', $text) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $aliases[] = $part;
                    }
                }
            }
        }

        return array_values(array_unique($aliases));
    }

    private function parseCover(DOMXPath $xpath): string
    {
        foreach (['src', 'data-src'] as $attr) {
            $nodes = $xpath->query('.//div[contains(@class,"anime_cover_image")]//img/@'.$attr);
            if ($nodes !== false && $nodes->length > 0) {
                $url = trim((string) $nodes->item(0)->nodeValue);
                if (str_starts_with($url, 'http')) {
                    return $url;
                }
            }
        }

        return '';
    }

    /** @return array<int, string> */
    private function parseTags(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('.//div[contains(@class,"main_tags") or contains(@class,"sub_tags")]//div[contains(@class,"anime_tag")]');
        $tags = [];
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $text = trim((string) $node->textContent);
                if ($text !== '') {
                    $tags[] = $text;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /** @return array<int, array<string, string>> */
    private function parseStreams(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('.//div[contains(@class,"stream-area")]');
        $streams = [];
        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $area) {
            $regionNodes = $xpath->query('.//div[contains(@class,"stream-time") or contains(@class,"steam-site-name")]', $area);
            $region = ($regionNodes !== false && $regionNodes->length > 0)
                ? trim((string) $regionNodes->item(0)->textContent)
                : '';

            $siteNodes = $xpath->query('.//div[contains(@class,"stream-site")]', $area);
            if ($siteNodes === false) {
                continue;
            }
            foreach ($siteNodes as $site) {
                $platform = trim((string) $site->textContent);
                $linkNodes = $xpath->query('.//a/@href', $site);
                $url = ($linkNodes !== false && $linkNodes->length > 0)
                    ? trim((string) $linkNodes->item(0)->nodeValue)
                    : null;
                if ($platform !== '') {
                    $streams[] = [
                        'region' => $this->collapseWhitespace($region),
                        'platform' => $this->collapseWhitespace($platform),
                        'url' => $url,
                    ];
                }
            }
        }

        return $streams;
    }

    /** @return array<string, string> */
    private function parseExternalIds(DOMXPath $xpath): array
    {
        $ids = [];
        $links = $xpath->query('.//div[contains(@class,"anime_links")]//a/@href');
        if ($links !== false) {
            foreach ($links as $href) {
                $url = (string) $href->nodeValue;
                if (preg_match('#myanimelist\.net/anime/(\d+)#', $url, $m)) {
                    $ids['mal'] = $m[1];
                } elseif (preg_match('#(?:bgm\.tv|bangumi\.tv)/subject/(\d+)#', $url, $m)) {
                    $ids['bangumi'] = $m[1];
                }
            }
        }

        return $ids;
    }

    private function parseAirDate(string $onairText, int $year, int $month): ?string
    {
        // 例：「4月4日起／每週六／…」→ 2026-04-04
        if (preg_match('/(\d{1,2})月(\d{1,2})日/u', $onairText, $m)) {
            return sprintf('%04d-%02d-%02d', $year, (int) $m[1], (int) $m[2]);
        }

        return null;
    }
```

- [ ] **Step 4: 執行確認通過**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/AcgSecretsParserTest.php`
Expected: PASS（全部）。若某欄位斷言因真實 fixture 結構不符而失敗，調整對應 XPath 直到通過——以 fixture 真實內容為準。

- [ ] **Step 5: 提交**

```bash
cd backend && git add app/Services/AnimeCatalog/AcgSecretsParser.php tests/Unit/AcgSecretsParserTest.php && git commit -m "feat: parse acgsecrets anime block into record"
```

---

## Task 5: AcgSecretsClient + scrape command（驗證閘門）

**Files:**
- Create: `backend/app/Services/AnimeCatalog/AcgSecretsClient.php`
- Create: `backend/app/Console/Commands/ScrapeAcgSecrets.php`
- Modify: `backend/config/services.php`

- [ ] **Step 1: 改 config — 用 acgsecrets 取代 bangumi**

`backend/config/services.php`，把 `'bangumi' => [...]` 整段替換為：

```php
    'acgsecrets' => [
        'base_url' => rtrim(env('ACGSECRETS_BASE_URL', 'https://acgsecrets.hk'), '/'),
        'user_agent' => env('ACGSECRETS_USER_AGENT', 'anime-tracker/1.0 (+https://github.com/anime-tracker)'),
        'min_delay_ms' => (int) env('ACGSECRETS_MIN_DELAY_MS', 1000),
        'max_delay_ms' => (int) env('ACGSECRETS_MAX_DELAY_MS', 3000),
        'retries' => (int) env('ACGSECRETS_RETRIES', 2),
    ],
```

- [ ] **Step 2: 實作 AcgSecretsClient**

```php
<?php

namespace App\Services\AnimeCatalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AcgSecretsClient
{
    public function fetchIndex(): string
    {
        return $this->get('/bangumi/list/');
    }

    public function fetchSeason(string $yyyymm): string
    {
        $html = $this->get("/bangumi/{$yyyymm}/");
        $this->throttle();

        return $html;
    }

    private function get(string $path): string
    {
        $url = (string) config('services.acgsecrets.base_url').$path;
        $retries = (int) config('services.acgsecrets.retries');

        $response = Http::timeout((int) config('services.http.timeout_seconds'))
            ->withUserAgent((string) config('services.acgsecrets.user_agent'))
            ->retry($retries + 1, 1000, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("acgsecrets fetch failed [{$response->status()}]: {$url}");
        }

        return $response->body();
    }

    private function throttle(): void
    {
        $min = (int) config('services.acgsecrets.min_delay_ms');
        $max = (int) config('services.acgsecrets.max_delay_ms');
        usleep(random_int($min, max($min, $max)) * 1000);
    }
}
```

- [ ] **Step 3: 實作 ScrapeAcgSecrets command**

```php
<?php

namespace App\Console\Commands;

use App\Services\AnimeCatalog\AcgSecretsClient;
use App\Services\AnimeCatalog\AcgSecretsParser;
use App\Services\AnimeCatalog\SeasonResolver;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ScrapeAcgSecrets extends Command
{
    protected $signature = 'anime:scrape-acgsecrets {--all} {--season=}';

    protected $description = 'Scrape acgsecrets.hk seasonal anime into JSON files.';

    public function handle(AcgSecretsClient $client, AcgSecretsParser $parser): int
    {
        $dir = database_path('seed/acgsecrets');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $seasons = $this->resolveSeasons($client, $parser);
        $summary = ['generated_at' => date('c'), 'seasons' => [], 'failed' => []];

        foreach ($seasons as $yyyymm) {
            try {
                $html = $client->fetchSeason($yyyymm);
                $records = $parser->parseSeasonPage($html, $yyyymm);
                file_put_contents(
                    "{$dir}/{$yyyymm}.json",
                    json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                );
                $summary['seasons'][$yyyymm] = [
                    'count' => count($records),
                    'missing_title_zh' => count(array_filter($records, fn ($r) => $r['title_zh'] === '')),
                    'missing_summary' => count(array_filter($records, fn ($r) => $r['summary'] === '')),
                    'missing_cover' => count(array_filter($records, fn ($r) => $r['cover_image'] === '')),
                ];
                $this->info("{$yyyymm}: ".count($records)." records");
            } catch (Throwable $e) {
                $summary['failed'][] = ['season' => $yyyymm, 'error' => $e->getMessage()];
                $this->error("{$yyyymm}: {$e->getMessage()}");
            }
        }

        file_put_contents(
            "{$dir}/summary.json",
            json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return empty($summary['failed']) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    private function resolveSeasons(AcgSecretsClient $client, AcgSecretsParser $parser): array
    {
        if ($season = (string) $this->option('season')) {
            return [$season];
        }

        if ($this->option('all')) {
            return $parser->parseSeasonIndex($client->fetchIndex());
        }

        // 當季 + 上一季
        $now = new DateTimeImmutable('now');
        $current = SeasonResolver::current($now);
        $months = ['winter' => 1, 'spring' => 4, 'summer' => 7, 'fall' => 10];
        $curMonth = $months[$current['code']];
        $curYyyymm = sprintf('%04d%02d', $current['year'], $curMonth);

        $prevMonth = $curMonth - 3;
        $prevYear = $current['year'];
        if ($prevMonth < 1) {
            $prevMonth = 10;
            $prevYear--;
        }
        $prevYyyymm = sprintf('%04d%02d', $prevYear, $prevMonth);

        return [$prevYyyymm, $curYyyymm];
    }
}
```

- [ ] **Step 4: 驗證閘門 — 跑單季並人工檢查 JSON 品質**

Run: `cd backend && php artisan anime:scrape-acgsecrets --season=202604`
Expected: 印出 `202604: <N> records`（N 約 60~80），產生 `database/seed/acgsecrets/202604.json`。

Run: `cd backend && php -r '$d=json_decode(file_get_contents("database/seed/acgsecrets/202604.json"),true); echo count($d)." records\n"; print_r($d[0]);'`
Expected: 第一筆 record 含正確的繁中 title_zh、title_ja、summary、cover_image(http)、streams、external_ids。

**人工檢查點：** 打開 https://acgsecrets.hk/bangumi/202604/ 比對前 3~5 部，確認名稱/大綱/圖片正確。若有系統性錯誤，回 Task 4 修 XPath。**確認 OK 才繼續。**

- [ ] **Step 5: 提交（含驗證用的 202604.json）**

```bash
cd backend && git add app/Services/AnimeCatalog/AcgSecretsClient.php app/Console/Commands/ScrapeAcgSecrets.php config/services.php database/seed/acgsecrets/202604.json database/seed/acgsecrets/summary.json && git commit -m "feat: add acgsecrets scrape command and client"
```

---

## Task 6: 全量爬取並提交 JSON

**Files:**
- Create: `backend/database/seed/acgsecrets/*.json`（~40 檔）

- [ ] **Step 1: 全量爬取（會耗時數分鐘，因降速）**

Run: `cd backend && php artisan anime:scrape-acgsecrets --all`
Expected: 逐季印出 records 數，最後 exit 0（無 failed）。若有少數季 failed，單獨重跑：`php artisan anime:scrape-acgsecrets --season=YYYYMM`。

- [ ] **Step 2: 檢視 summary 確認品質**

Run: `cd backend && php -r '$s=json_decode(file_get_contents("database/seed/acgsecrets/summary.json"),true); $t=0; foreach($s["seasons"] as $k=>$v){$t+=$v["count"];} echo "seasons: ".count($s["seasons"]).", total: $t, failed: ".count($s["failed"])."\n";'`
Expected: seasons ~40、total ~2500-2900、failed 0。

- [ ] **Step 3: 提交全部 JSON**

```bash
cd backend && git add database/seed/acgsecrets/ && git commit -m "data: add full acgsecrets seasonal anime JSON (2016-2025)"
```

---

## Task 7: anime_streams 表 + AnimeStream model

**Files:**
- Create: `backend/database/migrations/2026_06_30_000000_create_anime_streams_table.php`
- Create: `backend/app/Models/AnimeStream.php`
- Modify: `backend/app/Models/Anime.php`

- [ ] **Step 1: 建 migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anime_streams')) {
            Schema::create('anime_streams', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
                $table->string('region', 32);
                $table->string('platform', 64);
                $table->text('url')->nullable();
                $table->timestamps();
                $table->unique(['anime_id', 'region', 'platform'], 'uniq_anime_stream');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_streams');
    }
};
```

- [ ] **Step 2: 建 AnimeStream model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AnimeStream extends Model
{
    protected $fillable = ['anime_id', 'region', 'platform', 'url'];

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
```

- [ ] **Step 3: Anime model 加 streams 關聯**

`backend/app/Models/Anime.php`，在 `externalIds()` 方法後加：

```php
    public function streams(): HasMany
    {
        return $this->hasMany(AnimeStream::class);
    }
```

- [ ] **Step 4: 跑 migration 確認無誤**

Run: `cd backend && php artisan migrate`
Expected: `anime_streams` 表建立成功。

- [ ] **Step 5: 提交**

```bash
cd backend && git add database/migrations/2026_06_30_000000_create_anime_streams_table.php app/Models/AnimeStream.php app/Models/Anime.php && git commit -m "feat: add anime_streams table and model"
```

---

## Task 8: 重構 AnimeImportService 吃 AnimeRecord

**Files:**
- Modify: `backend/app/Services/AnimeCatalog/AnimeImportService.php`
- Test: `backend/tests/Unit/AnimeImportServiceTest.php`

- [ ] **Step 1: 寫失敗測試**

```php
<?php

namespace Tests\Unit;

use App\Models\Anime;
use App\Models\AnimeStream;
use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnimeImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $overrides = []): array
    {
        return array_merge([
            'season' => '202604',
            'season_year' => 2026,
            'season_code' => 'spring',
            'title_zh' => '黃泉雙使',
            'title_ja' => '黄泉のツガイ',
            'aliases' => ['黃泉的使者', 'Yomi no Tsugai'],
            'summary' => '大綱內容',
            'cover_image' => 'https://static.acgsecrets.hk/x.jpg',
            'air_date_text' => '4月4日起',
            'air_date' => '2026-04-04',
            'tags' => ['動作', '奇幻'],
            'streams' => [
                ['region' => '台灣', 'platform' => '巴哈姆特動畫瘋', 'url' => 'https://ani.gamer'],
            ],
            'external_ids' => ['mal' => '12345', 'bangumi' => '377130'],
        ], $overrides);
    }

    public function test_import_record_creates_anime_with_all_relations(): void
    {
        $service = app(AnimeImportService::class);
        $anime = $service->importRecord($this->record());

        $this->assertSame('黃泉雙使', $anime->name);
        $this->assertSame('大綱內容', $anime->description);
        $this->assertSame(2026, $anime->season_year);
        $this->assertSame('spring', $anime->season_code);
        $this->assertSame('acgsecrets', $anime->source);

        $this->assertSame('黄泉のツガイ', $anime->titles()->where('locale', 'ja')->value('title'));
        $this->assertTrue($anime->titles()->where('locale', 'zh-Hant')->where('is_primary', true)->exists());
        $this->assertSame(2, $anime->aliases()->count());
        $this->assertSame('12345', $anime->externalIds()->where('provider', 'mal')->value('external_id'));
        $this->assertSame('巴哈姆特動畫瘋', $anime->streams()->value('platform'));
    }

    public function test_import_record_upserts_by_external_id(): void
    {
        $service = app(AnimeImportService::class);
        $service->importRecord($this->record());
        $service->importRecord($this->record(['summary' => '更新後的大綱']));

        $this->assertSame(1, Anime::count());
        $this->assertSame('更新後的大綱', Anime::first()->description);
        $this->assertSame(1, AnimeStream::count());
    }
}
```

- [ ] **Step 2: 執行確認失敗**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/AnimeImportServiceTest.php`
Expected: FAIL — `importRecord` not defined（舊 service 是 `syncBangumiSeason`/`upsertImported`）。

- [ ] **Step 3: 重寫 AnimeImportService**

整檔替換為：

```php
<?php

namespace App\Services\AnimeCatalog;

use App\Models\Anime;
use App\Models\AnimeAlias;
use App\Models\AnimeExternalId;
use App\Models\AnimeStream;
use App\Models\AnimeTitle;
use Illuminate\Support\Facades\DB;

final class AnimeImportService
{
    public function importRecord(array $record): Anime
    {
        $payloadHash = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($record, $payloadHash): Anime {
            $anime = $this->resolveAnime($record);
            $anime->fill([
                'name' => $record['title_zh'],
                'description' => $record['summary'],
                'image_url' => $record['cover_image'],
                'source' => 'acgsecrets',
                'season_year' => $record['season_year'],
                'season_code' => $record['season_code'],
                'air_date' => $record['air_date'],
            ]);
            $anime->save();

            $this->syncTitles($anime, $record);
            $this->syncAliases($anime, $record);
            $this->syncExternalIds($anime, $record, $payloadHash);
            $this->syncStreams($anime, $record);

            return $anime->refresh();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{imported:int, skipped:int, errors:array}
     */
    public function importSeason(array $records): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($records as $record) {
            try {
                if (($record['title_zh'] ?? '') === '') {
                    $skipped++;
                    continue;
                }
                $this->importRecord($record);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = ['title' => $record['title_zh'] ?? null, 'message' => $e->getMessage()];
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function resolveAnime(array $record): Anime
    {
        foreach (['bangumi', 'mal'] as $provider) {
            $externalId = $record['external_ids'][$provider] ?? null;
            if ($externalId !== null) {
                $existing = AnimeExternalId::query()
                    ->where('provider', $provider)
                    ->where('external_id', (string) $externalId)
                    ->first();
                if ($existing?->anime) {
                    return $existing->anime;
                }
            }
        }

        $existing = Anime::query()
            ->where('season_year', $record['season_year'])
            ->where('season_code', $record['season_code'])
            ->where('name', $record['title_zh'])
            ->first();

        return $existing ?? new Anime();
    }

    private function syncTitles(Anime $anime, array $record): void
    {
        $anime->titles()->update(['is_primary' => false]);

        $titles = [
            'zh-Hant' => $record['title_zh'],
            'ja' => $record['title_ja'],
        ];
        foreach ($titles as $locale => $title) {
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            AnimeTitle::query()->updateOrCreate(
                ['anime_id' => $anime->id, 'locale' => $locale, 'title' => $title],
                ['is_primary' => $locale === 'zh-Hant', 'source' => 'acgsecrets']
            );
        }
    }

    private function syncAliases(Anime $anime, array $record): void
    {
        $anime->aliases()->delete();
        foreach ($record['aliases'] as $alias) {
            $alias = trim((string) $alias);
            if ($alias !== '') {
                AnimeAlias::query()->create(['anime_id' => $anime->id, 'alias' => $alias]);
            }
        }
    }

    private function syncExternalIds(Anime $anime, array $record, string $payloadHash): void
    {
        $urls = [
            'mal' => 'https://myanimelist.net/anime/',
            'bangumi' => 'https://bgm.tv/subject/',
        ];
        foreach ($record['external_ids'] as $provider => $externalId) {
            AnimeExternalId::query()->updateOrCreate(
                ['provider' => $provider, 'external_id' => (string) $externalId],
                [
                    'anime_id' => $anime->id,
                    'url' => ($urls[$provider] ?? '').$externalId,
                    'last_synced_at' => now(),
                    'payload_hash' => $payloadHash,
                ]
            );
        }
    }

    private function syncStreams(Anime $anime, array $record): void
    {
        $anime->streams()->delete();
        foreach ($record['streams'] as $stream) {
            $platform = trim((string) ($stream['platform'] ?? ''));
            if ($platform === '') {
                continue;
            }
            AnimeStream::query()->create([
                'anime_id' => $anime->id,
                'region' => trim((string) ($stream['region'] ?? '')),
                'platform' => $platform,
                'url' => $stream['url'] ?? null,
            ]);
        }
    }
}
```

注意 `AnimeAlias` 沒有 timestamps 欄位（migration 未建），確認 model 設定。若 `AnimeAlias` 報 timestamps 錯誤，於 Step 4 在 model 加 `public $timestamps = false;`。

- [ ] **Step 4: 執行確認通過**

Run: `cd backend && ./vendor/bin/phpunit tests/Unit/AnimeImportServiceTest.php`
Expected: PASS。若 `AnimeAlias` 報 `created_at` 錯誤，在 `app/Models/AnimeAlias.php` 加 `public $timestamps = false;` 後重跑。

- [ ] **Step 5: 提交**

```bash
cd backend && git add app/Services/AnimeCatalog/AnimeImportService.php app/Models/AnimeAlias.php tests/Unit/AnimeImportServiceTest.php && git commit -m "refactor: AnimeImportService reads acgsecrets records"
```

---

## Task 9: anime:import-acgsecrets command + 清庫匯入

**Files:**
- Create: `backend/app/Console/Commands/ImportAcgSecrets.php`

- [ ] **Step 1: 實作 import command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Anime;
use App\Models\AnimeAlias;
use App\Models\AnimeExternalId;
use App\Models\AnimeStream;
use App\Models\AnimeTitle;
use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ImportAcgSecrets extends Command
{
    protected $signature = 'anime:import-acgsecrets {--fresh}';

    protected $description = 'Import acgsecrets JSON files into the database.';

    public function handle(AnimeImportService $service): int
    {
        if ($this->option('fresh')) {
            DB::transaction(function (): void {
                AnimeStream::query()->delete();
                AnimeExternalId::query()->delete();
                AnimeAlias::query()->delete();
                AnimeTitle::query()->delete();
                Anime::query()->delete();
            });
            $this->warn('Cleared existing anime data.');
        }

        $dir = database_path('seed/acgsecrets');
        $files = glob("{$dir}/*.json") ?: [];
        $totalImported = 0;
        $totalSkipped = 0;

        foreach ($files as $file) {
            if (basename($file) === 'summary.json') {
                continue;
            }
            $records = json_decode((string) file_get_contents($file), true);
            if (! is_array($records)) {
                continue;
            }
            $result = $service->importSeason($records);
            $totalImported += $result['imported'];
            $totalSkipped += $result['skipped'];
            $this->info(basename($file).": imported {$result['imported']}, skipped {$result['skipped']}");
        }

        $this->info("Done. imported={$totalImported} skipped={$totalSkipped}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: 清庫並全量匯入**

Run: `cd backend && php artisan anime:import-acgsecrets --fresh`
Expected: 逐檔印出 imported/skipped，最後 `Done. imported=~2700 skipped=<少量>`。

- [ ] **Step 3: 驗證 DB 內容**

Run: `cd backend && php artisan tinker --execute="echo App\Models\Anime::count().' anime, '.App\Models\AnimeStream::count().' streams'.PHP_EOL; \$a=App\Models\Anime::has('streams')->first(); echo \$a->name.' | '.\$a->season_year.' | '.\$a->streams()->count().' streams'.PHP_EOL;"`
Expected: 數千 anime、數千 streams，範例 anime 有繁中名與串流。

- [ ] **Step 4: 提交**

```bash
cd backend && git add app/Console/Commands/ImportAcgSecrets.php && git commit -m "feat: add acgsecrets import command"
```

---

## Task 10: 排程註冊

**Files:**
- Modify: `backend/routes/console.php`

- [ ] **Step 1: 加入每週排程**

`backend/routes/console.php`，在檔尾加：

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('anime:scrape-acgsecrets')
    ->weeklyOn(1, '05:00')
    ->then(function (): void {
        Artisan::call('anime:import-acgsecrets');
    });
```

- [ ] **Step 2: 確認排程已註冊**

Run: `cd backend && php artisan schedule:list`
Expected: 列出 `anime:scrape-acgsecrets`，下次執行為週一 05:00。

- [ ] **Step 3: 提交**

```bash
cd backend && git add routes/console.php && git commit -m "feat: schedule weekly acgsecrets sync"
```

---

## Task 11: AnimeController 擴充 + 移除 syncSeasonal

**Files:**
- Modify: `backend/app/Http/Controllers/Api/AnimeController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/ApiTest.php`

- [ ] **Step 1: 寫/改 Feature 測試（index 回傳 streams/aliases/titles）**

在 `backend/tests/Feature/ApiTest.php` 加一個測試（沿用既有 TestCase 風格；先看檔案現有 helper）：

```php
    public function test_anime_index_returns_streams_aliases_titles(): void
    {
        $service = app(\App\Services\AnimeCatalog\AnimeImportService::class);
        $service->importRecord([
            'season' => '202604', 'season_year' => 2026, 'season_code' => 'spring',
            'title_zh' => '測試動畫', 'title_ja' => 'テスト',
            'aliases' => ['別名A'], 'summary' => '介紹', 'cover_image' => 'https://x/y.jpg',
            'air_date_text' => '', 'air_date' => '2026-04-04', 'tags' => [],
            'streams' => [['region' => '台灣', 'platform' => '巴哈', 'url' => 'https://a']],
            'external_ids' => [],
        ]);

        $response = $this->getJson('/api/anime?year=2026&season=spring');
        $response->assertOk();
        $item = collect($response->json('items'))->firstWhere('name', '測試動畫');

        $this->assertNotNull($item);
        $this->assertSame('巴哈', $item['streams'][0]['platform']);
        $this->assertContains('別名A', $item['aliases']);
        $this->assertTrue(collect($item['titles'])->contains(fn ($t) => $t['locale'] === 'ja'));
    }
```

- [ ] **Step 2: 執行確認失敗**

Run: `cd backend && ./vendor/bin/phpunit tests/Feature/ApiTest.php --filter test_anime_index_returns_streams`
Expected: FAIL — 回傳缺 streams/aliases/titles 鍵。

- [ ] **Step 3: 改 AnimeController::index 加 eager load 與輸出**

`AnimeController::index`，把查詢與回傳改為（移除原 leftJoin 改用 with + whereHas 搜尋，避免 distinct 欄位問題）：

```php
        $items = Anime::query()
            ->with([
                'streams:id,anime_id,region,platform,url',
                'aliases:id,anime_id,alias',
                'titles:id,anime_id,locale,title,is_primary',
            ])
            ->when($query !== '', function ($builder) use ($term): void {
                $builder->where(function ($where) use ($term): void {
                    $where->where('name', 'like', $term)
                        ->orWhereHas('aliases', fn ($q) => $q->where('alias', 'like', $term))
                        ->orWhereHas('titles', fn ($q) => $q->where('title', 'like', $term));
                });
            })
            ->when($year !== null, fn ($builder) => $builder->where('season_year', (int) $year))
            ->when($season !== '', fn ($builder) => $builder->where('season_code', $season))
            ->orderByRaw('air_date is null')
            ->orderBy('air_date')
            ->orderBy('name')
            ->limit(50)
            ->get([
                'id', 'name', 'description', 'image_url', 'source',
                'season_year', 'season_code', 'air_date', 'episode_count', 'status',
            ]);

        return response()->json([
            'items' => $items->map(fn (Anime $anime) => [
                'id' => $anime->id,
                'name' => $anime->name,
                'description' => $anime->description,
                'image_url' => $anime->image_url,
                'source' => $anime->source,
                'season_year' => $anime->season_year,
                'season_code' => $anime->season_code,
                'air_date' => $anime->air_date,
                'episode_count' => $anime->episode_count,
                'status' => $anime->status,
                'aliases' => $anime->aliases->pluck('alias')->all(),
                'streams' => $anime->streams->map(fn ($s) => [
                    'region' => $s->region, 'platform' => $s->platform, 'url' => $s->url,
                ])->all(),
                'titles' => $anime->titles->map(fn ($t) => [
                    'locale' => $t->locale, 'title' => $t->title, 'is_primary' => (bool) $t->is_primary,
                ])->all(),
            ]),
        ]);
```

- [ ] **Step 4: 移除 syncSeasonal 方法與路由**

刪除 `AnimeController::syncSeasonal()` 整個方法，並移除 import：`use App\Services\AnimeCatalog\AnimeImportService;`（若 index 不再用到）。

`backend/routes/api.php`，移除這行：
```php
Route::post('/anime/sync-seasonal', [AnimeController::class, 'syncSeasonal'])->middleware('jwt');
```

- [ ] **Step 5: 執行測試（含全套，確認沒打壞既有）**

Run: `cd backend && ./vendor/bin/phpunit`
Expected: 全 PASS。若 `ApiTest` 既有測試引用 sync-seasonal，一併移除那些斷言。

- [ ] **Step 6: 提交**

```bash
cd backend && git add app/Http/Controllers/Api/AnimeController.php routes/api.php tests/Feature/ApiTest.php && git commit -m "feat: expose streams/aliases/titles in anime API, remove sync-seasonal"
```

---

## Task 12: 前端 — normalize 加新欄位

**Files:**
- Modify: `frontend/app/utils/normalize.ts`
- Test: `frontend/test/normalize.spec.ts`（或既有測試檔）

- [ ] **Step 1: 確認既有前端測試檔位置與風格**

Run: `cd frontend && ls test/ && cat vitest.config.ts | head -20`
Expected: 找到測試目錄與既有 spec 命名。下方測試請放進對應檔案（若已有 normalize 測試檔則加進去）。

- [ ] **Step 2: 寫失敗測試**

```typescript
import { describe, it, expect } from 'vitest'
import { normalizeAnime } from '../app/utils/normalize'

describe('normalizeAnime new fields', () => {
  it('maps streams, aliases, titleJa from snake_case API', () => {
    const a = normalizeAnime({
      id: 1, name: '測試', description: '介紹', image_url: 'https://x/y.jpg',
      season_year: 2026, season_code: 'spring',
      aliases: ['別名A'],
      streams: [{ region: '台灣', platform: '巴哈', url: 'https://a' }],
      titles: [{ locale: 'ja', title: 'テスト', is_primary: false }],
    })
    expect(a.streams).toHaveLength(1)
    expect(a.streams[0].platform).toBe('巴哈')
    expect(a.aliases).toContain('別名A')
    expect(a.titleJa).toBe('テスト')
  })

  it('defaults new fields to empty when absent', () => {
    const a = normalizeAnime({ id: 2, name: 'x' })
    expect(a.streams).toEqual([])
    expect(a.aliases).toEqual([])
    expect(a.titleJa).toBe('')
  })
})
```

- [ ] **Step 3: 執行確認失敗**

Run: `cd frontend && npx vitest run test/normalize.spec.ts`
Expected: FAIL — `streams`/`aliases`/`titleJa` undefined。

- [ ] **Step 4: 擴充 normalize.ts**

`frontend/app/utils/normalize.ts`，`Anime` interface 加欄位：

```typescript
export interface AnimeStream {
  region: string
  platform: string
  url: string | null
}

export interface Anime {
  id: number
  name: string
  description: string
  imageUrl: string
  source: string
  seasonYear: number | null
  seasonCode: string
  airDate: string | null
  episodeCount: number | null
  status: string
  aliases: string[]
  streams: AnimeStream[]
  titleJa: string
}
```

`normalizeAnime` return 物件加：

```typescript
    aliases: Array.isArray(item.aliases) ? item.aliases.map((a: any) => repairText(a)) : [],
    streams: Array.isArray(item.streams)
      ? item.streams.map((s: any) => ({
          region: repairText(s.region),
          platform: repairText(s.platform),
          url: s.url || null
        }))
      : [],
    titleJa: repairText(
      (Array.isArray(item.titles) ? item.titles.find((t: any) => t.locale === 'ja')?.title : '') || ''
    )
```

- [ ] **Step 5: 執行確認通過**

Run: `cd frontend && npx vitest run test/normalize.spec.ts`
Expected: PASS

- [ ] **Step 6: 提交**

```bash
cd frontend && git add app/utils/normalize.ts test/normalize.spec.ts && git commit -m "feat: normalize anime streams/aliases/titleJa"
```

---

## Task 13: 前端 — 移除同步、顯示串流

**Files:**
- Modify: `frontend/app/composables/useApi.ts`
- Modify: `frontend/app/pages/seasonal.vue`
- Modify: `frontend/app/pages/catalog.vue`

- [ ] **Step 1: useApi 移除 syncSeasonalAnime**

`frontend/app/composables/useApi.ts`,刪除這一行：
```typescript
    syncSeasonalAnime: (payload: Record<string, any>) => request('/anime/sync-seasonal', { method: 'POST', body: JSON.stringify(payload) }),
```

- [ ] **Step 2: seasonal.vue 移除同步邏輯**

`frontend/app/pages/seasonal.vue`：
- 刪除 `syncResult` ref、`syncSeasonal()` 函式。
- 刪除 template 中呼叫 `syncSeasonal` 的按鈕與顯示 `syncResult` 的區塊。
- 確認 `loadSeasonal()` 與篩選流程不動。

- [ ] **Step 3: seasonal.vue 卡片顯示串流平台與日文名**

在動畫卡片 template 中（顯示 `anime.name` 附近）加入：

```vue
<p v-if="anime.titleJa" class="text-sm text-gray-500">{{ anime.titleJa }}</p>
<div v-if="anime.streams.length" class="mt-2 flex flex-wrap gap-2">
  <a
    v-for="s in anime.streams"
    :key="s.region + s.platform"
    :href="s.url || undefined"
    target="_blank"
    rel="noopener"
    class="text-xs px-2 py-1 rounded bg-gray-100 hover:bg-gray-200"
  >{{ s.region }} · {{ s.platform }}</a>
</div>
```

（class 名稱依專案既有 Tailwind/Nuxt UI 慣例調整，與相鄰元素風格一致。）

- [ ] **Step 4: catalog.vue 卡片同樣顯示串流/日文名**

在 `catalog.vue` 卡片區塊套用與 Step 3 相同的 `titleJa` 與 `streams` 顯示片段。

- [ ] **Step 5: 跑前端測試與型別檢查**

Run: `cd frontend && npx vitest run && npx nuxt typecheck`
Expected: 測試 PASS；typecheck 無因新欄位引發的錯誤（既有無關錯誤忽略）。若無 `nuxt typecheck` script，改跑 `npx vue-tsc --noEmit` 或略過。

- [ ] **Step 6: 提交**

```bash
cd frontend && git add app/composables/useApi.ts app/pages/seasonal.vue app/pages/catalog.vue && git commit -m "feat: show streams and JP title, remove manual sync"
```

---

## Task 14: 端對端驗證

- [ ] **Step 1: 啟動後端**

Run: `cd backend && php artisan serve --port=8000`（背景執行）
確認 DB 已是 Task 9 匯入後的狀態。

- [ ] **Step 2: 驗證 API 實際回傳**

Run: `curl -s "http://localhost:8000/api/anime?year=2026&season=spring" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo count($d["items"])." items\n"; print_r($d["items"][0]);'`
Expected: items 數十筆；第一筆含 name(繁中)、description、image_url、streams、aliases、titles。

- [ ] **Step 3: 啟動前端並人工檢視**

Run: `cd frontend && npm run dev`
打開瀏覽器到 seasonal 頁，選 2026 春季：確認顯示繁中名稱、日文原名、大綱、封面圖、串流平台連結；確認同步按鈕已消失。

- [ ] **Step 4: 最終確認 — 全測試綠燈**

Run: `cd backend && ./vendor/bin/phpunit && cd ../frontend && npx vitest run`
Expected: 後端與前端測試全 PASS。

- [ ] **Step 5: 完成（不需 commit，純驗證）**

若有任何顯示問題，回對應 Task 修正。
```
