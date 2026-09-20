<?php

declare(strict_types=1);

/**
 * All interface text of the Learning App, in English and German.
 *
 * Every visible string lives here, so no German or English text is written
 * directly into a template or into JavaScript.
 *
 * The same array is handed to the browser as JSON (see public/index.php), so the
 * server and the browser translate from one single source of truth.
 *
 * Two kinds of key live here:
 *   - interface text (labels, buttons, headings)
 *   - display names for the known categories, so a category that is stored in
 *     English in the database can be shown in German as well. The database row
 *     is still the source of truth for which categories exist; only the wording
 *     comes from here.
 */

/**
 * Returns every translation, grouped by language code.
 *
 * @return array<string, array<string, string>>
 */
function learning_app_translations(): array
{
    return [
        'en' => [
            'app.title' => 'Lernkartei',
            'app.brand' => 'LERNKARTEI',
            'crumb.start' => 'START',

            // The newline is intentional: the heading is meant to break onto two
            // lines. JavaScript turns each line into its own masked element so
            // every line can reveal separately.
            'home.heading' => "Choose a\nlearning area",

            // The segment bar has no visible label any more, so it needs a
            // spoken one: this is what a screen reader announces for the bar.

            'tile.subcategories.one' => 'subcategory',
            'tile.subcategories.other' => 'subcategories',
            'tile.cards.one' => 'card',
            'tile.cards.other' => 'cards',
            /* Shown in a tile while nothing sits inside the category yet. */
            'tile.subcategories.none' => 'No subcategories yet',
            'tile.tooltip' => '{name} · {cards}',

            'sidebar.label' => 'Learning areas',
            'detail.subareas' => 'Subcategories',
            'detail.empty.title' => 'No subcategories yet',
            'detail.empty.hint' => 'This learning area does not have any subcategories yet.',
            'detail.addSubcategory' => '+ Add subcategory',
            'detail.notFound.title' => 'Not found',
            'detail.notFound.hint' => 'This entry does not exist (any more).',

            'area.subareas' => 'Subcategories',
            'area.open' => 'Open learning area: {name}',
            'area.iconAlt' => 'Icon of {name}',

            'scroller.previous' => 'Previous categories',
            'scroller.next' => 'Next categories',
            'footer.addAria' => 'Add learning area',
            'footer.addSubcategoryAria' => 'Add subcategory',
            'footer.addCardAria' => 'Add flashcard',
            'footer.editAria' => 'Edit this entry',
            'footer.edit' => 'Edit',

            'dialog.addTitle' => 'Add learning area',
            'dialog.nameLabel' => 'Name',
            'dialog.namePlaceholder' => 'e.g. History',
            'dialog.cancel' => 'Cancel',
            'dialog.save' => 'Save',
            'dialog.saving' => 'Saving …',
            'dialog.errorEmpty' => 'Please enter a name.',
            'dialog.errorTooLong' => 'The name may be at most 100 characters long.',
            'dialog.errorDuplicate' => 'A learning area with this name already exists.',
            'dialog.errorName' => 'This name cannot be used.',
            'dialog.errorServer' => 'The learning area could not be saved.',

            // --- Flashcards (the level below a subcategory) ---
            'cards.heading' => 'Flashcards',
            'cards.sectionTitle' => 'Flashcards in this area',
            'cards.open' => 'Open flashcards: {name}',
            'cards.empty.title' => 'No flashcards yet',
            'cards.empty.hint' => 'This subcategory does not hold any flashcards yet.',
            'cards.addCard' => '+ Add flashcard',
            'card.front' => 'Front',
            'card.back' => 'Back',
            'card.bidirectional' => 'Both directions',
            'card.pair' => '{front} · {back}',

            // --- Actions on an entry ---
            'action.edit' => 'Edit',
            'action.delete' => 'Delete',
            'action.more' => 'More actions for {name}',
            'action.removeIcon' => 'Remove icon',
            'action.iconStored' => 'An icon is stored',
            'action.clear' => 'Clear',

            // --- Category form (learning area or subcategory) ---
            'dialog.category.createArea' => 'Add learning area',
            'dialog.category.createSubcategory' => 'Add subcategory',
            'dialog.category.editArea' => 'Edit learning area',
            'dialog.category.editSubcategory' => 'Edit subcategory',
            'dialog.category.iconLabel' => 'Icon (SVG)',
            'dialog.category.iconHint' => 'Optional. One .svg file, at most 300 KB.',
            'dialog.category.nameEn' => 'Name (English)',
            'dialog.category.nameDe' => 'Name (German)',
            'dialog.category.nameHint' => 'Used when no translation is filled in.',
            'dialog.category.descriptionEn' => 'Description (English)',
            'dialog.category.descriptionDe' => 'Description (German)',
            'dialog.category.descriptionHint' => 'Optional. Shown in the lists.',
            'dialog.errorIcon' => 'This file could not be read as an SVG icon.',
            'dialog.errorScale' => 'The icon size must be between 0.2 and 3.',

            // --- Flashcard form ---
            'dialog.card.create' => 'Add flashcard',
            'dialog.card.edit' => 'Edit flashcard',
            'dialog.card.frontLabel' => 'Front',
            'dialog.card.backLabel' => 'Back',
            'dialog.card.frontPlaceholder' => 'e.g. What is 2 + 2?',
            'dialog.card.backPlaceholder' => 'e.g. 4',
            'dialog.card.bidirectionalLabel' => 'Practise in both directions',
            'dialog.errorFront' => 'Please enter the front of the card.',
            'dialog.errorBack' => 'Please enter the back of the card.',

            // --- Delete confirmation ---
            'dialog.delete.title' => 'Delete "{name}"?',
            'dialog.delete.consequence' => 'Everything below it is deleted as well: {parts}.',
            'dialog.delete.nothingBelow' => 'Nothing sits below it, so only this entry is removed.',
            'dialog.delete.subcategories.one' => '1 subcategory',
            'dialog.delete.subcategories.other' => '{count} subcategories',
            'dialog.delete.cards.one' => '1 flashcard',
            'dialog.delete.cards.other' => '{count} flashcards',
            'dialog.delete.confirmLabel' => 'Type the name to confirm',
            'dialog.delete.submit' => 'Delete',
            'dialog.delete.deleting' => 'Deleting …',
            'dialog.deleteCard.title' => 'Delete this flashcard?',
            'dialog.deleteCard.hint' => 'The card is removed. Its learning progress is removed with it.',

            // --- Empty states and messages of the new views ---
            'home.empty.title' => 'No learning areas yet',
            'home.empty.hint' => 'Create the first learning area to get started.',
            'state.loadingCards' => 'Loading flashcards …',
            'state.saving' => 'Saving …',
            'feedback.deleted' => '{name} was deleted.',
            'feedback.saved' => 'Saved.',
            'dialog.errorSave' => 'The entry could not be saved.',
            'dialog.errorDelete' => 'The entry could not be deleted.',
            'dialog.errorConfirmName' => 'The name does not match this entry.',

            'state.loading' => 'Loading learning areas …',            'state.error' => 'The learning areas could not be loaded. Please try again later.',
            'state.noscript' => 'JavaScript is required to load the learning areas.',
            'state.unavailable' => '—',

            'theme.switch.toDark' => 'Switch to dark mode',
            'theme.switch.toLight' => 'Switch to light mode',
            'language.label' => 'Language',

            // --- Shared dialog system ---
            'dialog.close' => 'Close',
            'dialog.optional' => 'Optional',
            'dialog.translations' => 'Translations (optional)',
            'dialog.translationsHint' => 'Shown when the interface is in that language.',
            'dialog.descriptionLabel' => 'Description',
            'dialog.descriptionPlaceholder' => 'Optional, one or a few lines',
            'dialog.descriptionHint' => 'Written to the description of the language that is switched on.',
            'dialog.required' => 'Required',
            'dialog.hintEscape' => 'Escape closes this dialog.',
            'dialog.hintEnter' => 'Enter saves.',

            // --- Field level validation ---
            'dialog.errorNameRequired' => 'Please enter a name.',
            'dialog.errorNameTooLong' => 'The name may be at most {max} characters long.',
            'dialog.errorNameDuplicate' => 'A category with this name already exists here.',
            'dialog.errorDescriptionTooLong' => 'The description may be at most {max} characters long.',
            'dialog.errorFrontRequired' => 'Please enter the front of the card.',
            'dialog.errorFrontTooLong' => 'The front may be at most {max} characters long.',
            'dialog.errorBackRequired' => 'Please enter the back of the card.',
            'dialog.errorBackTooLong' => 'The back may be at most {max} characters long.',
            'dialog.errorConfirmRequired' => 'Type the name to confirm.',

            // --- Icon upload ---
            'dialog.icon.uploadTitle' => 'Upload icon',
            'dialog.icon.uploadHint' => 'Click to choose an SVG, or drag and drop one here.',
            'dialog.icon.drop' => 'Drop the SVG here',
            'dialog.icon.replace' => 'Replace icon',
            'dialog.icon.remove' => 'Remove icon',
            'dialog.icon.preview' => 'Preview',
            'dialog.icon.onlySvg' => 'Only .svg files are accepted.',
            'dialog.icon.tooLarge' => 'The file is larger than {max} KB.',
            'dialog.icon.notSvg' => 'This file is not a readable SVG.',
            'dialog.icon.normalised' => 'The drawing was fitted to the icon circle automatically.',
            'dialog.icon.fallbackHint' => 'Without an icon the first letter of the name is shown.',
            'dialog.icon.fileChosen' => 'File chosen: {name}',

            // --- Edit mode and the tile menu ---
            'footer.done' => 'Done',
            'editMode.badge' => 'Edit mode',
            'editMode.hint' => 'Use the menu in the corner of a tile to edit or delete it.',
            'action.moreTile' => 'Actions for {name}',

            // --- Toasts after a successful save ---
            'feedback.created' => '{name} was created.',
            'feedback.updated' => '{name} was saved.',
            'feedback.iconRemoved' => 'The icon was removed.',

            'language.en' => 'EN',
            'language.de' => 'DE',
        ],

        'de' => [
            'app.title' => 'Lernkartei',
            'app.brand' => 'LERNKARTEI',
            'crumb.start' => 'START',

            // Two lines, same as the English heading.
            'home.heading' => "Wähle ein\nThemengebiet",

            // Spoken label for the segment bar (see the English block).

            'tile.subcategories.one' => 'Unterkategorie',
            'tile.subcategories.other' => 'Unterkategorien',
            'tile.cards.one' => 'Karte',
            'tile.cards.other' => 'Karten',
            /* Shown in a tile while nothing sits inside the category yet. */
            'tile.subcategories.none' => 'Noch keine Unterkategorien',
            'tile.tooltip' => '{name} · {cards}',

            'sidebar.label' => 'Themengebiete',
            'detail.subareas' => 'Unterkategorien',
            'detail.empty.title' => 'Noch keine Unterkategorien',
            'detail.empty.hint' => 'Dieses Themengebiet hat noch keine Unterkategorien.',
            'detail.addSubcategory' => '+ Unterkategorie hinzufügen',
            'detail.notFound.title' => 'Nicht gefunden',
            'detail.notFound.hint' => 'Dieser Eintrag existiert nicht (mehr).',

            'area.subareas' => 'Unterkategorien',
            'area.open' => 'Themengebiet öffnen: {name}',
            'area.iconAlt' => 'Symbol für {name}',

            'scroller.previous' => 'Vorherige Gebiete',
            'scroller.next' => 'Nächste Gebiete',
            'footer.addAria' => 'Themengebiet hinzufügen',
            'footer.addSubcategoryAria' => 'Unterkategorie hinzufügen',
            'footer.addCardAria' => 'Karteikarte hinzufügen',
            'footer.editAria' => 'Diesen Eintrag bearbeiten',
            'footer.edit' => 'Bearbeiten',

            'dialog.addTitle' => 'Themengebiet hinzufügen',
            'dialog.nameLabel' => 'Name',
            'dialog.namePlaceholder' => 'z. B. Geschichte',
            'dialog.cancel' => 'Abbrechen',
            'dialog.save' => 'Speichern',
            'dialog.saving' => 'Wird gespeichert …',
            'dialog.errorEmpty' => 'Bitte einen Namen eingeben.',
            'dialog.errorTooLong' => 'Der Name darf höchstens 100 Zeichen lang sein.',
            'dialog.errorDuplicate' => 'Ein Themengebiet mit diesem Namen existiert bereits.',
            'dialog.errorName' => 'Dieser Name kann nicht verwendet werden.',
            'dialog.errorServer' => 'Das Themengebiet konnte nicht gespeichert werden.',

            // --- Karteikarten (die Ebene unter einer Unterkategorie) ---
            'cards.heading' => 'Karteikarten',
            'cards.sectionTitle' => 'Karteikarten in diesem Themengebiet',
            'cards.open' => 'Karteikarten öffnen: {name}',
            'cards.empty.title' => 'Noch keine Karteikarten',
            'cards.empty.hint' => 'Diese Unterkategorie enthält noch keine Karteikarten.',
            'cards.addCard' => '+ Karteikarte hinzufügen',
            'card.front' => 'Vorderseite',
            'card.back' => 'Rückseite',
            'card.bidirectional' => 'Beide Richtungen',
            'card.pair' => '{front} · {back}',

            // --- Aktionen an einem Eintrag ---
            'action.edit' => 'Bearbeiten',
            'action.delete' => 'Löschen',
            'action.more' => 'Weitere Aktionen für {name}',
            'action.removeIcon' => 'Symbol entfernen',
            'action.iconStored' => 'Ein Symbol ist gespeichert',
            'action.clear' => 'Zurücksetzen',

            // --- Formular für eine Kategorie (Themengebiet oder Unterkategorie) ---
            'dialog.category.createArea' => 'Themengebiet hinzufügen',
            'dialog.category.createSubcategory' => 'Unterkategorie hinzufügen',
            'dialog.category.editArea' => 'Themengebiet bearbeiten',
            'dialog.category.editSubcategory' => 'Unterkategorie bearbeiten',
            'dialog.category.iconLabel' => 'Symbol (SVG)',
            'dialog.category.iconHint' => 'Optional. Eine .svg-Datei, höchstens 300 KB.',
            'dialog.category.nameEn' => 'Name (Englisch)',
            'dialog.category.nameDe' => 'Name (Deutsch)',
            'dialog.category.nameHint' => 'Wird verwendet, wenn keine Übersetzung eingetragen ist.',
            'dialog.category.descriptionEn' => 'Beschreibung (Englisch)',
            'dialog.category.descriptionDe' => 'Beschreibung (Deutsch)',
            'dialog.category.descriptionHint' => 'Optional. Wird in den Listen angezeigt.',
            'dialog.errorIcon' => 'Diese Datei konnte nicht als SVG-Symbol gelesen werden.',
            'dialog.errorScale' => 'Die Symbolgröße muss zwischen 0.2 und 3 liegen.',

            // --- Formular für eine Karteikarte ---
            'dialog.card.create' => 'Karteikarte hinzufügen',
            'dialog.card.edit' => 'Karteikarte bearbeiten',
            'dialog.card.frontLabel' => 'Vorderseite',
            'dialog.card.backLabel' => 'Rückseite',
            'dialog.card.frontPlaceholder' => 'z. B. Was ist 2 + 2?',
            'dialog.card.backPlaceholder' => 'z. B. 4',
            'dialog.card.bidirectionalLabel' => 'In beide Richtungen üben',
            'dialog.errorFront' => 'Bitte die Vorderseite eingeben.',
            'dialog.errorBack' => 'Bitte die Rückseite eingeben.',

            // --- Löschbestätigung ---
            'dialog.delete.title' => '„{name}“ löschen?',
            'dialog.delete.consequence' => 'Dabei wird auch alles darunter gelöscht: {parts}.',
            'dialog.delete.nothingBelow' => 'Darunter befindet sich nichts, es wird nur dieser Eintrag entfernt.',
            'dialog.delete.subcategories.one' => '1 Unterkategorie',
            'dialog.delete.subcategories.other' => '{count} Unterkategorien',
            'dialog.delete.cards.one' => '1 Karteikarte',
            'dialog.delete.cards.other' => '{count} Karteikarten',
            'dialog.delete.confirmLabel' => 'Zum Bestätigen den Namen eingeben',
            'dialog.delete.submit' => 'Löschen',
            'dialog.delete.deleting' => 'Wird gelöscht …',
            'dialog.deleteCard.title' => 'Diese Karteikarte löschen?',
            'dialog.deleteCard.hint' => 'Die Karte wird entfernt. Ihr Lernfortschritt wird mit entfernt.',

            // --- Leere Zustände und Meldungen der neuen Ansichten ---
            'home.empty.title' => 'Noch keine Themengebiete',
            'home.empty.hint' => 'Lege das erste Themengebiet an, um zu starten.',
            'state.loadingCards' => 'Karteikarten werden geladen …',
            'state.saving' => 'Wird gespeichert …',
            'feedback.deleted' => '„{name}“ wurde gelöscht.',
            'feedback.saved' => 'Gespeichert.',
            'dialog.errorSave' => 'Der Eintrag konnte nicht gespeichert werden.',
            'dialog.errorDelete' => 'Der Eintrag konnte nicht gelöscht werden.',
            'dialog.errorConfirmName' => 'Der Name stimmt nicht mit diesem Eintrag überein.',

            'state.loading' => 'Themengebiete werden geladen …',            'state.error' => 'Die Themengebiete konnten nicht geladen werden. Bitte später erneut versuchen.',
            'state.noscript' => 'JavaScript wird benötigt, um die Themengebiete zu laden.',
            'state.unavailable' => '—',

            'theme.switch.toDark' => 'Zum dunklen Modus wechseln',
            'theme.switch.toLight' => 'Zum hellen Modus wechseln',
            'language.label' => 'Sprache',

            // --- Gemeinsames Dialog-System ---
            'dialog.close' => 'Schließen',
            'dialog.optional' => 'Optional',
            'dialog.translations' => 'Übersetzungen (optional)',
            'dialog.translationsHint' => 'Wird angezeigt, wenn die Oberfläche in dieser Sprache läuft.',
            'dialog.descriptionLabel' => 'Beschreibung',
            'dialog.descriptionPlaceholder' => 'Optional, eine oder ein paar Zeilen',
            'dialog.descriptionHint' => 'Wird in die Beschreibung der eingeschalteten Sprache geschrieben.',
            'dialog.required' => 'Pflichtfeld',
            'dialog.hintEscape' => 'Escape schließt diesen Dialog.',
            'dialog.hintEnter' => 'Enter speichert.',

            // --- Feldweise Prüfung ---
            'dialog.errorNameRequired' => 'Bitte einen Namen eingeben.',
            'dialog.errorNameTooLong' => 'Der Name darf höchstens {max} Zeichen lang sein.',
            'dialog.errorNameDuplicate' => 'Hier gibt es bereits eine Kategorie mit diesem Namen.',
            'dialog.errorDescriptionTooLong' => 'Die Beschreibung darf höchstens {max} Zeichen lang sein.',
            'dialog.errorFrontRequired' => 'Bitte die Vorderseite eingeben.',
            'dialog.errorFrontTooLong' => 'Die Vorderseite darf höchstens {max} Zeichen lang sein.',
            'dialog.errorBackRequired' => 'Bitte die Rückseite eingeben.',
            'dialog.errorBackTooLong' => 'Die Rückseite darf höchstens {max} Zeichen lang sein.',
            'dialog.errorConfirmRequired' => 'Bitte den Namen zur Bestätigung eingeben.',

            // --- Symbol hochladen ---
            'dialog.icon.uploadTitle' => 'Symbol hochladen',
            'dialog.icon.uploadHint' => 'Klicken, um ein SVG auszuwählen, oder hierher ziehen.',
            'dialog.icon.drop' => 'SVG hier ablegen',
            'dialog.icon.replace' => 'Symbol ersetzen',
            'dialog.icon.remove' => 'Symbol entfernen',
            'dialog.icon.preview' => 'Vorschau',
            'dialog.icon.onlySvg' => 'Nur .svg-Dateien werden akzeptiert.',
            'dialog.icon.tooLarge' => 'Die Datei ist größer als {max} KB.',
            'dialog.icon.notSvg' => 'Diese Datei ist kein lesbares SVG.',
            'dialog.icon.normalised' => 'Die Zeichnung wurde automatisch an den Symbolkreis angepasst.',
            'dialog.icon.fallbackHint' => 'Ohne Symbol wird der erste Buchstabe des Namens gezeigt.',
            'dialog.icon.fileChosen' => 'Gewählte Datei: {name}',

            // --- Bearbeiten-Modus und Kachelmenü ---
            'footer.done' => 'Fertig',
            'editMode.badge' => 'Bearbeiten-Modus',
            'editMode.hint' => 'Über das Menü in der Ecke einer Kachel bearbeiten oder löschen.',
            'action.moreTile' => 'Aktionen für {name}',

            // --- Hinweise nach dem Speichern ---
            'feedback.created' => '„{name}“ wurde angelegt.',
            'feedback.updated' => '„{name}“ wurde gespeichert.',
            'feedback.iconRemoved' => 'Das Symbol wurde entfernt.',

            'language.en' => 'EN',
            'language.de' => 'DE',
        ],
    ];
}

/**
 * Looks up a single string.
 *
 * When a key is missing the key itself is returned, so a forgotten translation
 * shows up on the page during development instead of becoming an empty space.
 */
function t(string $locale, string $key): string
{
    static $translations = null;

    if ($translations === null) {
        $translations = learning_app_translations();
    }

    if (isset($translations[$locale][$key])) {
        return $translations[$locale][$key];
    }

    // Fall back to English, then to the key itself.
    return $translations['en'][$key] ?? $key;
}
