#!/bin/sh
# Резервное копирование (п. 20 ТЗ): ежедневная копия PostgreSQL и документов,
# шифрование, ротация 14 дневных / 8 недельных / 12 месячных, копия на другое устройство.
#
# Запуск на хосте (cron, ежедневно 02:30):
#   30 2 * * * cd /opt/asset-inventory && ./docker/backup/backup.sh >> /var/log/asset-backup.log 2>&1
#
# Переменные:
#   BACKUP_DIR          локальный каталог копий (отдельный диск), по умолчанию /backup/asset-inventory
#   BACKUP_PASSFILE     файл с паролем шифрования (chmod 600, хранится вне каталога копий)
#   BACKUP_REMOTE       необязательно: rsync-назначение второй копии, напр. backup@nas:/volume1/asset/
set -eu

COMPOSE="docker compose"
BACKUP_DIR="${BACKUP_DIR:-/backup/asset-inventory}"
PASSFILE="${BACKUP_PASSFILE:-/etc/asset-inventory/backup.pass}"
STAMP="$(date +%Y%m%d_%H%M%S)"
DOW="$(date +%u)"   # 7 = воскресенье
DOM="$(date +%d)"

[ -r "$PASSFILE" ] || { echo "Нет файла пароля шифрования $PASSFILE"; exit 1; }
mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly" "$BACKUP_DIR/monthly"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "[$(date)] Дамп PostgreSQL"
$COMPOSE exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner' > "$TMP/db.dump"

echo "[$(date)] Архив документов, планов и вложений"
$COMPOSE exec -T php tar -C /var/www/storage --exclude=./keys -czf - . > "$TMP/storage.tgz"

( cd "$TMP" && sha256sum db.dump storage.tgz > SHA256SUMS )
tar -C "$TMP" -cf "$TMP/bundle.tar" db.dump storage.tgz SHA256SUMS

OUT="$BACKUP_DIR/daily/asset-inventory_$STAMP.tar.enc"
openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$PASSFILE" -in "$TMP/bundle.tar" -out "$OUT"
echo "[$(date)] Создано: $OUT ($(du -h "$OUT" | cut -f1))"

[ "$DOW" = "7" ] && cp "$OUT" "$BACKUP_DIR/weekly/"
[ "$DOM" = "01" ] && cp "$OUT" "$BACKUP_DIR/monthly/"

# Ротация
ls -1t "$BACKUP_DIR/daily/"*.enc 2>/dev/null | tail -n +15 | xargs -r rm -f
ls -1t "$BACKUP_DIR/weekly/"*.enc 2>/dev/null | tail -n +9 | xargs -r rm -f
ls -1t "$BACKUP_DIR/monthly/"*.enc 2>/dev/null | tail -n +13 | xargs -r rm -f

# Минимум одна копия на другом устройстве
if [ -n "${BACKUP_REMOTE:-}" ]; then
    rsync -a --delete "$BACKUP_DIR/" "$BACKUP_REMOTE"
    echo "[$(date)] Синхронизировано на $BACKUP_REMOTE"
else
    echo "ВНИМАНИЕ: BACKUP_REMOTE не задан — копия на другом устройстве не создается"
fi
