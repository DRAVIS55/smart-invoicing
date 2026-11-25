#!/usr/bin/env sh
set -eu

BACKUP_DIR="${1:-./backups}"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

echo "Backing up MySQL database..."
docker compose exec -T db sh -c 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > "$BACKUP_DIR/ledger_${TIMESTAMP}.sql"

echo "Backing up uploads_data volume..."
UPLOADS_VOL="$(docker volume ls -q | grep 'uploads_data$' | head -n 1 || true)"
if [ -n "$UPLOADS_VOL" ]; then
  docker run --rm -v "${UPLOADS_VOL}:/data" -v "$(pwd)/${BACKUP_DIR#./}:/backup" alpine tar -czf "/backup/uploads_${TIMESTAMP}.tar.gz" -C /data .
fi

echo "Backup complete in $BACKUP_DIR"
