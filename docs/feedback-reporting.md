# 問題回報

登入使用者可在 `/settings` 標題右側點選「問題回報」，並在浮動表單中填寫回報類別、主旨與內容。
`POST /feedback` 由 JWT 保護，
每個登入使用者每分鐘最多接受 5 次；後端會附上登入者名稱、信箱、使用者 ID 與回報時間，
並將登入者信箱設為回覆地址。收件地址預設為 `kaistarstudio@gmail.com`，可用
`FEEDBACK_TO_EMAIL` 覆寫。

實際寄送使用 Laravel Mail。Laravel 預設 mailer 是 `log`，只會把信件寫入 log；部署環境
必須設定可用的 `MAIL_MAILER`、寄件伺服器及 `MAIL_FROM_ADDRESS`，才能將回報送到收件匣。
通知信提供以動漫庫主題綠呈現的 HTML 版型與純文字備援，列出回報資訊表格及完整內容；
收件者可直接回覆通知信聯絡回報者。
SMTP 常用設定包含 `MAIL_HOST`、`MAIL_PORT`、`MAIL_USERNAME`、`MAIL_PASSWORD` 與
`MAIL_SCHEME`（例如 `smtps`）；敏感憑證只放在部署環境的 `.env`，不要提交到版本庫。
正式環境範本位於 `deploy/.env.production.example`；更新部署主機的 `.env` 後，重新建立
`backend` 容器以套用寄件設定。
