#!/usr/bin/env bash
#
# Deploy BongCalendar: bring the running copy in line with the checked-out code.
#
# The two steps that matter are the ones easy to forget, and both show up the
# same way — a page that keeps rendering its previous version after a deploy:
#
#   * `public/build/` is gitignored, so pulling code never brings new CSS or JS.
#     Without `npm run build` the server keeps serving the last bundle it built.
#
#   * Blade compiles to PHP under `storage/framework/views/` and decides whether
#     to recompile by comparing file times. A deploy that writes .blade.php files
#     with older timestamps — rsync -t, an unpacked archive, a restored checkout —
#     leaves those compiled files looking current, and the old page goes on being
#     served. `view:clear` removes the question.
#
# Run from the project root, on the server, after the code is in place.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Front-end bundle"
npm ci --ignore-scripts
npm run build

echo "==> Database"
php artisan migrate --force

echo "==> Caches"
# Clear before caching: a stale compiled view or a config cache written against
# the previous release must not survive into this one.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Storage link"
php artisan storage:link || true

echo "Done. Deployed $(git rev-parse --short HEAD 2>/dev/null || echo 'working copy')."
