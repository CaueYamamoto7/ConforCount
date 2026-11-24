#!/usr/bin/env bash
# Execute com a conexão direta configurada em um serviço libpq.
set -euo pipefail
umask 077
export PGSERVICE="${PGSERVICE:-conforcont}"
DESTINO="${BACKUP_DIR:-./backups}"
mkdir -p "$DESTINO"
ARQUIVO="$DESTINO/conforcont-$(date -u +%Y%m%dT%H%M%SZ).dump"
TEMPORARIO="$(mktemp "$DESTINO/.conforcont-XXXXXXXX")"
trap 'rm -f -- "$TEMPORARIO"' EXIT
pg_dump --format=custom --no-owner --no-privileges --exclude-table-data=public.sessions --file="$TEMPORARIO"
mv -- "$TEMPORARIO" "$ARQUIVO"
printf '%s\n' "$ARQUIVO"
