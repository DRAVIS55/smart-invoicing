#!/bin/sh
set -eu

storage_path="${APP_STORAGE_PATH:-/var/www/private_uploads}"
mkdir -p "$storage_path"
chown www-data:www-data "$storage_path"
chmod 0750 "$storage_path"

exec "$@"
