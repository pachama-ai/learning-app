<?php

declare(strict_types=1);

/**
 * Eine E-Mail verschicken - über den SMTP-Server des Postfachs.
 *
 * Warum dieser Weg: PHPs mail() braucht ein Programm wie sendmail auf dem Server, und das
 * ist hier nicht installiert. Mit einem eigenen kleinen SMTP-Gespräch braucht es kein
 * weiteres Programm und keine Bibliothek - und das Projekt bleibt bei "kein Paketmanager,
 * keine fremde Abhängigkeit".
 *
 * Was hier absichtlich NICHT passiert:
 *   - Es wird kein Passwort protokolliert. In den AUTH-Zeilen steht es, deshalb schreibt
 *     diese Datei kein einziges Kommando ins Fehlerprotokoll - nur die Antworten des
 *     Servers, und die enthalten keine Zugangsdaten.
 *   - Es wird nichts HTML verschickt. Der Text ist Text; damit braucht die Mail keinen
 *     Inhalt, der aussehen kann wie Markup.
 *
 * Braucht die Anwendung keine Mail, stört sie auch nichts: ist nichts eingerichtet,
 * antwortet mail_send() mit 'not_configured' und schreibt eine Zeile ins Protokoll.
 */

require_once __DIR__ . '/../config/mail.php';

/** Wie lange auf eine Antwort des Servers gewartet wird, in Sekunden. */
const MAIL_TIMEOUT_SECONDS = 15;

/**
 * Eine E-Mail verschicken.
 *
 * @param string $to Empfängeradresse
 * @param string $subject Betreff, unkodiert (die Kodierung macht diese Datei)
 * @param string $body reiner Text, Zeilenumbrüche erlaubt
 * @return array{ok: bool, error: string|null} error ist ein kurzer Code, keine Meldung
 */
function mail_send(string $to, string $subject, string $body): array
{
    $settings = mail_settings();

    if (!mail_is_configured()) {
        return ['ok' => false, 'error' => 'not_configured'];
    }

    /* Eine Adresse, die offensichtlich keine ist, wird gar nicht erst versucht: der Server
       würde sie ablehnen, und das wäre ein Fehler mehr, der wie ein Serverproblem aussieht. */
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'invalid_recipient'];
    }

    /* Ab Port 465 wird die Verbindung sofort verschlüsselt (ssl://), ab 587 erst nach
       STARTTLS. Welcher der beiden Wege gilt, entscheidet MAIL_SECURE in .env. */
    $target = ($settings['secure'] === 'ssl' ? 'ssl://' : '')
        . $settings['host'] . ':' . $settings['port'];

    $socket = @stream_socket_client($target, $errorNumber, $errorText, MAIL_TIMEOUT_SECONDS);

    if ($socket === false) {
        error_log(
            'mail: Verbindung zu ' . $settings['host'] . ':' . $settings['port']
            . ' fehlgeschlagen (' . (string) $errorNumber . ' ' . (string) $errorText . ')'
        );

        return ['ok' => false, 'error' => 'connect_failed'];
    }

    stream_set_timeout($socket, MAIL_TIMEOUT_SECONDS);

    try {
        mail_expect($socket, 220);
        mail_command($socket, 'EHLO ' . mail_client_name(), 250);

        if ($settings['secure'] === 'tls') {
            mail_command($socket, 'STARTTLS', 220);

            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Die Verschlüsselung kam nicht zustande.');
            }

            /* Nach dem Wechsel wird der Server noch einmal begrüßt: jetzt kann er seine
               Fähigkeiten in der verschlüsselten Verbindung nennen. */
            mail_command($socket, 'EHLO ' . mail_client_name(), 250);
        }

        mail_command($socket, 'AUTH LOGIN', 334);
        mail_command($socket, base64_encode($settings['user']), 334);
        mail_command($socket, base64_encode($settings['password']), 235);

        mail_command($socket, 'MAIL FROM:<' . $settings['from'] . '>', 250);
        /* 251 heißt "kümmere ich mich", und das ist genauso gut wie 250. */
        mail_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        mail_command($socket, 'DATA', 354);

        mail_write($socket, mail_data_block(mail_message($to, $subject, $body, $settings)));
        mail_expect($socket, 250);
        mail_command($socket, 'QUIT', 221);
    } catch (RuntimeException $error) {
        fclose($socket);

        /* Im Protokoll steht, was der Server gesagt hat - und das enthält keine
           Zugangsdaten. In die Antwort an den Aufrufer geht davon nichts: der bekommt nur
           den kurzen Code. */
        error_log('mail: ' . $error->getMessage());

        return ['ok' => false, 'error' => 'send_failed'];
    }

    fclose($socket);

    return ['ok' => true, 'error' => null];
}

/**
 * Der Name, mit dem sich dieser Rechner beim Server meldet.
 *
 * EHLO verlangt einen Namen; der eigene Rechnername ist die ehrlichste Antwort darauf.
 *
 * @return string
 */
function mail_client_name(): string
{
    $name = gethostname();

    return is_string($name) && $name !== '' ? $name : 'localhost';
}

/**
 * Die fertige Nachricht: Kopfzeilen und Text.
 *
 * Die Kopfzeilen stehen in einer festen Reihenfolge, weil sie so am leichtesten zu lesen
 * sind. Wichtig ist der Zeichensatz: der Text ist UTF-8, damit deutsche Namen richtig
 * ankommen.
 *
 * @param string $to Empfängeradresse
 * @param string $subject Betreff
 * @param string $body Text
 * @param array<string, mixed> $settings die Werte aus mail_settings()
 * @return string
 */
function mail_message(string $to, string $subject, string $body, array $settings): string
{
    $from = (string) $settings['from'];

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mail_display_name((string) $settings['from_name']) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . mail_encode($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . mail_domain($from) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=' . MAIL_CHARSET,
        'Content-Transfer-Encoding: 8bit',
    ];

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * Aus der Nachricht den Datenblock machen, den SMTP erwartet.
 *
 * Zwei Dinge sind hier Pflicht und keine Kosmetik: jede Zeile endet mit CRLF, und ein
 * Punkt am Zeilenanfang wird verdoppelt. Ein einzelner Punkt auf einer Zeile bedeutet im
 * Datenblock "hier ist Schluss" - ohne die Verdopplung wäre der Text ab dieser Zeile weg.
 *
 * @param string $message
 * @return string
 */
function mail_data_block(string $message): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $message);
    $text = (string) preg_replace('/^\./m', '..', $text);
    $text = str_replace("\n", "\r\n", $text);

    return $text . "\r\n.\r\n";
}

/**
 * Einen Text als MIME-Wort kodieren.
 *
 * In den Kopfzeilen sind nur ASCII-Zeichen erlaubt; Umlaute und ß müssen deshalb kodiert
 * werden. Base64 ist dafür der einfache und verlustfreie Weg.
 *
 * @param string $text
 * @return string
 */
function mail_encode(string $text): string
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Der Absendername, wie er im Mailprogramm steht.
 *
 * Er steht in Anführungszeichen, weil er Leerzeichen enthält. Anführungszeichen und
 * Zeilenumbrüche im Namen selbst werden entfernt: ein Zeilenumbruch in einer Kopfzeile
 * wäre der Anfang einer gefälschten zweiten Kopfzeile.
 *
 * @param string $name
 * @return string
 */
function mail_display_name(string $name): string
{
    $clean = str_replace(['"', "\r", "\n"], '', $name);

    return $clean === '' ? '' : '"' . mail_encode($clean) . '"';
}

/**
 * Die Domain, die in die Message-Id gehört.
 *
 * Sie kommt aus der Absenderadresse; fehlt ein @-Zeichen, bleibt "localhost". Die Angabe
 * ist nur eine Kennung - sie muss nicht auflösbar sein, aber sie soll nicht lügen.
 *
 * @param string $address
 * @return string
 */
function mail_domain(string $address): string
{
    $parts = explode('@', $address, 2);

    return count($parts) === 2 && $parts[1] !== '' ? $parts[1] : 'localhost';
}

/**
 * Eine Zeile vom Server lesen.
 *
 * @param resource $socket
 * @return string ohne die Zeilenenden
 * @throws RuntimeException wenn der Server nichts mehr schickt
 */
function mail_read_line($socket): string
{
    $line = fgets($socket, 1024);

    if ($line === false) {
        throw new RuntimeException('Der Server hat die Verbindung ohne Antwort geschlossen.');
    }

    return rtrim($line, "\r\n");
}

/**
 * Auf einen bestimmten Antwortcode warten.
 *
 * Antworten dürfen mehrzeilig sein: sie beginnen dann mit "250-" und enden mit "250 ".
 * Erst mit der letzten Zeile ist der Server fertig.
 *
 * @param resource $socket
 * @param int|list<int> $expected der erwartete Code, oder mehrere erlaubte
 * @return void
 * @throws RuntimeException wenn ein anderer Code kommt
 */
function mail_expect($socket, int|array $expected): void
{
    $allowed = is_array($expected) ? $expected : [$expected];
    $line = mail_read_line($socket);
    $code = (int) substr($line, 0, 3);

    /* Bei einer mehrzeiligen Antwort steht nach dem Code ein Minus. Weiterlesen, bis eine
       Zeile ohne Minus kommt. */
    $text = $line;

    while (strlen($line) > 3 && $line[3] === '-') {
        $line = mail_read_line($socket);
        $text .= ' | ' . trim(substr($line, 4));
    }

    if (!in_array($code, $allowed, true)) {
        throw new RuntimeException(
            'SMTP antwortete ' . $code . ' statt ' . implode('/', $allowed) . ': ' . $text
        );
    }
}

/**
 * Ein Kommando schicken und die Antwort prüfen.
 *
 * Kommandos werden NICHT protokolliert: in den beiden Zeilen der Anmeldung steht das
 * Passwort, und ein Protokoll ist kein Tresor.
 *
 * @param resource $socket
 * @param string $command ohne Zeilenende
 * @param int|list<int> $expected der erwartete Antwortcode
 * @return void
 * @throws RuntimeException wenn der Server nicht wie erwartet antwortet
 */
function mail_command($socket, string $command, int|array $expected): void
{
    mail_write($socket, $command . "\r\n");
    mail_expect($socket, $expected);
}

/**
 * Schreiben und prüfen, dass wirklich alles angekommen ist.
 *
 * @param resource $socket
 * @param string $data
 * @return void
 * @throws RuntimeException wenn der Server die Verbindung mittendrin verloren hat
 */
function mail_write($socket, string $data): void
{
    $written = fwrite($socket, $data);

    if ($written === false || $written < strlen($data)) {
        throw new RuntimeException('Die Verbindung brach beim Senden ab.');
    }
}
