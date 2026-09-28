#!/usr/bin/env bash
#
# Startet die Lernkartei über Apache statt über den PHP-Entwicklungsserver.
#
# ---------------------------------------------------------------------------
# Warum Apache
# ---------------------------------------------------------------------------
# Eine Unterkategorieseite fragt mehrere Dinge GLEICHZEITIG an (die Lernbereiche,
# die Kategorie, ihre Unterkategorien und ihre Karten), und die Zeichnungen der
# Bereiche kommen obendrauf. Der Entwicklungsserver von PHP ist ein einziger
# Prozess: diese Anfragen stellen sich hintereinander an, und die Seite wartet auf
# ihre Summe. Apache beantwortet sie nebeneinander.
#
# Dazu gzippt Apache die SVG-Zeichnungen (siehe die Konfiguration: Text und SVG
# werden komprimiert), was der Entwicklungsserver nicht tut.
#
# ---------------------------------------------------------------------------
# Aufruf
# ---------------------------------------------------------------------------
#     ./start-apache.sh              -> http://127.0.0.1:8082/
#     PORT=8083 ./start-apache.sh    -> http://127.0.0.1:8083/
#
# Beenden mit Strg+C.
#
# Diese Instanz gehört allein uns: sie läuft als der Benutzer, dem das Projekt
# gehört, auf einem eigenen Port, und sie lässt den System-Apache (Port 80,
# /var/www/html) unberührt. sudo ist nicht nötig.
#
# ---------------------------------------------------------------------------
# Der Systemweg (einmalig, mit sudo) - wenn du die Anwendung auf Port 80 willst
# ---------------------------------------------------------------------------
#     sudo cp deploy/apache/learning-app.conf /etc/apache2/sites-available/
#     sudo a2ensite learning-app
#     sudo a2dissite 000-default
#     sudo systemctl reload apache2
#
# Dafür ist eines nötig: der System-Apache läuft als www-data und darf nicht in
# /home/user hineinlaufen. Die nächste Zeile erlaubt diesen Weg (und sonst
# nichts):
#
#     chmod o+x /home/user
#
# Rückgängig mit: chmod o-x /home/user
#
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
PORT="${PORT:-8082}"
CONF="$PROJECT_ROOT/deploy/apache/httpd-user.conf"

mkdir -p "$PROJECT_ROOT/deploy/apache/logs" "$PROJECT_ROOT/deploy/apache/sessions"

# Der Port in der Konfigurationsdatei ist der, den dieses Skript genannt bekommt.
sed -i "s/^Listen .*/Listen 127.0.0.1:${PORT}/" "$CONF"

echo "Learning app starts on http://127.0.0.1:${PORT}/ (Apache, gzip for SVG)."
echo "Stop it with Ctrl+C."

exec apache2 -d /etc/apache2 -f "$CONF" -DFOREGROUND
