#!/bin/sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
image=${BACKUP_IMAGE:-ghcr.io/kevinsuu/anime-db-backup:latest}
platforms=${BACKUP_PLATFORMS:-linux/amd64}
output=${BACKUP_OUTPUT:-push}

case "$output" in
  push)
    output_flag=--push
    ;;
  load)
    case "$platforms" in
      *,*)
        printf 'BACKUP_OUTPUT=load only supports one platform.\n' >&2
        exit 1
        ;;
    esac
    output_flag=--load
    ;;
  *)
    printf 'BACKUP_OUTPUT must be either push or load.\n' >&2
    exit 1
    ;;
esac

printf 'Building %s for %s (output: %s)\n' "$image" "$platforms" "$output"

docker buildx build \
  --platform "$platforms" \
  --tag "$image" \
  "$output_flag" \
  "$project_root/docker/mysql-backup"

printf 'Backup image completed: %s\n' "$image"
