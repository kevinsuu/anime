#!/bin/sh
set -eu

marker=/backups/.last-successful-backup

mkdir -p /backups
printf 'Database backup scheduler started (daily at 22:00 %s).\n' "${TZ:-Asia/Taipei}"

while true; do
  today=$(date '+%Y-%m-%d')
  hour=$(date '+%H')
  last_backup=$(cat "$marker" 2>/dev/null || true)

  if [ "$hour" -ge 22 ] && [ "$last_backup" != "$today" ]; then
    if /usr/local/bin/backup.sh; then
      printf '%s\n' "$today" > "$marker"
    else
      printf 'Database backup failed; retrying in 60 seconds.\n' >&2
    fi
  fi

  sleep 60
done
