# Queue 化匯入流程設計

## 背景與問題

`anime:import-acgsecrets` 目前逐筆同步處理 acgsecrets JSON 種子資料（目前 2535 筆記錄），每筆呼叫 `AnimeImportService::importRecord()`——寫入 anime 基本欄位、下載原圖並產生縮圖（[ThumbnailService](../../../backend/app/Services/AnimeCatalog/ThumbnailService.php)，2026-07-03 縮圖 pipeline 上線後新增的步驟）、同步 8 張關聯表（標題、別名、外部 ID、主題曲、預告片、卡司、工作人員、連結）。

這造成兩個問題：

1. **速度慢**：每筆下載原圖是同步阻塞的網路 I/O（~0.2-0.5 秒/筆），2535 筆全部序列執行，網路等待時間無法重疊，整體耗時隨資料量線性增長。
2. **記憶體隨執行時間持續累積，長跑會耗盡 CLI `memory_limit`（128MB）**：實測以 `memory_limit=200M` 重跑同一個匯入流程，處理到 1800+ 筆仍在穩定增長（未崩潰），對照原本 128MB 限制下固定在 700~800 筆附近崩潰（`Allowed memory size of 134217728 bytes exhausted`，錯誤發生於 `guzzlehttp/psr7` 的 `stream_get_contents`）。已用獨立重現腳本排除 `ThumbnailService::generate()` 單一呼叫本身有洩漏（20 次迴圈記憶體穩定在 22-24MB）；增長來源判定為 Artisan 長跑進程 + Eloquent + Guzzle 多筆疊加的典型模式，不是本次縮圖功能引入的新缺陷，只是縮圖下載這個步驟第一次讓單筆處理時間/資源顯著上升，使得原本可能存在但無感的緩慢累積第一次被完整跑一遍 2535 筆時真正撞上上限。

## 目標

- 用 Laravel Queue 把「處理一筆 anime 記錄」拆成獨立 job，多個 worker process 並行消費，讓網路下載等待時間重疊，加快整體匯入速度。
- 每個 worker 是獨立 PHP process，處理完一批 job 後可結束/重啟，不再有單一長跑進程的記憶體累積問題。
- `anime:import-acgsecrets` 這個既有指令的**使用方式不變**（同一個指令、同樣的參數、結束時印出同樣格式的總計報告），只有內部實作改變。
- 每週排程（[routes/console.php](../../../backend/routes/console.php)）不需要新增排程項目，`scheduler` 容器現有的排程呼叫 `anime:import-acgsecrets` 時，新行為（推 queue → 4 個 worker 平行跑 → 彙總）在同一次指令執行內完整發生。
- 不引入 Redis 或其他新基礎設施——用 Laravel Queue 的 `database` driver，複用既有 MySQL。

## 架構

```
anime:import-acgsecrets（既有指令，改動）
  ├─ 讀 JSON 種子檔案（不變，維持現有 glob + json_decode 邏輯）
  ├─ 對每筆記錄，在命令層計算 payload hash 並比對 anime.import_hash
  │   （複用 AnimeImportService::importRecord 內部同一套 hash 邏輯，
  │    抽成可從命令層呼叫的判斷，只讀不寫）
  │  → hash 相同（unchanged）→ 不 dispatch，直接計入 unchanged 計數
  │  → hash 不同或是新記錄 → dispatch(new ImportAnimeRecordJob($record, $source))
  ├─ 全部檔案處理完、job 都推進 database queue 的 jobs 表後：
  │   啟動 4 個 `queue:work --queue=import --tries=3 --stop-when-empty` 子行程
  │   （用 Symfony Process 平行啟動，命令本身等待全部子行程結束）
  └─ 彙總：查詢這次執行新建立的 job 對應結果（成功筆數、failed_jobs 筆數），
      印出跟現在格式一致的 "Total: imported X, unchanged Y, skipped Z" 報告

ImportAnimeRecordJob（新類別，implements ShouldQueue）
  ├─ 建構子：public readonly array $record, string $source
  ├─ public int $tries = 3;
  ├─ handle(AnimeImportService $service): 直接呼叫 $service->importRecord($this->record, $this->source)
  │   （AnimeImportService 內部邏輯完全不變，包含 import_hash 二次比對——
  │    命令層的比對是為了避免不必要的 job 推入 queue，
  │    Service 內部的比對是保留給 job 重試/未來直接呼叫時的保護，兩者不衝突）
  └─ 失敗（拋出例外，如 DB 連線斷）→ Laravel Queue 自動重試最多 3 次，
      3 次都失敗則落入 failed_jobs 表，不影響其他 job 繼續處理

queue.php config（Laravel 內建，已存在於專案）
  └─ 'default' => env('QUEUE_CONNECTION', 'database') 已支援，只需要
      .env / docker-compose.yml 的 QUEUE_CONNECTION 改成 database

jobs / failed_jobs 資料表（新 migration，用 Laravel 內建產生器）
  └─ php artisan queue:table && php artisan queue:failed-table 產生的標準結構
```

## 為什麼「命令層先過濾、只推需要處理的 job」

`AnimeImportService::importRecord` 現有邏輯是：若 `anime.exists && anime.import_hash === $payloadHash` 則直接回傳 `wasUnchanged: true`，不做任何寫入（[AnimeImportService.php:47-49](../../../backend/app/Services/AnimeCatalog/AnimeImportService.php#L47-L49)）。多數週期性排程執行時，大部分記錄沒有變動——每週排程 2535 筆裡通常只有幾十筆是真正的新番/更新。

若不在命令層過濾、把全部 2535 筆都無腦推進 queue，即使 worker 處理每個「unchanged」job 都很快（只有一次 SQL 查詢+比對），也會讓 `jobs` 表在每次排程時瞬間新增數千列又清空，且 4 個 worker 仍要逐一撈出這些注定被跳過的 job，浪費 queue 系統的吞吐量。命令層先用只讀查詢過濾一次，只有「hash 不同或全新記錄」才 dispatch，讓 queue 只承載真正需要處理（含縮圖下載）的工作量，這是效率考量，不是邏輯上的必要。

## 資料流細節

- 命令層的 hash 比對不能重寫一份新邏輯，必須跟 `AnimeImportService::importRecord` 內部完全一致的 hash 計算方式（`hash('sha256', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))`），否則會出現「命令層判斷不同、但 Service 層判斷相同」的不一致。做法是把這個 hash 計算抽成 `AnimeImportService` 上的一個小型 public 方法（例如 `computePayloadHash(array $record): string`），命令層與 Service 內部都呼叫同一個方法。
- 命令層的「這筆是否已存在且 hash 相同」判斷，需要能找到對應的既有 anime 記錄——這部分邏輯目前在 `AnimeImportService::resolveAnime()`（private）。同樣需要把「查找＋比對 hash」這個檢查抽成一個 public 方法（例如 `needsImport(array $record): bool`），內部複用現有的 `resolveAnime` 查找邏輯，避免在命令層重寫一份查找規則造成兩套邏輯分岔。
- `queue:work --stop-when-empty` 讓每個 worker 子行程在清空 `import` queue 後自然結束，不會變成常駐 process，符合「這個 worker 機制不常用、不需要一直空轉」的判斷。
- 4 個 worker 子行程用 Symfony Process（Laravel 已內建依賴）平行啟動，`anime:import-acgsecrets` 本身等待這 4 個子行程全部結束後才繼續往下彙總並結束——這保留了「指令執行完畢＝匯入真的跑完了」的現有行為，不需要額外的狀態查詢方式。
- 彙總報告的「imported」「skipped」計數：`skipped`（原本指格式錯誤、空標題等情況）維持在命令層過濾階段就判斷（不需要進 queue 才能發現標題是空的）。`imported` 計數用一個每次執行都唯一的 cache key 累加：命令產生一個執行專屬的 batch id（例如 `Str::uuid()`），連同 record 一起傳給 `ImportAnimeRecordJob`；job 在 `handle()` 成功完成後執行 `Cache::increment("import:{$batchId}:success")`。命令層在全部 worker 結束後讀取這個 cache key 的值作為 `imported` 計數，讀取後刪除該 key。`failed_jobs` 表裡屬於這次 batch id 的筆數（可在 job 序列化資料裡帶上 batch id 以便篩選）即為失敗筆數，兩者相加應等於 dispatch 出去的 job 總數，可作為完整性檢查。

## 部署改動

- `.env` / `docker-compose.yml` / `deploy/docker-compose.yml`：`QUEUE_CONNECTION` 從 `sync` 改為 `database`。
- 新 migration：`php artisan queue:table`、`php artisan queue:failed-table` 產生的標準 `jobs`、`failed_jobs`、`job_batches`（如有）資料表。
- `scheduler` 容器：不需要新增排程項目或改變 entrypoint——`routes/console.php` 現有的 `Schedule::command('anime:scrape-acgsecrets')->then(...)` 鏈裡呼叫的 `anime:import-acgsecrets`，其內部行為改變（推 queue + 啟動 worker 子行程 + 等待）已經封裝在指令內部，排程呼叫方式不變。
- **不需要新增常駐 queue-worker 容器**——worker 子行程由 `anime:import-acgsecrets` 指令自己用 Symfony Process 啟動與等待，隨指令的生命週期存在，指令結束時 worker 也已經跑完退出。

## 失敗處理策略

| 情境 | 行為 |
|---|---|
| 單筆縮圖下載失敗 | 不算 job 失敗——`ThumbnailService::generate()` 內部已 catch 例外並回傳 null，`importRecord` 正常完成，只是 `cover_image_path` 維持 null（既有行為，這次不變） |
| Job 真正拋出例外（DB 連線斷、queue 系統本身問題） | Laravel Queue 自動重試，最多 3 次；3 次都失敗記錄進 `failed_jobs` 表，不影響其他 job 繼續處理 |
| 命令層 hash 比對階段記錄格式錯誤（空 `title_zh` 等） | 維持現狀，在過濾階段就計入 `skipped`，不 dispatch |
| 4 個 worker 子行程其中之一意外中止（例如被 kill） | `anime:import-acgsecrets` 等待邏輯需要偵測子行程異常結束（非 0 exit code），在最終報告中標註警告，但不因此讓整個指令失敗——其餘 worker 與已完成的 job 不受影響，之後重跑指令會因為 import_hash 尚未更新而重新處理未完成的部分 |

## 測試計畫

- `ImportAnimeRecordJobTest`：單元測試，驗證 job 呼叫 `AnimeImportService::importRecord` 並傳入正確參數；驗證 `$tries = 3`。
- `AnimeImportServiceTest`：新增測試涵蓋 `computePayloadHash()`、`needsImport()` 這兩個新抽出的 public 方法，確保與既有 `importRecord` 內部行為一致。
- `ImportAcgSecretsTest`（新的 Feature 測試，或擴充現有測試）：用 `Queue::fake()` 驗證命令對「需要處理」的記錄正確 dispatch job、對「unchanged」記錄不 dispatch；用小量假資料驗證端對端跑完（含實際啟動 worker 子行程）能正確彙總報告數字。
- 手動驗證：清空測試用資料库，跑一次真實的 `anime:import-acgsecrets`，比對總耗時是否相較序列版本明顯縮短，並確認 `memory_limit=128M`（預設值，不再需要手動調高）下能完整跑完不崩潰。

## 範圍外（Out of scope）

- `anime:generate-thumbnails`（既有 backfill 命令）不在這次 queue 化範圍內——它本身只做下載+縮圖（無多表寫入），單筆處理成本低很多，暫不需要並行化；若未來也需要加速，屬於獨立的後續優化。
- 不引入 Redis 或其他 queue driver，只用 Laravel 內建的 `database` driver。
- 不新增常駐 queue-worker 容器。
- 不改變 `AnimeImportService::importRecord` 內部的業務邏輯（縮圖產生、關聯表同步等），只新增兩個小型 public 輔助方法（`computePayloadHash`、`needsImport`）供命令層呼叫。
