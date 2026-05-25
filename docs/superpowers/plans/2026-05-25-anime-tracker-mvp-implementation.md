# 動漫追番網站 MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 依照 `docs/superpowers/specs/2026-05-25-anime-tracker-mvp-design.md` 建立 Vue 前端、PHP REST API、MySQL migration、本機 Docker 與基本驗證。

**Architecture:** 前端是 Vue + Vite SPA，以 hash routing 支援 GitHub Pages；後端是無框架 PHP API，使用 PDO 存取 MySQL、手寫 router、JWT 與 Google ID token 驗證。Docker Compose 串起 frontend、backend、mysql、phpmyadmin，正式部署可沿用前後端各自 Dockerfile。

**Tech Stack:** Vue 3、Vite、PHP 8.3、PDO MySQL、MySQL 8、Docker、Docker Compose。

---

### Task 1: Backend Skeleton

**Files:**
- Create: `backend/public/index.php`
- Create: `backend/src/*.php`
- Create: `backend/database/migrations/001_init.sql`
- Create: `backend/database/seed.sql`
- Create: `backend/tests/run.php`

- [ ] 建立 PHP API 入口、設定、router、JSON response、CORS。
- [ ] 建立 JWT 簽發與驗證。
- [ ] 建立 Google ID token verifier，正式使用 Google tokeninfo，開發模式允許 `DEV_AUTH_BYPASS=true`。
- [ ] 建立 PDO repository 與 controllers。
- [ ] 建立 migration、seed 與 PHP 測試。
- [ ] 執行 `php -l` 與 `php backend/tests/run.php`。

### Task 2: Frontend SPA

**Files:**
- Create: `frontend/package.json`
- Create: `frontend/index.html`
- Create: `frontend/src/**/*`

- [ ] 建立 Vue + Vite 專案骨架。
- [ ] 建立 API client，統一帶 JWT。
- [ ] 建立 Login、Home、Catalog/Search、My List、Public List、Settings 視圖。
- [ ] 建立 responsive layout 與手機版底部導覽。
- [ ] 提供 Google 登入與開發登入模式。
- [ ] 執行可用的靜態檢查或建置指令。

### Task 3: Docker and Documentation

**Files:**
- Create: `backend/Dockerfile`
- Create: `frontend/Dockerfile`
- Create: `docker-compose.yml`
- Create: `.env.example`
- Modify: `README.md`

- [ ] 建立後端 Dockerfile。
- [ ] 建立前端 Dockerfile。
- [ ] 建立 docker-compose，包含 frontend、backend、mysql、phpmyadmin。
- [ ] 建立 `.env.example`，只放範例值。
- [ ] 更新 README，說明本機啟動、環境變數、部署與密鑰注意事項。

### Task 4: Verification

- [ ] 掃描 `.env` 與密鑰是否未被提交。
- [ ] 執行 PHP lint。
- [ ] 執行後端測試。
- [ ] 若依賴可安裝，執行前端 build。
- [ ] 彙整未驗證項目與原因。
