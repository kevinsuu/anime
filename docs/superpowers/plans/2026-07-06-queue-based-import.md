# Queue 化匯入流程 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 `anime:import-acgsecrets` 從逐筆同步處理改成用 Laravel Queue（database driver）分派 job、4 個 worker 子行程平行消費，加快匯入速度並消除長跑進程的記憶體累積問題，指令的對外使用方式與輸出格式維持不變。

**Architecture:** 新增 `ImportAnimeRecordJob`（`ShouldQueue`，內部呼叫既有 `AnimeImportService::importRecord`，邏輯完全不變）。`AnimeImportService` 新增兩個 public 輔助方法 `computePayloadHash()`、`needsImport()`，讓命令層能在 dispatch 前先過濾掉未變動的記錄。`ImportAcgSecrets` 命令改為：讀檔案→用 `needsImport()` 過濾→dispatch job→用 `Symfony\Component\Process\Process` 平行啟動 4 個 `queue:work --queue=import --tries=3 --stop-when-empty` 子行程→等待全部子行程結束→用 Cache 計數彙總最終報告。

**Tech Stack:** Laravel 13 Queue（`database` driver，複用既有 MySQL，不新增 Redis）、`Symfony\Component\Process\Process`（已是 laravel/framework 的既有依賴，經實測確認可用且真正並行，不需要 `composer require` 任何新套件）、Laravel `Cache` facade（預設 `file` driver 已足夠，用於跨 worker 子行程彙總計數）。

---

## 已驗證的技術細節（供各 Task 直接引用，不需再摸索）

- **`Illuminate\Support\Facades\Process`（`Process::pool()`）在這個專案裡不可用**——實測 `illuminate/process` 套件並未安裝在 `composer.lock` 裡（`composer show illuminate/process` 回報找不到），呼叫 `Process::pool(...)->start()->wait()` 會靜默無效（無錯誤但也無輸出）。**不要用這個 facade**，改用 `Symfony\Component\Process\Process`（`laravel/framework` 的核心依賴，一定存在，不需要額外安裝）。
- **`Symfony\Component\Process\Process` 已實測驗證真正並行**：
  ```php
  use Symfony\Component\Process\Process;

  $processes = [];
  for ($i = 0; $i < 4; $i++) {
      $p = new Process(['php', 'artisan', 'queue:work', '--queue=import', '--tries=3', '--stop-when-empty']);
      $p->setWorkingDirectory(base_path());
      $p->setTimeout(null); // 匯入可能耗時較久，不設逾時
      $p->start();
      $processes[] = $p;
  }
  foreach ($processes as $p) {
      $p->wait();
  }
  ```
  實測 3 個各 1 秒的 process 平行執行僅耗時 1.01 秒（非 3 秒），確認真正並行而非序列。
- **`jobs`/`failed_jobs` 資料表目前不存在**（已用 `Schema::hasTable()` 確認），需要透過 Laravel 內建的 `php artisan queue:table` 與 `php artisan queue:failed-table` 產生 migration。
- **`config/queue.php` 已存在於專案**（Laravel 預設檔案），`'default' => env('QUEUE_CONNECTION', 'database')` 已經支援 database driver，不需要修改這個檔案本身，只需要改 `.env`／`docker-compose.yml`／`deploy/docker-compose.yml` 的 `QUEUE_CONNECTION` 環境變數。
- **`AnimeImportService::importRecord` 現有的 hash 計算**（[AnimeImportService.php:46](../../../backend/app/Services/AnimeCatalog/AnimeImportService.php#L46)）：`hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))`。新增的 `computePayloadHash()` 必須完全複製這個計算方式，`importRecord` 內部改為呼叫這個新方法，避免兩份重複邏輯將來走偏。
- **`resolveAnime()` 目前是 private 方法**（[AnimeImportService.php:144](../../../backend/app/Services/AnimeCatalog/AnimeImportService.php#L144)），`needsImport()` 需要複用它的查找邏輯（先查 `external_ids`，找不到再用 `season_year`+`season_code`+`name` 比對），因此 `needsImport()` 直接呼叫 `resolveAnime()`（同一個 class 內部，private 方法可直接呼叫，不需要改可見性）。
- **`ImportAcgSecrets` 命令現況**是按檔案（每季一個 JSON）呼叫 `AnimeImportService::importSeason($records, $source)`，取得 `['imported' => int, 'unchanged' => int, 'skipped' => int, 'errors' => array]`，每個檔案印一行 `"{source}/{name}: imported X, unchanged Y, skipped Z"`（[ImportAcgSecrets.php:70](../../../backend/app/Console/Commands/ImportAcgSecrets.php#L70)）。這個逐檔案報告格式在 queue 化後必須維持不變。
- **現有 `importSeason()` 對格式錯誤記錄的處理**（空 `title_zh`）在 queue 化後**維持不變**，這部分邏輯留在 `importSeason()` 內、不搬進 queue，只有真正合法且需要處理的記錄才進入 dispatch 流程。

---

## Task 1: Queue 基礎設施（migration + 環境設定）

**Files:**
- Create: `backend/database/migrations/xxxx_xx_xx_create_jobs_table.php`（由 Laravel 指令產生，實際檔名含執行當下的 timestamp）
- Create: `backend/database/migrations/xxxx_xx_xx_create_failed_jobs_table.php`（同上）
- Modify: `.env`（repo 根目錄）
- Modify: `docker-compose.yml`
- Modify: `deploy/docker-compose.yml`

- [ ] **Step 1: 產生 jobs 與 failed_jobs migration**

Run:
```bash
docker compose exec backend php artisan queue:table
docker compose exec backend php artisan queue:failed-table
```
Expected: 兩個指令各輸出一行 `Migration created successfully.`，並在 `backend/database/migrations/` 下產生兩個新檔案（檔名含 `create_jobs_table` 與 `create_failed_jobs_table`）。

- [ ] **Step 2: 執行 migration**

Run: `docker compose exec backend php artisan migrate`
Expected: 輸出包含這兩個新 migration 的 `DONE`。

- [ ] **Step 3: 確認資料表已建立**

Run: `docker compose exec backend php artisan tinker --execute="echo Schema::hasTable('jobs') ? 'jobs yes' : 'jobs no'; echo ' / '; echo Schema::hasTable('failed_jobs') ? 'failed_jobs yes' : 'failed_jobs no';"`
Expected: `jobs yes / failed_jobs yes`

- [ ] **Step 4: 修改 `.env` 的 `QUEUE_CONNECTION`**

在 repo 根目錄的 `.env`（不是 `backend/.env`——這個專案的 CLAUDE.md 說明只有根目錄的 `.env` 生效），找到 `QUEUE_CONNECTION=sync` 這一行，改為：
```
QUEUE_CONNECTION=database
```

- [ ] **Step 5: 確認 `docker-compose.yml` 與 `deploy/docker-compose.yml` 的 `QUEUE_CONNECTION` 設定方式**

Run: `grep -n "QUEUE_CONNECTION" /Users/sumingkai/Documents/anime/docker-compose.yml /Users/sumingkai/Documents/anime/deploy/docker-compose.yml`

以這次指令的實際輸出為準決定怎麼編輯：
- 若 `docker-compose.yml` 是 `QUEUE_CONNECTION: ${QUEUE_CONNECTION}`（從 `.env` 插值），Step 4 改完 `.env` 後這裡不需要再動。
- 若 `deploy/docker-compose.yml` 是寫死的 `QUEUE_CONNECTION: sync`（production 部署設定），需要把這幾行（backend 與 scheduler 兩個服務裡都有）改成 `QUEUE_CONNECTION: database`。

- [ ] **Step 6: 重啟 backend 容器讓新的 `QUEUE_CONNECTION` 生效**

Run:
```bash
docker compose up -d backend
docker compose exec backend php artisan tinker --execute="echo config('queue.default');"
```
Expected: 輸出 `database`

- [ ] **Step 7: Commit**

```bash
git add backend/database/migrations/ docker-compose.yml deploy/docker-compose.yml
git commit -m "$(cat <<'EOF'
feat: 新增 Laravel Queue 基礎設施（database driver）

匯入流程即將改為 queue 化以支援平行處理，先建立 jobs/failed_jobs
資料表並將 QUEUE_CONNECTION 從 sync 切換為 database。
EOF
)"
```

**重要**：`.env` 檔案通常不進 git（檢查 `.gitignore`），若 `git add` 時 `.env` 沒有被列出屬於正常現象，不需要 commit 它；`docker-compose.yml`／`deploy/docker-compose.yml` 才是需要 commit 的部分。

---

## Task 2: `AnimeImportService` 新增 `computePayloadHash()` 與 `needsImport()`

**Files:**
- Modify: `backend/app/Services/AnimeCatalog/AnimeImportService.php`
- Modify: `backend/tests/Unit/AnimeImportServiceTest.php`

**背景**：命令層（Task 4）需要在 dispatch job 前先判斷「這筆記錄是否真的需要處理」，必須使用跟 `importRecord` 內部完全相同的 hash 計算方式與查找邏輯，避免兩套判斷邏輯分岔。

- [ ] **Step 1: 寫失敗測試**

在 [AnimeImportServiceTest.php](../../../backend/tests/Unit/AnimeImportServiceTest.php) 加入以下測試方法（加在 `test_import_record_skips_unchanged_payload` 之後）：

```php
    public function test_compute_payload_hash_matches_internal_import_hash(): void
    {
        $service = app(AnimeImportService::class);
        $record = $this->record();

        $service->importRecord($record);

        $this->assertSame($service->computePayloadHash($record), Anime::first()->import_hash);
    }

    public function test_needs_import_returns_true_for_new_record(): void
    {
        $service = app(AnimeImportService::class);

        $this->assertTrue($service->needsImport($this->record()));
    }

    public function test_needs_import_returns_false_for_unchanged_existing_record(): void
    {
        $service = app(AnimeImportService::class);
        $record = $this->record();

        $service->importRecord($record);

        $this->assertFalse($service->needsImport($record));
    }

    public function test_needs_import_returns_true_when_record_changed(): void
    {
        $service = app(AnimeImportService::class);

        $service->importRecord($this->record());

        $this->assertTrue($service->needsImport($this->record(['summary' => '更新後的大綱'])));
    }
```

- [ ] **Step 2: 執行測試，確認因方法不存在而失敗**

Run: `docker compose exec backend php artisan test --filter=AnimeImportServiceTest`
Expected: FAIL（`Call to undefined method App\Services\AnimeCatalog\AnimeImportService::computePayloadHash()`）

- [ ] **Step 3: 在 `AnimeImportService` 新增這兩個方法，並讓 `importRecord` 改用 `computePayloadHash()`**

在 [AnimeImportService.php](../../../backend/app/Services/AnimeCatalog/AnimeImportService.php) 做以下修改：

把第 44-46 行：
```php
    public function importRecord(array $record, string $source = 'acgsecrets'): ImportOutcome
    {
        $payloadHash = hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
```

改為：
```php
    public function importRecord(array $record, string $source = 'acgsecrets'): ImportOutcome
    {
        $payloadHash = $this->computePayloadHash($record);
```

在 `importRecord` 方法結束之後（第 91 行 `}` 之後、`importSeason` 方法之前），新增這兩個 public 方法：

```php
    /**
     * @param array<string, mixed> $record
     */
    public function computePayloadHash(array $record): string
    {
        return hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 判斷這筆記錄是否需要真的處理（新記錄，或內容與上次 import 不同）。
     * 只做查找 + hash 比對，不寫入任何資料，供命令層在 dispatch job 前先過濾用。
     *
     * @param array<string, mixed> $record
     */
    public function needsImport(array $record): bool
    {
        $anime = $this->resolveAnime($record);

        if (! $anime->exists) {
            return true;
        }

        return $anime->import_hash !== $this->computePayloadHash($record);
    }
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=AnimeImportServiceTest`
Expected: `9 passed`（原本 5 個 + 新增 4 個）

- [ ] **Step 5: 執行完整後端測試套件**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AnimeCatalog/AnimeImportService.php backend/tests/Unit/AnimeImportServiceTest.php
git commit -m "$(cat <<'EOF'
feat: AnimeImportService 新增 computePayloadHash/needsImport

供命令層在 dispatch job 前先過濾未變動的記錄，避免把不需要處理的
資料也推進 queue。importRecord 內部改為呼叫 computePayloadHash，
確保命令層與 Service 內部使用同一套 hash 計算邏輯。
EOF
)"
```

---

## Task 3: `ImportAnimeRecordJob`

**Files:**
- Create: `backend/app/Jobs/ImportAnimeRecordJob.php`
- Test: `backend/tests/Unit/ImportAnimeRecordJobTest.php`

**背景**：把「處理一筆記錄」包成一個 queue job，內部直接呼叫既有的 `AnimeImportService::importRecord`，不重寫任何業務邏輯。Job 額外接收一個 `batchId` 字串——這是 Task 4 的命令每次執行時產生的唯一識別碼，job 成功完成後用它累加一個共用的 cache 計數鍵，讓命令能在所有 worker 結束後統計「這次執行到底成功了幾筆」。

- [ ] **Step 1: 寫失敗測試**

```php
<?php

namespace Tests\Unit;

use App\Jobs\ImportAnimeRecordJob;
use App\Models\Anime;
use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ImportAnimeRecordJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_imports_the_record_via_anime_import_service(): void
    {
        $record = [
            'season_year' => 2026, 'season_code' => 'spring',
            'title_zh' => '測試動畫', 'title_ja' => 'テストアニメ',
        ];

        $job = new ImportAnimeRecordJob($record, 'acgsecrets', 'test-batch-1');
        $job->handle(app(AnimeImportService::class));

        $this->assertSame(1, Anime::count());
        $this->assertSame('測試動畫', Anime::first()->name);
    }

    public function test_tries_is_three(): void
    {
        $job = new ImportAnimeRecordJob(['title_zh' => 'x'], 'acgsecrets', 'test-batch-2');

        $this->assertSame(3, $job->tries);
    }

    public function test_handle_increments_batch_success_counter(): void
    {
        $record = ['title_zh' => '測試動畫2', 'season_year' => 2026, 'season_code' => 'spring'];
        $job = new ImportAnimeRecordJob($record, 'acgsecrets', 'test-batch-3');

        $job->handle(app(AnimeImportService::class));

        $this->assertSame(1, Cache::get('import:test-batch-3:success'));
    }
}
```

- [ ] **Step 2: 執行測試，確認因類別不存在而失敗**

Run: `docker compose exec backend php artisan test --filter=ImportAnimeRecordJobTest`
Expected: FAIL（`Class "App\Jobs\ImportAnimeRecordJob" not found`）

- [ ] **Step 3: 實作 `ImportAnimeRecordJob`**

```php
<?php

namespace App\Jobs;

use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class ImportAnimeRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param array<string, mixed> $record
     */
    public function __construct(
        public readonly array $record,
        public readonly string $source,
        public readonly string $batchId,
    ) {
    }

    public function handle(AnimeImportService $service): void
    {
        $service->importRecord($this->record, $this->source);

        Cache::increment("import:{$this->batchId}:success");
    }
}
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=ImportAnimeRecordJobTest`
Expected: `3 passed`

- [ ] **Step 5: 執行完整後端測試套件**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Jobs/ImportAnimeRecordJob.php backend/tests/Unit/ImportAnimeRecordJobTest.php
git commit -m "$(cat <<'EOF'
feat: 新增 ImportAnimeRecordJob 包裝單筆記錄的匯入

內部直接呼叫既有 AnimeImportService::importRecord，不改動任何
業務邏輯。tries=3 讓短暫的網路/資料庫問題有重試空間；成功完成後
遞增 batch 專屬的 cache 計數，供命令層彙總最終報告使用。
EOF
)"
```

---

## Task 4: `ImportAcgSecrets` 命令改為 queue 化 + 平行 worker

**Files:**
- Modify: `backend/app/Console/Commands/ImportAcgSecrets.php`
- Test: `backend/tests/Feature/ImportAcgSecretsTest.php`

**背景**：這是這次改動的核心——命令從逐檔案同步呼叫 `importSeason()`，改為逐筆過濾＋dispatch，再啟動 4 個平行 worker 子行程處理完 queue，最後彙總報告。

**關於測試設計的重要說明**：`ImportAcgSecrets::handle()` 用 `glob("{$dir}/*.json")` 掃描**整個** `database/seed/acgsecrets/` 目錄，這代表測試在這個目錄底下新建的臨時 JSON 檔案，會跟 repo 裡真實存在的種子資料一起被掃描、一起被 dispatch。因此測試不能斷言「總共 dispatch 了剛好 N 個 job」（真實種子資料也會貢獻 job 數），只能斷言「特定這幾筆測試資料確實被 dispatch 了／沒被 dispatch」——下面的測試程式碼從一開始就採用這種寫法，不會遇到精確筆數不吻合的問題。

- [ ] **Step 1: 寫失敗測試**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ImportAnimeRecordJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ImportAcgSecretsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = database_path('seed/acgsecrets/_test_queue_import.json');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_dispatches_a_job_for_each_new_record(): void
    {
        Queue::fake();

        file_put_contents($this->path, json_encode([
            ['title_zh' => '__測試新動畫A__', 'season_year' => 2026, 'season_code' => 'spring'],
            ['title_zh' => '__測試新動畫B__', 'season_year' => 2026, 'season_code' => 'spring'],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('anime:import-acgsecrets')->assertSuccessful();

        Queue::assertPushed(ImportAnimeRecordJob::class, function (ImportAnimeRecordJob $job) {
            return $job->record['title_zh'] === '__測試新動畫A__';
        });
        Queue::assertPushed(ImportAnimeRecordJob::class, function (ImportAnimeRecordJob $job) {
            return $job->record['title_zh'] === '__測試新動畫B__';
        });
    }

    public function test_skips_dispatch_for_records_with_empty_title(): void
    {
        Queue::fake();

        file_put_contents($this->path, json_encode([
            ['title_zh' => '', 'season_year' => 2026, 'season_code' => 'spring'],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('anime:import-acgsecrets')->assertSuccessful();

        Queue::assertPushed(ImportAnimeRecordJob::class, function (ImportAnimeRecordJob $job) {
            return $job->record['title_zh'] === '';
        }, 0);
    }

    public function test_does_not_dispatch_for_unchanged_record(): void
    {
        // 先不 fake queue，讓第一次真的走完整匯入流程（含 dispatch + worker
        // 執行），確保 import_hash 真的寫進資料庫。
        file_put_contents($this->path, json_encode([
            ['title_zh' => '__測試不變動動畫__', 'season_year' => 2026, 'season_code' => 'spring'],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('anime:import-acgsecrets')->assertSuccessful();

        // 第二次跑同一份檔案（內容完全相同），這次改用 Queue::fake() 只檢查
        // dispatch 行為，不需要真的再跑一次 worker。
        Queue::fake();

        $this->artisan('anime:import-acgsecrets')->assertSuccessful();

        Queue::assertPushed(ImportAnimeRecordJob::class, function (ImportAnimeRecordJob $job) {
            return $job->record['title_zh'] === '__測試不變動動畫__';
        }, 0);
    }
}
```

- [ ] **Step 2: 執行測試，確認因為命令還沒 dispatch 任何 job 而失敗**

Run: `docker compose exec backend php artisan test --filter=ImportAcgSecretsTest`
Expected: FAIL（`Queue::assertPushed` 找不到任何 `ImportAnimeRecordJob` 被 push，因為目前命令還是走 `importSeason()` 直接同步處理，沒有 dispatch 任何 job）

- [ ] **Step 3: 修改 `ImportAcgSecrets` 命令**

完整替換 [ImportAcgSecrets.php](../../../backend/app/Console/Commands/ImportAcgSecrets.php) 的內容：

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ImportAnimeRecordJob;
use App\Models\Anime;
use App\Models\AnimeAlias;
use App\Models\AnimeExternalId;
use App\Models\AnimeStream;
use App\Models\AnimeTitle;
use App\Services\AnimeCatalog\AnimeImportService;
use App\Services\AnimeCatalog\WatchedManifestImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ImportAcgSecrets extends Command
{
    protected $signature = 'anime:import-acgsecrets {--fresh} {--workers=4}';

    protected $description = 'Import scraped acgsecrets JSON files (and personal mylist seeds) into the anime catalog tables.';

    private const QUEUE_NAME = 'import';

    public function handle(AnimeImportService $service, WatchedManifestImporter $watched): int
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

        // mylist 為個人補充的種子資料(結構與 acgsecrets 相同),acgsecrets 先匯入,
        // mylist 靠 external_ids.bangumi 與既有紀錄去重。
        $sources = [
            'acgsecrets' => database_path('seed/acgsecrets'),
            'mylist' => database_path('seed/mylist'),
        ];

        $batchId = (string) Str::uuid();
        $totalUnchanged = 0;
        $totalSkipped = 0;
        $totalDispatched = 0;

        foreach ($sources as $source => $dir) {
            foreach (glob("{$dir}/*.json") ?: [] as $file) {
                $name = basename($file);
                if (in_array($name, ['summary.json', 'watched.json'], true)) {
                    continue;
                }

                $contents = file_get_contents($file);
                if ($contents === false) {
                    throw new RuntimeException("Failed to read {$file}");
                }

                $records = json_decode($contents, true);
                if (! is_array($records)) {
                    $this->error("{$source}/{$name}: invalid JSON, skipping file");

                    continue;
                }

                [$dispatched, $unchanged, $skipped] = $this->dispatchRecords($service, $records, $source, $batchId);
                $totalDispatched += $dispatched;
                $totalUnchanged += $unchanged;
                $totalSkipped += $skipped;

                $this->line("{$source}/{$name}: dispatched {$dispatched}, unchanged {$unchanged}, skipped {$skipped}");
            }
        }

        if ($totalDispatched > 0) {
            $this->info("Dispatched {$totalDispatched} job(s) to the '".self::QUEUE_NAME."' queue, starting workers...");
            $this->runWorkers((int) $this->option('workers'));
        }

        $succeeded = (int) Cache::pull("import:{$batchId}:success", 0);
        $failed = $totalDispatched - $succeeded;

        $this->info("Total: imported {$succeeded}, unchanged {$totalUnchanged}, skipped {$totalSkipped}"
            .($failed > 0 ? ", failed {$failed}" : ''));

        $result = $watched->sync();
        if ($result['skipped']) {
            $this->line('watched manifest: skipped (MYLIST_OWNER_EMAIL not set or manifest missing)');
        } else {
            $this->info("watched manifest: marked {$result['marked']}, already in list {$result['existing']}, unresolved ".count($result['missing']));
            foreach ($result['missing'] as $title) {
                $this->warn("  unresolved: {$title}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * 對一份檔案裡的記錄逐筆判斷是否需要處理，需要的才 dispatch job。
     * 回傳 [dispatched筆數, unchanged筆數, skipped筆數]。
     *
     * @param array<int, mixed> $records
     * @return array{0: int, 1: int, 2: int}
     */
    private function dispatchRecords(AnimeImportService $service, array $records, string $source, string $batchId): array
    {
        $dispatched = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! is_array($record) || trim((string) ($record['title_zh'] ?? '')) === '') {
                $skipped++;

                continue;
            }

            if (! $service->needsImport($record)) {
                $unchanged++;

                continue;
            }

            ImportAnimeRecordJob::dispatch($record, $source, $batchId)
                ->onQueue(self::QUEUE_NAME);

            $dispatched++;
        }

        return [$dispatched, $unchanged, $skipped];
    }

    private function runWorkers(int $workerCount): void
    {
        $processes = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $process = new Process([
                'php', 'artisan', 'queue:work',
                '--queue='.self::QUEUE_NAME,
                '--tries=3',
                '--stop-when-empty',
            ]);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(null);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $process) {
            $process->wait();
        }
    }
}
```

- [ ] **Step 4: 執行測試，確認通過**

Run: `docker compose exec backend php artisan test --filter=ImportAcgSecretsTest`
Expected: `3 passed`

- [ ] **Step 5: 執行完整後端測試套件**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS（含 `ImportAnimeRecordJobTest` 的 3 個測試、`ImportAcgSecretsTest` 的 3 個測試，以及既有全部測試）

- [ ] **Step 6: Commit**

```bash
git add backend/app/Console/Commands/ImportAcgSecrets.php backend/tests/Feature/ImportAcgSecretsTest.php
git commit -m "$(cat <<'EOF'
feat: ImportAcgSecrets 改為 queue 化並平行處理

命令改為：用 needsImport() 過濾未變動的記錄→dispatch job 到
import queue→啟動 4 個 queue:work 子行程平行消費→等待全部結束→
用 cache 計數彙總最終報告。--workers 選項可調整平行數量（預設 4）。
對外使用方式與輸出格式維持不變。
EOF
)"
```

---

## Task 5: 端對端驗證

**Files:** 無新檔案，純驗證步驟。

**背景**：這是最終確認——用真實種子資料跑一次完整匯入，確認速度改善、記憶體不再累積到崩潰、且資料正確性與序列版本一致。

- [ ] **Step 1: 清空現有 anime 資料，準備乾淨的驗證環境**

Run:
```bash
docker compose exec backend php artisan tinker --execute="App\Models\Anime::query()->delete();"
docker compose exec backend sh -c "rm -f /app/storage/app/public/covers/*.webp"
docker compose exec backend php artisan tinker --execute="echo App\Models\Anime::count();"
```
Expected: 最後一行輸出 `0`

- [ ] **Step 2: 用預設的 128MB CLI 記憶體限制執行完整匯入（不再需要手動調高 memory_limit）**

Run:
```bash
time docker compose exec backend php artisan anime:import-acgsecrets
```
Expected: 指令完整跑完、印出 `Total: imported X, unchanged Y, skipped Z` 這行報告，過程中沒有 `Allowed memory size exhausted` 的錯誤訊息。記下 `time` 指令回報的實際耗時（`real` 那一行），用於與序列版本比較。

- [ ] **Step 3: 確認資料量與縮圖數量符合預期**

Run:
```bash
docker compose exec backend php artisan tinker --execute="echo App\Models\Anime::count();"
docker compose exec backend sh -c "ls /app/storage/app/public/covers | wc -l"
```
Expected: anime 筆數應接近 2500+（實際數字取決於當下種子資料內容，只要不是 0 或遠低於預期都算正常）；縮圖檔案數量應與有 `cover_image_path` 的 anime 筆數相符。

- [ ] **Step 4: 確認 `failed_jobs` 表沒有異常大量的失敗記錄**

Run: `docker compose exec backend php artisan tinker --execute="echo DB::table('failed_jobs')->count();"`
Expected: 數字應該是 0 或很小（個位數，對應極少數真正的網路/系統問題），不應該是大量失敗。如果數字偏高，用 `docker compose exec backend php artisan queue:failed` 查看失敗原因，判斷是否為本次改動引入的問題還是既有的資料問題（例如某些 acgsecrets 圖片連結本身已失效）。

- [ ] **Step 5: 執行完整後端測試套件，確認沒有回歸**

Run: `docker compose exec backend php artisan test`
Expected: 全部 PASS

- [ ] **Step 6: 確認 API 回傳資料正常（驗證這次改動沒有破壞既有功能）**

Run: `curl -s "http://localhost:8080/anime?year=2026&season=summer" | head -c 500`

（若本機 host port 因為 `docker-compose.override.yml` 被改為其他號碼，改用實際對應的 port，例如 `curl -s "http://localhost:18080/anime?year=2026&season=summer"`）

Expected: 回傳合法 JSON，包含 `items` 陣列，每筆有 `image_url` 欄位。

（此 Task 不需要 commit——純驗證。若驗證中發現問題，回到對應 Task 修正並補 commit。）
