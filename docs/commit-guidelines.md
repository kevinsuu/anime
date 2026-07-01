# Commit 訊息規範

本專案的 git commit 訊息使用**中文描述**，搭配英文 Conventional Commits 前綴。所有貢獻者（含 AI agent）建立 commit 前都應遵循本規範。

## 格式

```
<type>: <中文摘要，簡潔說明這次改了什麼>

<選填的中文詳細說明，解釋為什麼要這樣改、有什麼取捨或風險>
```

- **Subject（第一行）**：`type: 中文摘要`，摘要控制在約 50 字內，不加句號
- **Body（選填）**：空一行後接詳細說明，解釋動機（why）而非重複 diff 內容（what）。單行改動、意圖明顯的 commit 可以省略 body
- 若 commit 是多個相關改動的集合，body 用條列式列出涵蓋範圍

## Type 前綴（維持英文，方便工具辨識）

| Type | 用途 |
|---|---|
| `feat` | 新增功能 |
| `fix` | 修復 bug |
| `refactor` | 不改變外部行為的程式碼重構 |
| `chore` | 建置流程、依賴、設定檔等雜項調整 |
| `docs` | 純文件變更 |
| `test` | 新增或修改測試 |
| `style` | 純格式調整（不影響邏輯，如縮排、空白） |
| `perf` | 效能優化 |

## 範例

```
feat: 新增 refresh token 自動續發機制

Access token 一小時過期後，自動用 refresh token 換發新的，
不再讓使用者每小時被踢回登入頁。Refresh token 採輪替式設計，
每次使用後即撤銷並換發新的，降低外洩後被重放利用的風險。
```

```
fix: 修正收藏公開/私人切換無法設回私人的問題

原本 copyCollectionLink 只處理「設為公開」分支，
點擊已公開的收藏並不會真的切回私人，與 UI 提示的行為不符。
```

```
chore: 統一 docker-compose 環境變數至根目錄 .env
```

## 不要做的事

- 不要在 subject 或 body 混用中英文夾雜到難以閱讀的程度（技術詞彙、套件名稱、API 路徑等專有名詞維持原文即可，例如「新增 `POST /anime/refresh` 端點」是可以的）
- 不要把多個不相關主題的改動塞進同一個 commit——每個 commit 應該是一個可以獨立說明、獨立 revert 的單位
- 不要在 commit 訊息中出現任何機敏資訊（金鑰、密碼、內部網路位址、個人信箱等）——本倉庫為公開倉庫

## 給 AI agent 的指示

當你（Claude Code 或其他 AI coding agent）被要求建立 commit 時：

1. 依本文件格式撰寫中文 commit 訊息
2. 若改動橫跨多個不相關主題，優先拆成多個 commit，各自對應一個主題
3. Commit 前必須確認沒有機敏資訊被寫進 diff（`.env` 真實值、API 金鑰、個人信箱等）
4. 若不確定某段改動的動機（why），應詢問使用者而非憑空杜撰理由
