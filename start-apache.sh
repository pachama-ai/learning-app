#!/usr/bin/env bash
#
# Starts the learning app through Apache instead of the PHP development server.
#
# ---------------------------------------------------------------------------
# Why Apache
# ---------------------------------------------------------------------------
# A subcategory page asks for several things AT THE SAME TIME (the learning
# areas, the category, its subcategories and its cards), and the drawings of the
# areas come on top. PHP's own development server is a single process: those
# requests queue up behind each other, and the page waits for their sum. Apache
# answers them side by side.
#
# On top of that Apache gzips the SVG drawings (see the config: text and SVG are
# compressed), which the development server does not.
#
# ---------------------------------------------------------------------------
# Usage
# ---------------------------------------------------------------------------
#     ./start-apache.sh              -> http://127.0.0.1:8082/
#     PORT=8083 ./start-apache.sh    -> http://127.0.0.1:8083/
#
# Stop it with Ctrl+C.
#
# This instance is ours alone: it runs as the user who owns the project, on a
# port of its own, and it leaves the system Apache (port 80, /var/www/html)
# untouched. No sudo is needed.
#
# ---------------------------------------------------------------------------
# The system way (one time, with sudo) - if you want the app on port 80
# ---------------------------------------------------------------------------
#     sudo cp deploy/apache/learning-app.conf /etc/apache2/sites-available/
#     sudo a2ensite learning-app
#     sudo a2dissite 000-default
#     sudo systemctl reload apache2
#
# One thing is needed for that: the system Apache runs as www-data, which may not
# walk into /home/user. The next line allows that walk (nothing else):
#
#     chmod o+x /home/user
#
# Undo it with: chmod o-x /home/user
#
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
PORT="${PORT:-8082}"
CONF="$PROJECT_ROOT/deploy/apache/httpd-user.conf"

mkdir -p "$PROJECT_ROOT/deploy/apache/logs" "$PROJECT_ROOT/deploy/apache/sessions"

# The port in the config file is the one this script was told to use.
sed -i "s/^Listen .*/Listen 127.0.0.1:${PORT}/" "$CONF"

echo "Learning app starts on http://127.0.0.1:${PORT}/ (Apache, gzip for SVG)."
echo "Stop it with Ctrl+C."

exec apache2 -d /etc/apache2 -f "$CONF" -DFOREGROUND
