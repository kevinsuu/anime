# PHP 執行環境安全更新

backend 與 scheduler 使用 `backend/Dockerfile.production` 和
`backend/Dockerfile.scheduler` 建置 PHP 映像。正式部署只會在推送新的 `vX.Y.Z` tag
時重建映像並更新主機上的容器；重新啟動舊容器不會更新其中的 PHP。

CI 與正式部署的 PHP 映像建置都設定 `pull: true`，以便每次建置先取得官方 PHP base
image 的最新修補版本。更新 PHP 後，必須建立並部署新版本 tag。

針對 CVE-2026-109618 與 CVE-2026-109619，PHP 8.4 的最低修補版本為 **8.4.27**。

## 發布更新

先將 Docker build workflow 的修改提交並推送到目前分支，再建立下一個未使用的版本 tag：

```bash
git add .github/workflows/ci.yml .github/workflows/deploy.yml deploy/README.md docs/php-runtime-maintenance.md
git commit -m "fix: 更新 PHP 安全映像建置"
git push
git tag vX.Y.Z
git push origin vX.Y.Z
```

將 `vX.Y.Z` 換成下一個未使用的版本號。此 tag 會觸發 `.github/workflows/deploy.yml`，建置
backend、scheduler、frontend 映像，推送至 GHCR，並透過 SSH 更新正式主機。

在 GitHub Actions 確認建置與部署工作成功後，於正式主機確認容器已替換：

```bash
docker ps --filter name=anime-deploy-
docker exec anime-deploy-backend-1 php -v
docker exec anime-deploy-scheduler-1 php -v
```

兩個容器都應為 PHP 8.4.27 或更新的 8.4 版。`docker exec` 不依賴目前工作目錄；若改用
`docker compose exec`，先切換到正式部署目錄（包含 `docker-compose.yml` 的目錄）。

## 本機建置

若只需更新本機 Docker Compose 環境，可在 repository 根目錄執行：

```bash
docker compose build --pull backend scheduler
docker compose up -d backend scheduler
docker compose exec backend php -v
docker compose exec scheduler php -v
```

本機重建不會更新 GHCR 或正式主機；正式環境仍須透過版本 tag 執行部署流程。
