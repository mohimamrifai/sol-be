#!/bin/sh
set -e

# Docker Compose sets these; ensure the long-running serve process sees them.
export DB_HOST="${DB_HOST:-mysql}"
export DB_PORT="${DB_PORT:-3306}"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-1}"

php artisan config:clear --quiet 2>/dev/null || true

exec php artisan serve --host=0.0.0.0 --port=8000
