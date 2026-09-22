# 我的清單效能

`/list` 的首屏只等待目前分頁的清單項目；觀看數、收藏分類與標籤會在首屏
渲染後以非阻塞請求補上。這讓篩選與分頁的核心內容不會受輔助資料影響。

清單列的動畫連結停用 Nuxt 預取，避免一個 50 筆頁面同時發出 50 個詳情頁
`_payload.json` 請求。封面則由共享的 IntersectionObserver 在進入視窗附近後才
設定 `src`，只讓可見內容競爭網路頻寬。

資料庫以 `user_id` 為起點的複合索引支援「最近加入」排序與觀看狀態篩選：
`(user_id, created_at, id)` 與 `(user_id, watched, id)`。部署時須執行 Laravel
migration，才能取得索引的查詢效益。
