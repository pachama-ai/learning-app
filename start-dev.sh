#!/usr/bin/env bash
#
# Starts the development server for this app.
#
# ---------------------------------------------------------------------------
# Why four workers
# ---------------------------------------------------------------------------
# A subcategory page asks for four things AT THE SAME TIME: the learning areas
# (for the sidebar), the category itself, its subcategories and its cards.
# PHP's own development server answers exactly one request at a time with a
# single worker, so those four requests queue up behind each other and the page
# waits for the sum of them.
#
# PHP_CLI_SERVER_WORKERS starts several worker processes, so the four requests
# are really answered side by side. Measured on this project, the four calls of
# one detail page:
#
#     one worker   91.8 ms
#     four workers 61.7 ms
#
# Nothing about the application changes: this only affects the local server.
#
# ---------------------------------------------------------------------------
# Usage
# ---------------------------------------------------------------------------
#     ./start-dev.sh              -> http://127.0.0.1:8081/
#     PORT=8082 ./start-dev.sh    -> http://127.0.0.1:8082/
#
# Stop it with Ctrl+C. Apache is NOT used by this script; it serves
# /var/www/html and has nothing to do with this project.

set -euo pipefail

PORT="${PORT:-8081}"
# The folder this script sits in, so it works from anywhere.
PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"

# Four workers instead of one. Keep this number at or below the number of CPU
# cores; more workers only take turns on the same cores.
export PHP_CLI_SERVER_WORKERS=4

echo "Learning app starts on http://127.0.0.1:${PORT}/ with ${PHP_CLI_SERVER_WORKERS} workers."
echo "Press Ctrl+C to stop it."

cd "$PROJECT_ROOT"
exec php -S "127.0.0.1:${PORT}" -t public
