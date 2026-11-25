# AEO 與 SEO 維護指南

動漫庫以 SEO 作為可被搜尋的基礎，再用 AEO（Answer Engine Optimization）提高內容被 AI
答案引擎正確理解、引用與推薦的機會。AEO 不保證曝光或引用；所有調整仍以可驗證的網站內容、
可爬取性與清楚的實體關係為主。

## 目前實作

- Nuxt SSR 直接輸出首頁、季度列表、資料庫列表與作品詳情內容；季度列表的完整作品清單會先出現在 HTML，
  瀏覽器端再以虛擬列表呈現，避免搜尋爬蟲只讀到首屏少量作品。季度前後頁以一般連結互相連接，讓搜尋爬蟲
  能沿站內連結發現歷史季度。
- 公開收藏分享頁由 SSR 載入集合與作品評分，並以作品題材標籤提供前端分類篩選；多選分類採符合任一分類。
- 首頁、季度與資料庫頁會依 query 顯示不同資料。保留 SSR HTML 快取，但停用 Nuxt payload 抽取，避免
  不含原始 query 的 payload URL 將另一個年份、季度或篩選結果套到目前頁面。
- sitemap 只收錄已有作品的季度（含下一年度，新季度片單一公布即可被發現），季度 `lastmod` 取該季作品
  最新的更新時間；沒有作品的季度頁輸出 `noindex, follow`。API 暫時失敗時不輸出 noindex，避免誤讓
  搜尋引擎移除頁面。sitemap 另收錄具獨立閱讀價值的作品頁，並輸出 `index, follow`：故事介紹至少 160 個非空白字元；或介紹至少
  60 字，且「有集數」「有播出平台」「至少 3 位聲優」三項中符合兩項（這些是頁面上可查核的第一方資料）。
  詳情頁與 sitemap 共用同一規則。其他作品詳情仍可正常瀏覽，但會輸出 `noindex, follow`，避免
  大量薄內容頁分散網站的索引訊號。作品 `lastmod` 使用資料實際更新時間，舊資料才以播出日補位。
- 作品詳情頁只輸出一個 `<h1>`（手機版標題，位於 HTML 前段）；桌機版標題以 `role="heading" aria-level="1"`
  保留輔助技術語意，避免重複 h1 混淆頁面主體。
- 首頁以 JSON-LD 定義「動漫庫 / Anime Library」的 `Organization` 與 `WebSite` 實體。
- 季度與資料庫頁以 `CollectionPage`、`ItemList` JSON-LD 描述列表；資料庫頁另提供可見的問答式摘要。
- 季度頁鎖定「YYYY年M月新番」「X季新番」「動漫新番」等查詢：標題與描述同時寫出月份、季節名稱
  （例如「2026年10月新番表（秋季新番）共73部」），首頁標題帶入本季「動漫新番表」。上方維持精簡的
  篩選介面，作品列表**下方**才放「新番總覽」問答（有哪些、何時開播、每週幾播、在哪裡看），內容全部由
  `app/utils/seasonalSeo.ts` 從該季作品資料計算，並同步輸出 `FAQPage` JSON-LD；資料不足的問題會略過，
  不填推測內容。總覽下方連結同年度其他季度、前後年度同季（不超過下一季）與資料庫，作品頁的「播出季度」
  也連回該季新番表，強化站內連結。
- 作品頁先給可獨立閱讀的答案摘要，再提供故事、集數、平台、聲優及資料來源；JSON-LD 以
  `WebPage` 連結 `TVSeries`，並用 Bangumi、MyAnimeList URL 協助實體對應。
- 搜尋、分類篩選與第二頁以後的資料庫 URL 使用 `noindex, follow`，避免低價值參數頁分散索引。
- 不存在或無效的作品 ID 回傳 HTTP `404`，避免可被抓取的 soft 404 浪費索引資源。
- 登入頁允許爬蟲讀取 `noindex` 指令；私人清單與設定頁仍受登入保護、`noindex` 與 robots.txt 路徑規則約束。

## AI 爬蟲政策

`frontend/public/robots.txt` 將搜尋引用與模型訓練分開：

- 允許 `OAI-SearchBot`、`Claude-SearchBot`、`PerplexityBot` 讀取公開內容；登入頁由 `noindex` 排除，私人路徑不開放抓取。
- 封鎖訓練用途的 `GPTBot`、`ClaudeBot`、`CCBot`、`Applebot-Extended`、`meta-externalagent`、`Bytespider`、
  `cohere-training-data-crawler`。`Applebot-Extended` 只控制訓練，不影響 `Applebot` 的 Siri／Spotlight 搜尋；
  `Amazonbot` 同時服務 Alexa 等答案功能，暫不封鎖。
- 不封鎖一般 `Googlebot`，避免影響 Google Search 與 AI 搜尋介面。
- `Google-Extended` 暫時沿用一般規則。Google 將 Gemini 模型訓練與 Gemini grounding 放在同一控制項；
  若日後封鎖，會同時犧牲部分 Gemini 引用機會，修改前應先確認產品取捨。

爬蟲名稱與用途可能調整，至少每季核對一次
[OpenAI Crawlers](https://developers.openai.com/api/docs/bots)、
[Anthropic crawler 說明](https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler)、
[Perplexity Crawlers](https://docs.perplexity.ai/docs/resources/perplexity-crawlers) 與
[Google crawlers](https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers)。

## 內容維護原則

每次擴充動漫資料時，維持以下順序：

1. 第一段直接回答「這是什麼作品、何時播出、共幾集、在哪裡看」。
2. 每個段落即使離開上下文，仍要能辨識作品名稱與資料意義。
3. 優先提供可查核的第一方整理結果，例如收錄數、播出日期、平台及更新日期。
4. 不為了結構化資料捏造缺少的評分、評論、集數或播出狀態。
5. 品牌名稱固定使用「動漫庫」，英文別名固定使用「Anime Library」。
6. 來源資料繼續遵循 scrape → JSON snapshot → import 流程，不做單筆正式資料庫修改。
7. 欲讓新作品取得索引資格，優先補上可獨立閱讀的繁中故事介紹（至少 160 個非空白字元）；介紹較短時，
   須同時具備集數、播出平台、聲優中的兩項（見上方規則），而非只填別名、標籤或一句話摘要。

`llms.txt` 目前沒有主要答案引擎正式支援，因此不是優先項目。若未來平台官方文件開始採用，
再依實際支援範圍評估加入。

## 上線與量測

發布後先確認：

```bash
curl -fsSL https://anime.kaistarstudio.me/robots.txt
curl -fsSL https://anime.kaistarstudio.me/sitemap.xml
curl -fsSL https://anime.kaistarstudio.me/anime/<作品 ID>
```

接著建立每月基準：

- Google Search Console：索引頁數、品牌/作品查詢曝光、點擊與平均排名。
- Bing Webmaster Tools：索引狀態、搜尋曝光及可用的 AI 引用報表。
- 伺服器 access log：`OAI-SearchBot`、`Claude-SearchBot`、`PerplexityBot` 的成功回應與錯誤率。
- 固定 10 至 20 個真實問題，例如「本季有哪些奇幻新番」或「某作品在哪個平台看」，每月在各答案
  引擎人工檢查一次是否提及動漫庫、引用哪個 URL、答案是否正確。

比較調整前後至少四週的趨勢，不以單次 AI 回答判定成效。若答案內容錯誤，優先修正對應頁面的
可見文字與來源資料，再同步 JSON-LD；結構化資料不能與頁面內容不一致。
