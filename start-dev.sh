#!/usr/bin/env bash
#
# Startet den Entwicklungsserver fuer diese Anwendung.
#
# ---------------------------------------------------------------------------
# Warum vier Worker
# ---------------------------------------------------------------------------
# Eine Unterkategorie-Seite fragt VIER Dinge gleichzeitig ab: die Lernbereiche
# (fuer die Seitenleiste), die Kategorie selbst, ihre Unterkategorien und ihre
# Karten. Der PHP-eigene Entwicklungsserver beantwortet immer nur eine Anfrage
# auf einmal (ein Worker), also stellen sich diese vier Anfragen hintereinander
# an und die Seite wartet auf ihre Summe.
#
# PHP_CLI_SERVER_WORKERS startet mehrere Worker-Prozesse, damit die vier
# Anfragen wirklich nebeneinander laufen. Auf diesem Projekt gemessen, die vier
# Aufrufe einer Detailseite:
#
#     ein Worker     91.8 ms
#     vier Worker    61.7 ms
#
# An der Anwendung aendert das nichts: es betrifft nur den lokalen Server.
#
# ---------------------------------------------------------------------------
# Benutzung
# ---------------------------------------------------------------------------
#     ./start-dev.sh              -> http://127.0.0.1:8081/
#     PORT=8082 ./start-dev.sh    -> http://127.0.0.1:8082/
#
# Beenden mit Strg+C. Apache wird von diesem Skript NICHT benutzt; der
# System-Apache liefert /var/www/html aus und hat mit diesem Projekt nichts zu
# tun.

set -euo pipefail

PORT="${PORT:-8081}"
# Der Ordner, in dem dieses Skript liegt, damit es von ueberall aus laeuft.
PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"

# Vier Worker statt einem. Die Zahl sollte nicht groesser sein als die Anzahl
# der CPU-Kerne; mehr Worker teilen sich nur dieselben Kerne.
export PHP_CLI_SERVER_WORKERS=4

# Die Sitzungen liegen in DEMSELBEN Ordner wie bei Apache. Das ist wichtiger,
# als es aussieht: der Browser legt das Sitzungs-Cookie pro Rechner ab, nicht pro
# Port. Wer 8081 und 8082 gleichzeitig offen hat, schickt also dieselbe
# Sitzungs-Kennung an beide Server. Lagen die Sitzungen in zwei verschiedenen
# Ordnern, kannte jeder Server nur seine eigene Haelfte davon - und die
# Anwendung meldete "The page was open for too long", weil der CSRF-Token nicht
# mehr zu der Sitzung passte, die der andere Server angelegt hatte.
SESSION_DIR="$PROJECT_ROOT/deploy/apache/sessions"
mkdir -p "$SESSION_DIR"

echo "Learning app starts on http://127.0.0.1:${PORT}/ with ${PHP_CLI_SERVER_WORKERS} workers."
echo "Sessions are kept in ${SESSION_DIR}."
echo "Press Ctrl+C to stop it."

cd "$PROJECT_ROOT"
# -d session.save_path zeigt auf denselben Ordner wie die Apache-Konfiguration
# (deploy/apache/httpd-user.conf, Zeile 68).
exec php -S "127.0.0.1:${PORT}" -t public -d session.save_path="$SESSION_DIR"
