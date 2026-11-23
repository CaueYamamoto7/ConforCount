#!/usr/bin/env bash
# Backup diário do banco. Guarda 30 dias.
# Pré-requisito: crie /root/.obrigacoes.cnf (chmod 600) com:
#   [client]
#   user=obrigacoes
#   password=SENHA_DO_BANCO
# Agende no cron:  0 2 * * * /var/www/obrigacoes/bin/backup.sh
set -euo pipefail
DESTINO=/var/backups/obrigacoes
BANCO=obrigacoes
mkdir -p "$DESTINO"
chmod 700 "$DESTINO"
mysqldump --defaults-extra-file=/root/.obrigacoes.cnf --single-transaction --routines "$BANCO" | gzip > "$DESTINO/$BANCO-$(date +%F).sql.gz"
find "$DESTINO" -name "$BANCO-*.sql.gz" -mtime +30 -delete
