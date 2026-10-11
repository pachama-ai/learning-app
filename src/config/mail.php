<?php

declare(strict_types=1);

/**
 * Die Einstellungen für ausgehende E-Mails.
 *
 * Die Werte stehen NICHT hier und auch nicht in .env.example mit echten Werten, sondern
 * ausschließlich in der Datei .env im Projektstamm (gelesen über src/helpers/env.php).
 * Damit gibt es genau eine Stelle mit Zugangsdaten, sie liegt außerhalb des Web-Roots, und
 * sie ist in .gitignore eingetragen - dieselbe Regel wie bei der Datenbank.
 *
 * Warum SMTP und nicht die eingebaute Funktion mail()
 * ---------------------------------------------------
 * PHPs mail() gibt die Nachricht an ein Programm wie sendmail weiter, und so etwas ist auf
 * diesem Server nicht installiert (kein sendmail, kein msmtp, kein postfix). Der Versand
 * geht deshalb direkt zum Postfach: über dessen SMTP-Server, mit Anmeldung und
 * verschlüsselter Verbindung. Was man dafür braucht, ist ein Zugangsweg zum Postfach -
 * bei Gmail ein App-Passwort und nicht das Kontopasswort des Google-Kontos.
 *
 * Welche Werte gebraucht werden (siehe .env.example):
 *   MAIL_HOST       Rechner des Postfachs, z. B. smtp.gmail.com
 *   MAIL_PORT       Port: 587 für STARTTLS, 465 für eine von Anfang an verschlüsselte Verbindung
 *   MAIL_SECURE     'tls' (STARTTLS) oder 'ssl' - leer heißt unverschlüsselt, was nur zu einem
 *                   Server im eigenen Netz sinnvoll ist
 *   MAIL_USER       Anmeldename des Postfachs (meist die vollständige Adresse)
 *   MAIL_PASS       Passwort bzw. App-Passwort
 *   MAIL_FROM       Absenderadresse; leer heißt: dieselbe wie MAIL_USER
 *   MAIL_FROM_NAME  Absendername, den das Mailprogramm anzeigt, z. B. Lernkartei
 *   APP_BASE_URL    Basisadresse der Anwendung, z. B. https://study.yokunto.de - daraus
 *                   baut die Benachrichtigung ihren Link
 *
 * Fehlt etwas, ist der Versand schlicht nicht eingerichtet: mail_is_configured() sagt
 * nein, und die Anwendung läuft weiter. Es wird dann nichts verschickt und auch nichts als
 * verschickt vermerkt.
 */

require_once __DIR__ . '/../helpers/env.php';

/** Der Zeichensatz der Mail. UTF-8, weil in den Texten deutsche Namen stehen. */
const MAIL_CHARSET = 'UTF-8';

/**
 * Die SMTP-Einstellungen aus .env, mit sinnvollen Vorgaben.
 *
 * Der Verschlüsselungsweg wird klein geschrieben zurückgegeben und auf die drei erlaubten
 * Werte begrenzt: ein Tippfehler in .env soll nicht dazu führen, dass unverschlüsselt
 * gesendet wird, ohne dass es jemand merkt. Alles, was nicht 'ssl', 'tls' oder '' ist,
 * gilt deshalb als 'tls'.
 *
 * @return array{host: string, port: int, secure: string, user: string, password: string,
 *               from: string, from_name: string, base_url: string}
 */
function mail_settings(): array
{
    $secure = strtolower(trim(env('MAIL_SECURE', 'tls')));

    if (!in_array($secure, ['ssl', 'tls', ''], true)) {
        $secure = 'tls';
    }

    $user = trim(env('MAIL_USER', ''));
    $from = trim(env('MAIL_FROM', ''));

    return [
        'host' => trim(env('MAIL_HOST', '')),
        'port' => (int) env('MAIL_PORT', '587'),
        'secure' => $secure,
        'user' => $user,
        'password' => (string) env('MAIL_PASS', ''),
        /* Ohne eigene Absenderadresse wird die Anmeldeadresse benutzt: Postfächer lassen
           fremde Absender oft nicht zu, und dann kommt gar nichts an. */
        'from' => $from !== '' ? $from : $user,
        'from_name' => trim(env('MAIL_FROM_NAME', 'Lernkartei')),
        'base_url' => rtrim(trim(env('APP_BASE_URL', '')), '/'),
    ];
}

/**
 * Ist der Mailversand eingerichtet?
 *
 * Geprüft werden nur die drei Angaben, ohne die keine Verbindung zustande kommt. Die
 * Absenderadresse fehlt hier absichtlich: sie fällt auf MAIL_USER zurück.
 *
 * @return bool
 */
function mail_is_configured(): bool
{
    $settings = mail_settings();

    return $settings['host'] !== '' && $settings['user'] !== '' && $settings['password'] !== '';
}
