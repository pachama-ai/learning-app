<?php

declare(strict_types=1);

/**
 * Fotos an einer Lernkarte speichern und entfernen.
 *
 * Die Datei wird nicht so abgelegt, wie sie hochgeladen wurde. Sie wird zuerst
 * gelesen und neu geschrieben: dadurch verschwinden EXIF-Daten und ein
 * umbenanntes Skript kann nicht als Bild durchgehen. Gespeichert wird nur ein
 * zufälliger Dateiname mit einer festen Endung; der Name aus dem Browser wird
 * nie benutzt.
 *
 * Die Anwendung kennt die Spalten image_front und image_back erst, wenn
 * database/card_images.sql ausgeführt wurde. card_images_available() sagt, ob
 * das der Fall ist; die anderen Funktionen antworten sonst mit einem klaren
 * Fehler, statt eine kaputte Abfrage abzuschicken.
 */

require_once __DIR__ . '/card_service.php';
require_once __DIR__ . '/../helpers/card_image.php';

/**
 * Ob diese Datenbank Fotos an Karten speichern kann.
 */
function card_images_available(PDO $pdo): bool
{
    return card_image_columns_present(card_columns($pdo));
}

/**
 * Die gespeicherten Dateinamen einer Karte, oder null, wenn sie diesem Konto
 * nicht gehört.
 *
 * Absichtlich die rohen Namen: zum Löschen einer ersetzten Datei braucht der
 * Service den alten Namen, nicht die Adresse für den Browser.
 *
 * @return array{image_front: string|null, image_back: string|null}|null
 */
function card_image_files(PDO $pdo, int $cardId, int $ownerUserId): ?array
{
    if (!card_images_available($pdo)) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT image_front, image_back
           FROM cards
          WHERE id = :id
            AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'image_front' => $row['image_front'] === null ? null : (string) $row['image_front'],
        'image_back' => $row['image_back'] === null ? null : (string) $row['image_back'],
    ];
}

/**
 * Nimmt ein hochgeladenes Foto an und trägt es in die Karte ein.
 *
 * @param array<string, mixed> $file Ein Eintrag aus $_FILES.
 * @param string $side "front" oder "back".
 * @return array{error: string|null, card: array<string, mixed>|null}
 */
function card_image_store(PDO $pdo, array $file, int $cardId, string $side, int $ownerUserId): array
{
    $column = card_image_column_for_side($side);

    if ($column === null) {
        return ['error' => 'invalid_image_side', 'card' => null];
    }

    if (!card_images_available($pdo)) {
        return ['error' => 'card_images_unavailable', 'card' => null];
    }

    $card = find_card($pdo, $cardId, $ownerUserId);

    if ($card === null) {
        return ['error' => 'card_not_found', 'card' => null];
    }

    $read = card_image_read_upload($file);

    if ($read['error'] !== null) {
        return ['error' => $read['error'], 'card' => null];
    }

    $image = $read['image'];

    if (!$image instanceof \GdImage) {
        return ['error' => 'image_invalid', 'card' => null];
    }

    $extension = $read['extension'];
    $directory = card_image_directory();

    /* Der Ordner entsteht beim ersten Foto. 0775, damit der Webserver schreiben
       darf, aber niemand von außen. */
    if (!is_dir($directory)
        && !@mkdir($directory, 0775, true)
        && !is_dir($directory)) {
        imagedestroy($image);

        return ['error' => 'image_failed', 'card' => null];
    }

    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    $path = card_image_path($name);

    if ($path === null) {
        imagedestroy($image);

        return ['error' => 'image_failed', 'card' => null];
    }

    $written = card_image_write($image, $path, $extension);
    imagedestroy($image);

    if (!$written) {
        @unlink($path);

        return ['error' => 'image_failed', 'card' => null];
    }

    @chmod($path, 0644);

    $files = card_image_files($pdo, $cardId, $ownerUserId);
    $old = $files === null ? null : $files[$column];

    $statement = $pdo->prepare(
        'UPDATE cards SET ' . $column . ' = :file
          WHERE id = :id
            AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );
    $statement->bindValue(':file', $name, PDO::PARAM_STR);
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    /*
     * Die Karte gehört inzwischen jemand anderem oder wurde gelöscht: dann gehört
     * die neue Datei niemandem und verschwindet wieder. Der vorherige Wert bleibt
     * in diesem Fall unangetastet, weil das UPDATE nichts getroffen hat.
     */
    if ($statement->rowCount() === 0) {
        @unlink($path);

        return ['error' => 'card_not_found', 'card' => null];
    }

    card_image_delete_file($old);

    $updated = find_card($pdo, $cardId, $ownerUserId);

    return $updated === null
        ? ['error' => 'card_not_found', 'card' => null]
        : ['error' => null, 'card' => $updated];
}

/**
 * Nimmt das Foto einer Seite wieder weg.
 *
 * Kein Foto zu haben ist kein Fehler: die Antwort ist in diesem Fall die Karte
 * unverändert. Erst wenn die Karte danach noch existiert, wird die Datei
 * gelöscht; so bleibt sie bei einem Abbruch nicht als Waise liegen.
 *
 * @return array{error: string|null, card: array<string, mixed>|null}
 */
function card_image_remove(PDO $pdo, int $cardId, string $side, int $ownerUserId): array
{
    $column = card_image_column_for_side($side);

    if ($column === null) {
        return ['error' => 'invalid_image_side', 'card' => null];
    }

    if (!card_images_available($pdo)) {
        return ['error' => 'card_images_unavailable', 'card' => null];
    }

    $files = card_image_files($pdo, $cardId, $ownerUserId);

    if ($files === null) {
        return ['error' => 'card_not_found', 'card' => null];
    }

    $old = $files[$column];

    $statement = $pdo->prepare(
        'UPDATE cards SET ' . $column . ' = NULL
          WHERE id = :id
            AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );
    $statement->bindValue(':id', $cardId, PDO::PARAM_INT);
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);
    $statement->execute();

    if ($statement->rowCount() > 0) {
        card_image_delete_file($old);
    }

    $updated = find_card($pdo, $cardId, $ownerUserId);

    return $updated === null
        ? ['error' => 'card_not_found', 'card' => null]
        : ['error' => null, 'card' => $updated];
}

/**
 * Die Foto-Dateinamen aller Karten dieser Kategorien.
 *
 * Wird vor dem Löschen eines Teilbaums gelesen. Die Dateien selbst werden erst
 * gelöscht, wenn die Datenbank das Löschen bestätigt hat (siehe
 * card_image_delete_files()): ein Rollback soll keine Bilder entfernen, auf die
 * die Karten danach noch zeigen.
 *
 * @param list<int> $categoryIds
 * @return list<string>
 */
function card_image_files_of_categories(PDO $pdo, array $categoryIds, int $ownerUserId): array
{
    if ($categoryIds === [] || !card_images_available($pdo)) {
        return [];
    }

    $placeholders = [];
    $ids = [];

    foreach (array_values($categoryIds) as $index => $categoryId) {
        $placeholders[] = ':image_category_' . $index;
        $ids[':image_category_' . $index] = (int) $categoryId;
    }

    $statement = $pdo->prepare(
        'SELECT image_front, image_back
           FROM cards
          WHERE category_id IN (' . implode(', ', $placeholders) . ')
            AND category_id IN (SELECT id FROM categories WHERE owner_user_id = :owner_user_id)'
    );
    $statement->bindValue(':owner_user_id', $ownerUserId, PDO::PARAM_INT);

    foreach ($ids as $placeholder => $id) {
        $statement->bindValue($placeholder, $id, PDO::PARAM_INT);
    }

    $statement->execute();

    $files = [];

    foreach ($statement->fetchAll() as $row) {
        foreach (['image_front', 'image_back'] as $column) {
            $file = $row[$column] === null ? null : (string) $row[$column];

            if (card_image_filename_is_valid($file)) {
                $files[] = $file;
            }
        }
    }

    return array_values(array_unique($files));
}

/**
 * Löscht die Dateien einer Liste von Namen.
 *
 * @param list<string> $files
 */
function card_image_delete_files(array $files): void
{
    foreach ($files as $file) {
        card_image_delete_file($file);
    }
}

/**
 * Prüft den Upload und gibt ein fertig gedrehtes, verkleinertes Bild zurück.
 *
 * Alle Prüfungen passieren vor dem Lesen der Bildpunkte: eine Datei, die
 * behauptet, ein Foto zu sein, aber keine ist, kostet so keinen Speicher.
 *
 * @param array<string, mixed> $file Ein Eintrag aus $_FILES.
 * @return array{error: string|null, image: \GdImage|null, extension: string}
 */
function card_image_read_upload(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['error' => 'image_too_large', 'image' => null, 'extension' => ''];
    }

    if ($error !== UPLOAD_ERR_OK) {
        return ['error' => 'image_missing', 'image' => null, 'extension' => ''];
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        return ['error' => 'image_missing', 'image' => null, 'extension' => ''];
    }

    $size = @filesize($temporaryPath);

    if ($size === false || $size <= 0) {
        return ['error' => 'image_empty', 'image' => null, 'extension' => ''];
    }

    if ($size > CARD_IMAGE_MAX_BYTES) {
        return ['error' => 'image_too_large', 'image' => null, 'extension' => ''];
    }

    $info = @getimagesize($temporaryPath);
    $types = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    if (!is_array($info) || !isset($types[$info[2]])) {
        return ['error' => 'image_type', 'image' => null, 'extension' => ''];
    }

    [$width, $height, $type] = [$info[0], $info[1], $info[2]];
    $extension = $types[$type];

    if ($width < CARD_IMAGE_MIN_SIDE || $height < CARD_IMAGE_MIN_SIDE) {
        return ['error' => 'image_too_small', 'image' => null, 'extension' => ''];
    }

    if ($width > CARD_IMAGE_MAX_SIDE || $height > CARD_IMAGE_MAX_SIDE) {
        return ['error' => 'image_dimensions', 'image' => null, 'extension' => ''];
    }

    /*
     * getimagesize deutet die ersten Bytes. Der MIME-Typ aus finfo liest mehr und
     * muss zur erkannten Endung passen; sonst wäre es zum Beispiel ein PDF mit
     * passendem Anfang.
     */
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo === false ? false : finfo_file($finfo, $temporaryPath);

    if ($finfo !== false) {
        finfo_close($finfo);
    }

    $mimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!is_string($mime) || ($mimes[$mime] ?? null) !== $extension) {
        return ['error' => 'image_type', 'image' => null, 'extension' => ''];
    }

    $contents = @file_get_contents($temporaryPath);
    $image = $contents === false ? false : @imagecreatefromstring($contents);

    if (!$image instanceof \GdImage) {
        return ['error' => 'image_invalid', 'image' => null, 'extension' => ''];
    }

    $image = card_image_orient($image, $temporaryPath, $type);
    $image = card_image_scale_down($image);

    return ['error' => null, 'image' => $image, 'extension' => $extension];
}

/**
 * Dreht ein JPEG so, wie es das Handy aufgenommen hat.
 *
 * Die Kamera schreibt die Drehung nur in die EXIF-Daten; ohne diesen Schritt
 * läge ein Hochformatfoto quer. Nach dem Neuschreiben ist die Drehung in den
 * Bildpunkten und die EXIF-Daten sind weg.
 */
function card_image_orient(\GdImage $image, string $path, int $type): \GdImage
{
    if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
        return $image;
    }

    $exif = @exif_read_data($path);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    $angle = 0;

    if ($orientation === 3) {
        $angle = 180;
    } elseif ($orientation === 6) {
        $angle = -90;
    } elseif ($orientation === 8) {
        $angle = 90;
    }

    if ($angle === 0) {
        return $image;
    }

    $rotated = @imagerotate($image, $angle, 0);

    if (!$rotated instanceof \GdImage) {
        return $image;
    }

    imagedestroy($image);

    return $rotated;
}

/**
 * Verkleinert ein Bild, dessen längste Seite über CARD_IMAGE_TARGET_SIDE liegt.
 *
 * imagecopyresampled statt imagescale, weil imagescale das Ausgangsbild und die
 * verkleinerte Fassung gleichzeitig im Speicher hält. Hier ist nur die kleine
 * Fassung zusätzlich nötig; das große Bild wird danach sofort freigegeben.
 *
 * Die durchsichtige Fläche ist für PNG und WebP gedacht; bei JPEG zählt sie
 * nicht.
 */
function card_image_scale_down(\GdImage $image): \GdImage
{
    $width = imagesx($image);
    $height = imagesy($image);
    $longest = max($width, $height);

    if ($longest <= CARD_IMAGE_TARGET_SIDE) {
        return $image;
    }

    $scale = CARD_IMAGE_TARGET_SIDE / $longest;
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);

    if (!$target instanceof \GdImage) {
        return $image;
    }

    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);

    if ($transparent !== false) {
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    }

    imagecopyresampled(
        $target,
        $image,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $width,
        $height
    );

    imagedestroy($image);

    return $target;
}

/**
 * Schreibt das Bild in der Endung, die es hatte.
 *
 * @return bool Ob die Datei geschrieben wurde.
 */
function card_image_write(\GdImage $image, string $path, string $extension): bool
{
    if ($extension === 'jpg') {
        return imagejpeg($image, $path, 86);
    }

    if ($extension === 'png') {
        return imagepng($image, $path, 6);
    }

    if ($extension === 'webp' && function_exists('imagewebp')) {
        return imagewebp($image, $path, 86);
    }

    return false;
}

/**
 * Löscht eine alte Bilddatei, wenn der gespeicherte Name brauchbar ist.
 *
 * Ein Name, der nicht zum Muster passt, wird nicht angefasst: er könnte auf eine
 * Datei außerhalb des Foto-Ordners zeigen.
 */
function card_image_delete_file(?string $file): void
{
    $path = card_image_path($file);

    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}
