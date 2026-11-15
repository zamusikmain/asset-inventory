#!/bin/sh
# Восстановление из резервной копии (ежеквартальная тестовая проверка — п. 20 ТЗ).
#   ./docker/backup/restore.sh /backup/asset-inventory/daily/asset-inventory_YYYYmmdd_HHMMSS.tar.enc [--target=assets_restore_test]
#
# По умолчанию восстанавливает в ОТДЕЛЬНУЮ БД assets_restore_test и каталог /tmp/restore-storage
# (проверка без влияния на рабочие данные). Для боевого восстановления: --target=assets --storage
set -eu
FILE="$1"; shift || true
TARGET_DB="assets_restore_test"; RESTORE_STORAGE=0
for a in "$@"; do
    case "$a" in
        --target=*) TARGET_DB="${a#--target=}" ;;
        --storage) RESTORE_STORAGE=1 ;;
    esac
done
PASSFILE="${BACKUP_PASSFILE:-/etc/asset-inventory/backup.pass}"
COMPOSE="docker compose"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$PASSFILE" -in "$FILE" -out "$TMP/bundle.tar"
tar -C "$TMP" -xf "$TMP/bundle.tar"
( cd "$TMP" && sha256sum -c SHA256SUMS )

echo "Восстановление БД в $TARGET_DB"
$COMPOSE exec -T db sh -c "dropdb -U \"\$POSTGRES_USER\" --if-exists $TARGET_DB && createdb -U \"\$POSTGRES_USER\" $TARGET_DB"
$COMPOSE exec -T db sh -c "pg_restore -U \"\$POSTGRES_USER\" -d $TARGET_DB --no-owner" < "$TMP/db.dump"
COUNT=$($COMPOSE exec -T db sh -c "psql -U \"\$POSTGRES_USER\" -d $TARGET_DB -tAc 'select count(*) from assets'")
echo "Активов в восстановленной БД: $COUNT"

if [ "$RESTORE_STORAGE" = "1" ]; then
    $COMPOSE exec -T php sh -c 'tar -C /var/www/storage -xzf -' < "$TMP/storage.tgz"
    echo "Документы восстановлены в /var/www/storage"
else
    mkdir -p /tmp/restore-storage && tar -C /tmp/restore-storage -xzf "$TMP/storage.tgz"
    echo "Документы распакованы для проверки в /tmp/restore-storage ($(find /tmp/restore-storage -type f | wc -l) файлов)"
fi
echo "Проверка восстановления завершена успешно"
