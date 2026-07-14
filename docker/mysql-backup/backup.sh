#!/bin/sh
set -eu

umask 077

backup_dir=/backups
timestamp=$(date '+%Y-%m-%d_%H-%M-%S')
final_file="${backup_dir}/${DB_DATABASE}_${timestamp}.sql.gz"
temporary_file="${final_file}.tmp"

mkdir -p "$backup_dir"
trap 'rm -f "$temporary_file"' EXIT HUP INT TERM

MYSQL_PWD="$DB_PASSWORD" mysqldump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USERNAME" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --no-tablespaces \
  "$DB_DATABASE" | gzip > "$temporary_file"

gzip -t "$temporary_file"
mv "$temporary_file" "$final_file"

find "$backup_dir" -type f -name '*.sql.gz' -mtime "+${BACKUP_RETENTION_DAYS:-30}" -delete

printf 'Database backup created: %s\n' "$final_file"
