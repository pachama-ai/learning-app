/*
 * Lernkartei - der Karten-Browser
 *
 * Die Startseite fragt einen vorhandenen Endpunkt nach echten Daten und zeigt sie an:
 *   api/categories.php -> die Lernbereiche mit Farbe und Zählungen
 *
 * Hier wird nichts erfunden: jede Zahl auf der Seite ist ein Wert, den die API wirklich
 * zurückgegeben hat. Die Startseite fragt genau das ab, was sie zeigt, und sonst nichts.
 */
(function () {
    'use strict';

    /* Ein dünner Strichpfeil, direkt gezeichnet, damit keine eigene Symboldatei nötig
       ist. "currentColor" lässt ihn dem Erscheinungsbild folgen. */

    /* Der Pfeil, der in die Ablagefläche des Importdialogs zeigt. Wie jedes andere
       Symbol dieser Datei entsteht er mit innerHTML, weil die Zeichenkette eine
       Konstante dieses Skripts ist - ein Name oder ein Text aus der Datenbank ist das
       nie. */
    var UPLOAD_SVG = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M12 16V4"/><path d="M7.5 8.5 12 4l4.5 4.5"/><path d="M4.5 15.5v2A2.5 2.5 0 0 0 7 20h10a2.5 2.5 0 0 0 2.5-2.5v-2"/></svg>';

    var ARROW_SVG = '<svg class="arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M4 12h15M13 6l6 6-6 6"/></svg>';

    /*
     * Eine Platzhalterzeichnung gibt es nicht mehr. Eine Kategorie ohne eigene Zeichnung
     * zeigt den ersten Buchstaben des Namens, unter dem sie erscheint - in der Kachel
     * genauso wie in der Vorschau des Uploadfelds (siehe fillIconCircle).
     */
    /*
     * Hier steht ABSICHTLICH keine Tabelle der Kategorien.
     *
     * Eine frühere Fassung trug eine Liste der bekannten Lernbereiche mit ihrem Namen,
     * ihrer Symboldatei, ihrer Symbolgröße, ihrer Farbe und ihrem Wortlaut in beiden
     * Sprachen. Jeder dieser Werte ist heute eine Spalte der Tabelle `categories` und wird
     * über die API gelesen:
     *
     *   der Name einer Kategorie -> name, name_en, name_de
     *   ihre Zeichnung           -> icon_svg, ausgeliefert von api/category_icon.php
     *   die Größe der Zeichnung  -> icon_scale
     *   ihre Farbe               -> color
     *   wie viel darin liegt     -> subcategory_count, card_count (Zählungen in SQL)
     *
     * Nur zwei Dinge stehen hier noch: die Form des Pfeils und der neutrale Platzhalter
     * oben. Keines von beiden sind Daten der Anwendung.
     */

    var config = JSON.parse(document.getElementById('app-config').textContent);
    var translations = config.translations || {};
    var locale = config.defaultLocale;
    var theme = 'light';

    /* Antworten werden für diesen Seitenaufbau behalten, ein Sprachwechsel schickt also
       nicht dieselbe Anfrage noch einmal. Geleert, sobald ein neuer Bereich angelegt
       wird. */
    var responseCache = {};

    /* ----------------------------------------------------------------------
       Der erste Aufbau: die ganze Anwendung in einer Antwort
       ---------------------------------------------------------------------- */

    /*
     * api/bootstrap.php schickt die Lernbereiche, ihre Unterkategorien und jede Karte in
     * einer Antwort. Sie wird hier behalten, und die Ansichten entstehen daraus - das
     * Durchwandern der Anwendung kostet also keine weitere Anfrage.
     *
     * Was absichtlich NICHT darin steckt: die erzeugte Aufgabe einer Übungskarte (ihre
     * Zahlen müssen bei jedem Anzeigen neu sein) und die Zeichnungen der Kategorien und
     * Kartenbilder (die haben ihren eigenen Abruf und ihren eigenen Speicher). Beides
     * wird geholt, wenn es wirklich gebraucht wird.
     */
    var bootstrapCache = {
        promise: null,       // die erste Abfrage
        pending: false,      // laeuft sie gerade?
        known: {},           // welche Kategorien der Bootstrap abdeckt
        areas: null,         // dieselbe Form wie GET /api/categories.php
        children: {},        // "2" -> Liste der Unterkategorien
        cards: {},           // "85" -> Liste der Karten
        summaries: {},       // "85" -> Zaehlung (total/due/known/unsure)
        contentLanguages: [],
        streak: null,        // {"available":true,"days":3} - Tage in Folge, ganze Person
        streaks: null,       // "101" -> dasselbe je Unterkategorie (null, solange die Spalte fehlt)
        hasUser: false
    };

    /*
     * Lädt den Aufbau einmal je Seitenansicht. Ein Fehlschlag ist kein Problem: das
     * Versprechen wird fallengelassen, und jede Ansicht lädt ihre Daten genau wie vorher
     * selbst.
     */
    function loadBootstrap() {
        if (bootstrapCache.promise !== null) {
            return bootstrapCache.promise;
        }

        bootstrapCache.pending = true;

        bootstrapCache.promise = fetchJson(config.endpoints.bootstrap + '?language=' + encodeURIComponent(locale))
            .then(function (data) {
                bootstrapCache.pending = false;
                bootstrapCache.areas = Array.isArray(data.areas) ? data.areas : [];
                bootstrapCache.children = data.children && typeof data.children === 'object' ? data.children : {};
                bootstrapCache.cards = data.cards && typeof data.cards === 'object' ? data.cards : {};
                bootstrapCache.summaries = data.summaries && typeof data.summaries === 'object' ? data.summaries : {};
                bootstrapCache.contentLanguages = Array.isArray(data.content_languages) ? data.content_languages : [];
                bootstrapCache.streak = data.streak && typeof data.streak === 'object' ? data.streak : null;
                bootstrapCache.streaks = data.streaks && typeof data.streaks === 'object' ? data.streaks : null;
                bootstrapCache.hasUser = data.has_user === true;

                /*
                 * Welche Kategorien diese Antwort wirklich abdeckt - auch die ohne eigene
                 * Karten. Die Kartenabfrage hinter dieser Antwort hatte keinen Filter, eine
                 * in der Kartenliste fehlende Kategorie hat also wirklich keine Karten: die
                 * leere Liste ist die Antwort, keine Anfrage.
                 */
                bootstrapCache.known = {};

                bootstrapCache.areas.forEach(function (area) {
                    bootstrapCache.known[String(area.id)] = true;
                });

                Object.keys(bootstrapCache.children).forEach(function (parentId) {
                    bootstrapCache.children[parentId].forEach(function (child) {
                        bootstrapCache.known[String(child.id)] = true;
                    });
                });

                /* Die Stellen, die schon Kategorien laden, finden ihre Antwort im Speicher:
                   keine zweite Anfrage für etwas, das schon da ist. */
                responseCache[''] = bootstrapCache.areas;

                Object.keys(bootstrapCache.children).forEach(function (parentId) {
                    responseCache['?parent_id=' + parentId] = bootstrapCache.children[parentId];
                });

                return bootstrapCache;
            })
            .catch(function () {
                bootstrapCache.pending = false;
                bootstrapCache.promise = null;

                return null;
            });

        return bootstrapCache.promise;
    }

    /* Eine einzelne Kategorie aus dem Aufbau. Nur eine Id, die nicht darin steht (eine
       Adresse, die es nicht gibt), wird einzeln geholt. */
    function fetchCategoryOne(categoryId) {
        if (bootstrapCache.pending) {
            return bootstrapCache.promise.then(function () {
                return fetchCategoryOne(categoryId);
            });
        }

        var cached = findCachedCategory(categoryId);

        if (cached !== null) {
            return Promise.resolve({ ok: true, status: 200, data: cached });
        }

        return apiRequest(config.endpoints.categories + '?id=' + encodeURIComponent(categoryId), 'GET');
    }

    function findCachedCategory(categoryId) {
        var wanted = Number(categoryId);
        var lists = [bootstrapCache.areas === null ? [] : bootstrapCache.areas];

        Object.keys(bootstrapCache.children).forEach(function (parentId) {
            lists.push(bootstrapCache.children[parentId]);
        });

        for (var i = 0; i < lists.length; i++) {
            for (var j = 0; j < lists[i].length; j++) {
                if (Number(lists[i][j].id) === wanted) {
                    return lists[i][j];
                }
            }
        }

        return null;
    }

    /*
     * Die Karten einer Kategorie, in der Form, die api/cards.php zurückgibt.
     *
     * Eine Liste, die aus dem Aufbau kommt, hat Übungskarten ohne Aufgabe: diese Zahlen
     * werden gezogen, während die Karte gelesen wird, sie dürfen also nicht in einem
     * Speicher liegen. Sie werden in EINER kleinen Anfrage geholt, bevor die Liste
     * gezeichnet wird.
     */
    /*
     * Die Tage in Folge EINER Unterkategorie.
     *
     * Die Zahl gehört zu der Unterkategorie, deren Seite offen ist, und nicht zur ganzen
     * Person: drei Tage in "Übungsaufgaben" sind nicht drei Tage in "Einmaleins".
     *
     * Eine Unterkategorie ohne einen einzigen Durchgang hat keine eigenen - und das ist
     * nicht dasselbe wie "keine Zahlen", sie antwortet deshalb mit "hier noch nichts
     * gelernt" statt auf die Zahl einer anderen Liste zurückzufallen. Nur wenn der Server
     * gar keine Zuordnung schickt (die Kategoriespalte von study_sessions gibt es noch
     * nicht, siehe database/add_session_category.sql), gilt die Zahl der ganzen Person,
     * genau wie vor dieser Migration.
     */
    function streakForCategory(categoryId) {
        if (bootstrapCache.streaks === null) {
            return bootstrapCache.streak;
        }

        var entry = bootstrapCache.streaks[String(categoryId)];

        return entry === undefined ? { available: false, days: 0 } : entry;
    }

    function fetchCards(categoryId) {
        if (bootstrapCache.pending) {
            return bootstrapCache.promise.then(function () {
                return fetchCards(categoryId);
            });
        }

        var cached = bootstrapCache.cards[String(categoryId)];

        /* Eine Kategorie, die die erste Antwort abdeckte, die aber keine eigenen Karten
           hat: ein Bereich zum Beispiel. Dann ist die leere Liste die Antwort und keine
           Anfrage. */
        if (cached === undefined && bootstrapCache.known[String(categoryId)] === true) {
            return Promise.resolve({
                ok: true,
                status: 200,
                data: {
                    cards: [],
                    summary: null,
                    /* Die Tage in Folge kommen aus demselben Zwischenspeicher wie
                       die Karten: die Kachel braucht sie beim ersten Aufbau. */
                    streak: streakForCategory(categoryId),
                    has_user: bootstrapCache.hasUser,
                    content_languages: bootstrapCache.contentLanguages,
                    language: locale
                }
            });
        }

        if (cached === undefined) {
            return apiRequest(config.endpoints.cards + '?category_id=' + encodeURIComponent(categoryId)
                + '&language=' + encodeURIComponent(locale), 'GET').then(function (result) {
                /*
                 * Die Antwort trägt die Serie mit. Die neueste behalten, damit eine
                 * spätere Ansicht aus dem Aufbau nicht eine ältere Zahl zeigt als die, die
                 * gerade vom Server kam.
                 */
                if (result.ok && result.data !== null && typeof result.data === 'object'
                    && result.data.streak !== null && typeof result.data.streak === 'object') {
                    /*
                     * Die Antwort betrifft eine Unterkategorie, sie gehört also in die
                     * Zuordnung. Ohne Zuordnung (die Kategoriespalte gibt es noch nicht) ist
                     * die Zahl die der ganzen Person.
                     */
                    if (bootstrapCache.streaks === null) {
                        bootstrapCache.streak = result.data.streak;
                    } else {
                        bootstrapCache.streaks[String(categoryId)] = result.data.streak;
                    }
                }

                return result;
            });
        }

        var withoutTask = cached.filter(function (card) {
            return card.exercise !== null && typeof card.exercise === 'object' && card.exercise.task === undefined;
        });

        var ready = withoutTask.length === 0
            ? Promise.resolve(true)
            : fetchExerciseTasks(withoutTask);

        return ready.then(function () {
            return {
                ok: true,
                status: 200,
                data: {
                    cards: cached,
                    summary: bootstrapCache.summaries[String(categoryId)] || null,
                    /* Die Serie kommt aus demselben Speicher wie die Karten - siehe die
                       leere Liste oben. */
                    streak: streakForCategory(categoryId),
                    has_user: bootstrapCache.hasUser,
                    content_languages: bootstrapCache.contentLanguages,
                    language: locale
                }
            };
        });
    }

    /* Frische Zahlen für die Übungskarten einer Liste, in einer Anfrage. */
    function fetchExerciseTasks(cards) {
        var items = cards.slice(0, 50).map(function (card) {
            return {
                exercise_type: card.exercise.type,
                exercise_params: card.exercise.params
            };
        });

        return apiRequest(config.endpoints.exercisePreview, 'POST', { items: items })
            .then(function (result) {
                var tasks = result.ok && result.data && Array.isArray(result.data.tasks) ? result.data.tasks : null;

                if (tasks === null) {
                    return false;
                }

                cards.forEach(function (card, index) {
                    if (index < tasks.length && tasks[index] !== null && typeof tasks[index] === 'object') {
                        card.exercise.task = tasks[index];
                    }
                });

                return true;
            });
    }

    /* ----------------------------------------------------------------------
       Was ein Schreibvorgang aus dem Speicher nimmt - und was er darin lässt
       ---------------------------------------------------------------------- */

    /*
     * Ein Schreibvorgang ändert höchstens zwei Dinge: die Karten einer Kategorie und die
     * Zähler des Kategoriebaums. Alles andere bleibt im Speicher, die nächste Ansicht
     * entsteht also weiterhin ohne Anfrage.
     */
    function bootstrapDropCards(categoryId) {
        if (categoryId === null || categoryId === undefined) {
            return;
        }

        delete bootstrapCache.cards[String(categoryId)];
        delete bootstrapCache.summaries[String(categoryId)];
        delete bootstrapCache.known[String(categoryId)];
    }

    function bootstrapDropCategories() {
        /*
         * Ein Elternteil, der in der Antwort Unterkategorien hatte, wird wieder unbekannt:
         * nur ein Elternteil, der nie welche hatte, darf ohne Nachfragen mit einer leeren
         * Liste antworten.
         */
        Object.keys(bootstrapCache.children).forEach(function (parentId) {
            delete bootstrapCache.known[parentId];
        });

        bootstrapCache.areas = null;
        bootstrapCache.children = {};

        Object.keys(responseCache).forEach(function (key) {
            if (key === '' || key.indexOf('?parent_id=') === 0) {
                delete responseCache[key];
            }
        });
    }

    /*
     * Für Änderungen, die viele Zeilen auf einmal treffen (ein Import) oder deren Ausgang
     * unklar ist (eine Zeile, die woanders schon weg war). Dann darf nichts als gegeben
     * gelten, und die nächste Ansicht lädt neu, was sie braucht.
     */
    function bootstrapDropAll() {
        responseCache = {};
        bootstrapCache.areas = null;
        bootstrapCache.children = {};
        bootstrapCache.cards = {};
        bootstrapCache.summaries = {};
        bootstrapCache.known = {};

        /* Das Versprechen bleibt: der Aufbau selbst wird nicht für jeden einzelnen
           Schreibvorgang erneut erfragt. Der nächste volle Seitenaufbau fängt mit einem
           frischen an. */
        bootstrapCache.promise = null;
    }

    /* Gesetzt, wenn gerade ein neuer Bereich angelegt wurde, damit seine Kachel
       hereingeblendet werden kann. */
    var newAreaId = null;

    /* Die Kacheln der aktuellen Startseite, nach Position verknüpft für den Hover. */
    var linkedTiles = [];

    /*
     * Was die Detailansicht gerade zeigt. "currentEntry" ist die Kategorie, zu der die
     * Seite gehört, damit der Plus-Knopf, der Bearbeiten-Knopf und die Dialogtitel alle
     * die Frage "worauf würde das wirken?" beantworten können.
     */
    var currentEntry = null;
    var currentEntryCards = [];

    /*
     * Was die Kartenliste der geöffneten Unterkategorie weiß: die Karten selbst, die
     * Zählungen der API und den Text im Suchfeld. Die Liste wird im Browser gefiltert und
     * für eine Suche nie neu geladen - der Filter sieht nur an, was schon da ist.
     */
    var openCards = [];
    var openCardSummary = null;
    var openCardStreak = null;
    var cardSearchQuery = '';
    var cardSearchMin = 15;

    /* Merkt sich ein "Speichern und weiter", damit der Dialog danach wieder aufgehen kann. */
    var cardSaveAndNext = false;
    var openMenu = null;
    var feedbackTimer = null;

    /*
     * Wie lange ein Löschen noch zurückgenommen werden kann.
     *
     * In diesem Zeitfenster geht nichts an den Server, "Rückgängig" nimmt also wirklich
     * zurück: die Zeile hat die Datenbank nie verlassen.
     */
    var UNDO_WINDOW_MS = 6500;

    /*
     * Das Löschen, das gerade wartet, oder null:
     *   { kind, target, url, timer }
     * Es kann immer nur eines warten - ein zweites führt das erste zu Ende.
     */
    var pendingDelete = null;

    /*
     * Der offene Importdialog: die gewählte Datei und alles, was der Server darüber
     * geantwortet hat. Er ist null, solange kein Importdialog offen ist.
     */
    var importState = null;
    var importPanel = null;

    /* Was der leere Zustand anbietet, wenn es nichts zu zeigen gibt. */
    var entryEmptyHandler = null;


    var elements = {
        page: document.querySelector('.page'),
        crumb: document.getElementById('crumb'),
        homeView: document.getElementById('view-home'),
        detailView: document.getElementById('view-detail'),
        homeHeading: document.getElementById('home-heading'),
        homeHeader: document.getElementById('home-header'),
        detailHeading: document.getElementById('detail-heading'),
        detailStats: document.getElementById('detail-stats'),
        detailCount: document.getElementById('detail-count'),
        detailDot: document.getElementById('detail-dot'),
        grid: document.getElementById('area-grid'),
        tiles: document.getElementById('tiles'),
        tilesNav: document.getElementById('tiles-nav'),
        tilesTrack: document.getElementById('tiles-nav-track'),
        tilesThumb: document.getElementById('tiles-nav-thumb'),
        /* Die zwei Pfeile sitzen in der Fußzeile; die Hülle um sie herum ist in jeder
           Ansicht ohne Kachelreihe versteckt. */
        tilesButtons: document.getElementById('tiles-nav-buttons'),
        tilesPrev: document.getElementById('tiles-nav-prev'),
        tilesNext: document.getElementById('tiles-nav-next'),
        sidebarNav: document.getElementById('sidebar-nav'),
        loading: document.getElementById('loading-state'),
        error: document.getElementById('error-state'),
        empty: document.getElementById('empty-state'),
        emptyTitle: document.getElementById('empty-title'),
        emptyHint: document.getElementById('empty-hint'),
        addButton: document.getElementById('add-button'),
        themeToggle: document.getElementById('theme-toggle'),
        langUnderline: document.getElementById('lang-underline'),
        localeButtons: document.querySelectorAll('[data-locale]'),
        emptyAction: document.getElementById('empty-action'),
        emptyActionSecondary: document.getElementById('empty-action-secondary'),

        entryList: document.getElementById('entry-list'),
        entryEmpty: document.getElementById('entry-empty'),
        entryEmptyBlob: document.getElementById('entry-empty-blob'),
        entryEmptyTitle: document.getElementById('entry-empty-title'),
        entryEmptyHint: document.getElementById('entry-empty-hint'),
        entryEmptyAction: document.getElementById('entry-empty-action'),
        areaCards: document.getElementById('area-cards'),
        areaCardList: document.getElementById('area-card-list'),

        detailActions: document.getElementById('detail-actions'),
        detailBlob: document.getElementById('detail-blob'),
        dashboard: document.getElementById('detail-dashboard'),
        dashDue: document.getElementById('dash-due-value'),
        dashKnown: document.getElementById('dash-known-value'),
        dashKnownFill: document.getElementById('dash-known-fill'),
        dashUnsure: document.getElementById('dash-unsure-value'),
        dashStreak: document.getElementById('dash-streak-value'),
        dashStreakNote: document.getElementById('dash-streak-note'),
        detailFigureCount: document.getElementById('detail-figure-count'),
        detailFigureCards: document.getElementById('detail-figure-cards'),
        detailCardCount: document.getElementById('detail-card-count'),
        detailCardLabel: document.getElementById('detail-card-label'),
        statLabel: document.getElementById('detail-stat-label'),

        /* Der eine Dialog. Seine Felder entstehen, während er sich öffnet. */
        dialog: document.getElementById('app-dialog'),
        dialogForm: document.getElementById('app-dialog-form'),
        dialogTitle: document.getElementById('app-dialog-title'),
        dialogMessage: document.getElementById('app-dialog-message'),
        dialogFields: document.getElementById('app-dialog-fields'),
        dialogError: document.getElementById('app-dialog-error'),
        dialogShortcuts: document.getElementById('app-dialog-shortcuts'),
        dialogClose: document.getElementById('app-dialog-close'),
        dialogSubmit: document.getElementById('app-dialog-submit'),

        /* Die kurze Meldung, ihr Text und der Knopf, der dazugehören kann. */
        feedback: document.getElementById('feedback'),
        feedbackText: document.getElementById('feedback-text'),
        feedbackAction: document.getElementById('feedback-action'),

        /* Das Konto in der Kopfzeile, sein Fenster und die leise Zeile. */
        accountSlot: document.getElementById('account-slot'),
        pageNote: document.getElementById('page-note'),
        accountDialog: document.getElementById('account-dialog'),
        accountList: document.getElementById('account-list'),
        accountViewData: document.getElementById('account-view-data'),
        accountViewConfirm: document.getElementById('account-view-confirm'),
        accountClose: document.getElementById('account-dialog-close'),
        accountDeleteOpen: document.getElementById('account-delete-open'),
        accountCancel: document.getElementById('account-cancel'),
        accountConfirm: document.getElementById('account-confirm'),
        accountPassword: document.getElementById('account-password'),
        /* Die Frage vor dem Start einer Einheit. */
        newCardsDialog: document.getElementById('new-cards-dialog'),
        newCardsHint: document.getElementById('new-cards-dialog-hint'),
        newCardsQuick: document.getElementById('new-cards-dialog-quick'),
        newCardsInput: document.getElementById('new-cards-dialog-input'),
        newCardsError: document.getElementById('new-cards-dialog-error'),
        newCardsStart: document.getElementById('new-cards-dialog-start'),
        newCardsCancel: document.getElementById('new-cards-dialog-cancel'),
        newCardsClose: document.getElementById('new-cards-dialog-close'),
        accountError: document.getElementById('account-error'),

        /* Das Band über den Zeilen einer Unterkategorie: es trägt das Suchfeld. */
        cardTools: document.getElementById('card-tools'),
        cardSearchWrap: document.getElementById('card-tools-search'),
        cardSearch: document.getElementById('card-search'),
        cardSearchEmpty: document.getElementById('card-search-empty'),
        learnButton: document.getElementById('learn-button'),
        importButton: document.getElementById('import-button'),
        learnLabel: document.getElementById('learn-button-label'),
        addEntryButton: document.getElementById('add-entry-button'),

        /* Die Lerneinheit. */
        learn: document.getElementById('learn'),
        learnStage: document.getElementById('learn-stage'),
        learnCard: document.getElementById('learn-card'),
        learnCounter: document.getElementById('learn-counter'),
        learnProgress: document.getElementById('learn-progress'),
        learnProgressFill: document.getElementById('learn-progress-fill'),
        learnSideLabel: document.getElementById('learn-side-label'),
        learnFrontText: document.getElementById('learn-front-text'),
        learnBackText: document.getElementById('learn-back-text'),
        learnHint: document.getElementById('learn-hint'),
        learnRating: document.getElementById('learn-rating'),
        learnButtons: document.getElementById('learn-buttons'),
        learnSummary: document.getElementById('learn-summary'),
        learnSummaryNumber: document.getElementById('learn-summary-number'),
        learnSummaryBars: document.getElementById('learn-summary-bars'),
        learnSummaryLeft: document.getElementById('learn-summary-left'),
        learnRepeat: document.getElementById('learn-repeat'),
        learnFinish: document.getElementById('learn-finish'),
        learnAsk: document.getElementById('learn-ask'),
        learnAskText: document.getElementById('learn-ask-text'),
        learnAskCancel: document.getElementById('learn-ask-cancel'),
        learnAskConfirm: document.getElementById('learn-ask-confirm'),
        learnClose: document.getElementById('learn-close'),
        learnNotice: document.getElementById('learn-notice'),
        /* Die zwei Seiten der Lernkarte können ein Kartenbild tragen. */
        learnMapFront: document.getElementById('learn-map-front'),
        learnMapBack: document.getElementById('learn-map-back'),

        /* Der zweite Knopf des Kartendialogs. */
        dialogSaveNext: document.getElementById('app-dialog-save-next')
    };

    /* ----------------------------------------------------------------------
       Small helpers
       ---------------------------------------------------------------------- */

    function prefersReducedMotion() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function t(key, replacements) {
        var table = translations[locale] || {};
        var fallback = translations[config.defaultLocale] || {};
        var value = typeof table[key] === 'string' ? table[key] : fallback[key];

        if (typeof value !== 'string') {
            value = key;
        }

        if (replacements) {
            Object.keys(replacements).forEach(function (token) {
                value = value.split('{' + token + '}').join(replacements[token]);
            });
        }

        return value;
    }

    function readStorage(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeStorage(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            /* Die Seite arbeitet weiter, es fehlt nur die Erinnerung. */
        }
    }

    /* Zwei Ziffern mit führender Null: aus 8 wird "08". */
    function pad2(value) {
        return value < 10 ? '0' + value : String(value);
    }

    /*
     * Wie eine Zeile auf der Seite heißt.
     *
     * Eine Kategorie wird von der Datenbank benannt: `categories.name_<language>` wird
     * benutzt, wenn darin etwas steht, sonst `categories.name`. Nichts in dieser Datei
     * kennt den Namen einer Kategorie, ein Umbenennen in der Datenbank benennt die Zeile
     * also auch auf der Seite - in der Sprache, die gerade eingeschaltet ist.
     *
     * Eine Karte hat keine Namensspalte. Erkannt wird sie an ihrer Vorderseite, die wird
     * hier also benutzt. Sie muss vorher geglättet und gekürzt werden, denn eine
     * Vorderseite kann ein ganzer Satz sein und dieser Name steht in anderen Sätzen in
     * Anführungszeichen ("... löschen?", "... wurde gelöscht.").
     */
    function displayName(row) {
        var translated = row['name_' + locale];

        if (typeof translated === 'string' && translated !== '') {
            return translated;
        }

        if (typeof row.name === 'string' && row.name !== '') {
            return row.name;
        }

        return shortLabel(row.front);
    }

    /*
     * Ein Text, auf eine Zeile mit höchstens 90 Zeichen gebracht.
     *
     * An einem Leerzeichen geschnitten, wenn eines in der Nähe des Endes liegt, damit
     * kein Wort zerrissen wird, und mit Auslassungspunkten markiert, damit klar ist, dass
     * etwas fehlt.
     *
     * Alles, was keine Zeichenkette ist, wird zu einer leeren Zeichenkette. Das ist
     * wichtig: ein `undefined`, das an t() gereicht wird, würde als Komma in den Satz
     * eingefügt, weil String#split(...).join(undefined) auf das voreingestellte
     * Trennzeichen zurückfällt - so kam ein Dialogtitel zustande, der 'Löschen ","?'
     * las.
     */
    function shortLabel(text) {
        if (typeof text !== 'string') {
            return '';
        }

        var flat = text.replace(/\s+/g, ' ').trim();

        if (flat.length <= 90) {
            return flat;
        }

        var cut = flat.slice(0, 90);
        var lastSpace = cut.lastIndexOf(' ');

        if (lastSpace > 60) {
            cut = cut.slice(0, lastSpace);
        }

        return cut.replace(/[ ,;.]+$/, '') + '\u2026';
    }


    /*
     * Alles, was die Seite braucht, um einen Lernbereich zu zeichnen.
     *
     * Die Zeichnung kommt über api/category_icon.php aus der Datenbank. Nur eine
     * Kategorie ohne eigene Zeichnung fällt auf den neutralen Platzhalter zurück; eine
     * Symboldatei wird nie über ihren Namen gewählt.
     */
    function categoryMeta(row) {
        var scale = typeof row.icon_scale === 'number' ? row.icon_scale : 1;

        if (!(scale >= 0.2 && scale <= 3)) {
            scale = 1;
        }

        /*
         * null heißt "diese Kategorie hat keine eigene Zeichnung". Der Kreis zeigt dann den
         * ersten Buchstaben des Namens, statt leer zu bleiben.
         */
        return {
            title: displayName(row),
            icon: typeof row.icon_url === 'string' && row.icon_url !== '' ? row.icon_url : null,
            iconScale: scale
        };
    }

    /*
     * Einen Farb-Helfer gibt es absichtlich nicht mehr.
     *
     * Die Spalte `color` steht noch in der Datenbank, aber nichts in dieser Anwendung
     * liest, schreibt oder zeigt sie: eine Kategorie ist von der Gestaltung her neutral,
     * und die Kacheln des hellen Erscheinungsbilds nehmen ihre Farbe aus ihrer Position in
     * der Reihe (siehe das Stylesheet, --palette-1 .. --palette-8).
     */

    /*
     * Die Informationszeile einer Kachel. Jede Zahl darin wurde von der Datenbank gezählt:
     *
     *   8 Unterkategorien              - was wirklich darin liegt
     *   8 Unterkategorien · 24 Karten  - plus die Karten, sobald es welche gibt
     *   Noch keine Unterkategorien     - der leere Zustand, in der aktuellen Sprache
     *
     * Hier kein pad2: das ist Fließtext und kein Zähler, und eine Null wird nie als Zahl
     * gezeigt.
     */
    function tileDataLine(area) {
        if (area.subcategory_count === 0) {
            return t('tile.subcategories.none');
        }

        var line = area.subcategory_count + ' ' + t(area.subcategory_count === 1 ? 'tile.subcategories.one' : 'tile.subcategories.other');

        if (area.card_count > 0) {
            line += ' \u00B7 ' + area.card_count + ' ' + t(area.card_count === 1 ? 'tile.cards.one' : 'tile.cards.other');
        }

        return line;
    }

    /* ----------------------------------------------------------------------
       Uebersetzung
       ---------------------------------------------------------------------- */

    function translateStaticText() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n]'), function (node) {
            node.textContent = t(node.getAttribute('data-i18n'));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-label]'), function (node) {
            node.setAttribute('aria-label', t(node.getAttribute('data-i18n-label')));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-placeholder]'), function (node) {
            node.setAttribute('placeholder', t(node.getAttribute('data-i18n-placeholder')));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-title]'), function (node) {
            node.setAttribute('title', t(node.getAttribute('data-i18n-title')));
        });

        /* Der Zähler trägt Zahlen, er wird also neu geschrieben statt übersetzt. */
        updateTileNavigation();
    }

    /* ----------------------------------------------------------------------
       Ueberschriften: eine beschnittene Maske je Zeile, jede Zeile gleitet fuer sich hoch
       ---------------------------------------------------------------------- */

    function setHeading(element, text) {
        var lines = String(text).split('\n');

        /*
         * Ein langer Titel bekommt eine eigene Klasse und wird dann eine Stufe
         * kleiner gesetzt, statt abgeschnitten zu werden. Die Grenze liegt bei
         * 32 Zeichen: darueber passt ein Name wie "Electricity and Electrical
         * Engineering" auch auf 1440 px nicht mehr in eine Zeile.
         */
        element.classList.toggle('heading--long', String(text).length > 32);

        element.textContent = '';

        lines.forEach(function (line, index) {
            var mask = document.createElement('span');
            mask.className = 'heading__mask';

            var inner = document.createElement('span');
            inner.className = 'heading__line';
            inner.style.setProperty('--line-index', String(index));
            inner.textContent = line;

            mask.appendChild(inner);
            element.appendChild(mask);
        });
    }

    /* ----------------------------------------------------------------------
       Animationen
       ---------------------------------------------------------------------- */

    function applyRevealOrder(nodes, startIndex) {
        Array.prototype.forEach.call(nodes, function (node, index) {
            node.style.setProperty('--reveal-index', String(startIndex + index));
        });
    }

    /*
     * Zählt eine Zahl von null auf den Wert hoch, den die API wirklich zurückgegeben hat.
     * Die Detailansicht benutzt das für ihre Unterkategorie-Zahl.
     */
    function animateCount(element, value) {
        var target = Number(value) || 0;

        if (prefersReducedMotion()) {
            element.textContent = String(target);
            return;
        }

        /* Während des Zählens für Hilfstechnik versteckt, damit eine Vorlesehilfe den
           Endwert einmal nennt und nicht jeden Zwischenschritt. */
        element.setAttribute('aria-hidden', 'true');

        var start = null;
        var duration = 900;

        function step(timestamp) {
            if (start === null) {
                start = timestamp;
            }

            var progress = Math.min((timestamp - start) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);

            element.textContent = String(Math.round(target * eased));

            if (progress < 1) {
                window.requestAnimationFrame(step);
                return;
            }

            element.textContent = String(target);
            element.removeAttribute('aria-hidden');
        }

        window.requestAnimationFrame(step);
    }

    /* Dünne Linien-Gerüste, während die Bereiche laden. */
    function showSkeletons(container, count) {
        container.textContent = '';

        for (var index = 0; index < count; index++) {
            var skeleton = document.createElement('div');
            skeleton.className = 'skeleton';
            skeleton.setAttribute('aria-hidden', 'true');

            var blob = document.createElement('span');
            blob.className = 'skeleton__blob';

            var lineOne = document.createElement('span');
            lineOne.className = 'skeleton__line';

            var lineTwo = document.createElement('span');
            lineTwo.className = 'skeleton__line skeleton__line--short';

            skeleton.appendChild(blob);
            skeleton.appendChild(lineOne);
            skeleton.appendChild(lineTwo);
            container.appendChild(skeleton);
        }
    }

    /* ----------------------------------------------------------------------
       Verknuepfter Hover zwischen den Kacheln der Startseite
       ---------------------------------------------------------------------- */

    /*
     * Eine überfahrene Kachel hat früher jede andere Kachel der Reihe abgedunkelt. Das
     * las sich als "die anderen sind nicht verfügbar", obwohl ein Klick sie einfach
     * öffnet, das Abdunkeln ist deshalb weg: jede Kachel behält ihre volle Stärke, und
     * die unter dem Zeiger hebt sich selbst und bekommt den Schatten (siehe das
     * Stylesheet).
     *
     * clearLinked() bleibt: der Aufbau ruft es auf.
     */
    function clearLinked() {
        linkedTiles.forEach(function (tile) {
            tile.classList.remove('is-linked');
        });

        elements.grid.classList.remove('is-linking');
    }

    /* ----------------------------------------------------------------------
       API (die vorhandenen Endpunkte, genau wie vorher aufgerufen)
       ---------------------------------------------------------------------- */

    function fetchJson(url) {
        return window.fetch(url, {
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json()
                .catch(function () {
                    return null;
                })
                .then(function (payload) {
                    if (!response.ok || !payload || payload.success !== true) {
                        throw new Error('request to ' + url + ' failed with status ' + response.status);
                    }

                    return payload.data;
                });
        });
    }

    function fetchCategories(query) {
        if (responseCache[query]) {
            return Promise.resolve(responseCache[query]);
        }

        /* Während die erste Antwort noch unterwegs ist, wartet das hier auf sie: jetzt zu
           fragen würde genau das laden, was gleich ohnehin im Speicher stehen wird. */
        if (bootstrapCache.pending) {
            return bootstrapCache.promise.then(function () {
                return fetchCategories(query);
            });
        }

        /*
         * Eine Kategorie, die die erste Antwort abdeckte und die nie Unterkategorien hatte:
         * die leere Liste ist die Antwort. Eine Kategorie, deren Unterkategorien nach einem
         * Schreibvorgang weg sind, steht nicht mehr in "known" und wird deshalb neu
         * geladen.
         */
        var parentMatch = /^\?parent_id=(\d+)$/.exec(query);

        if (parentMatch !== null
            && bootstrapCache.known[parentMatch[1]] === true
            && bootstrapCache.children[parentMatch[1]] === undefined) {
            responseCache[query] = [];

            return Promise.resolve([]);
        }

        return fetchJson(config.endpoints.categories + query).then(function (data) {
            if (!Array.isArray(data)) {
                throw new Error('categories response is not a list');
            }

            responseCache[query] = data;
            return data;
        });
    }


    /*
     * Ein Helfer für jeden Schreibvorgang. Er antwortet immer mit einem Objekt, ein
     * Aufrufer muss also nie HTTP-Einzelheiten ansehen:
     *   { ok: true,  data: ... }
     *   { ok: false, code: 'category_exists' }
     */
    function apiRequest(url, method, body) {
        var options = {
            method: method,
            headers: { Accept: 'application/json' },
            /*
             * Den Browser nie eine API-Antwort aus seinem eigenen Speicher beantworten lassen.
             *
             * Jede Antwort hier hängt davon ab, WER fragt: die Kartenliste trägt den
             * Fortschritt der angemeldeten Person. Das Abmelden fragt genau dieselbe Adresse
             * noch einmal - und mit einer gespeicherten Antwort würde die Liste weiter den
             * Fortschritt des Kontos zeigen, das gerade gegangen ist, und genau das ist der
             * Fehler, den diese Zeile behebt.
             */
            cache: 'no-store'
        };

        if (body !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        return window.fetch(url, options).then(function (response) {
            return response.json()
                .catch(function () {
                    /* Ein Inhalt, der kein JSON ist (zum Beispiel eine HTML-Fehlerseite),
                       darf die Seite nicht mit einem Fehler anhalten. */
                    return null;
                })
                .then(function (payload) {
                    if (response.ok && payload && payload.success === true) {
                        return { ok: true, status: response.status, data: payload.data };
                    }

                    return {
                        ok: false,
                        status: response.status,
                        code: payload && payload.error ? String(payload.error.code) : 'request_failed'
                    };
                });
        }).catch(function () {
            /* Die Anfrage hat den Server nie erreicht. */
            return { ok: false, status: 0, code: 'network_error' };
        });
    }

    /* ----------------------------------------------------------------------
       Anmelden
       ---------------------------------------------------------------------- */

    /*
     * Die Anwendung arbeitet ohne Konto: wer möchte, kann Karten lernen, nur die Antwort
     * lässt sich noch nicht merken. Anmelden ist ein Angebot, kein Tor.
     *
     * Der Platz in der Kopfzeile und der Dialog entstehen hier; der Zustand und der Token
     * kommen aus api/auth.php. Der Token reist mit jeder Anfrage dieses Blocks zurück, eine
     * andere Seite kann also niemanden über den Browser anmelden.
     */
    /*
     * Die Person des Benutzer-Knopfes: die Zeichnung, die dieser Knopf vorher trug, ein
     * Kopf und eine Schulterlinie in einer 24er-Box, mit 16 px gezeichnet, damit sie
     * innerhalb des 34-px-Kreises eine leise Marke bleibt.
     *
     * Der angemeldete Zustand wird nicht von der Zeichnung erzählt, sondern von ihrer
     * Farbe und dem feinen Ring um den Kreis, und die Initialen stehen im Menü (siehe
     * app.css).
     */
    var EYE_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M2.6 12S6.2 5.6 12 5.6 21.4 12 21.4 12 17.8 18.4 12 18.4 2.6 12 2.6 12Z"/><circle cx="12" cy="12" r="3"/><path class="password-eye__slash" d="M4.4 19.6 19.6 4.4"/></svg>';

    var authState = { user: null, ready: false, csrfToken: '' };
    var authMode = 'sign_in';

    /* Die eine Anfrage dieses Blocks. Sie antwortet mit den Daten oder mit einem Code. */
    function authFetch(payload) {
        return window.fetch(config.endpoints.auth, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                var data = body && body.data ? body.data : null;

                if (response.ok === true && body && body.success === true) {
                    return { ok: true, code: null, data: data };
                }

                return {
                    ok: false,
                    code: body && body.error ? String(body.error.code) : 'request_failed',
                    data: data
                };
            }).catch(function () {
                return { ok: false, code: 'request_failed', data: null };
            });
        }).catch(function () {
            /* Die Anfrage hat den Server nie erreicht. */
            return { ok: false, code: 'network_error', data: null };
        });
    }

    function loadAuthState() {
        return window.fetch(config.endpoints.auth, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json();
            })
            .then(function (body) {
                if (body && body.success === true && body.data) {
                    authState.user = body.data.user ? body.data.user : null;
                    authState.ready = body.data.ready === true;
                    authState.csrfToken = String(body.data.csrf_token || '');
                }

                /*
                 * Diese Antwort kommt NACH dem ersten Aufbau der Seite, und das Konto in der
                 * Kopfzeile hängt von ihr ab.
                 *
                 * Ohne einen zweiten Durchgang blieb es fehlend bis zur nächsten Navigation -
                 * die Seite sah abgemeldet aus, obwohl sie es nicht war. Die Kopfzeile wird also
                 * neu gebaut und die offene Ansicht noch einmal gezeichnet.
                 */
                renderAccountSlot();
                render();

                return authState;
            })
            .catch(function () {
                /* Ohne Antwort behält die Kopfzeile ihre Einladung zum Anmelden. */
                renderAccountSlot();

                return authState;
            });
    }

    /*
     * Das Konto in der Kopfzeile, als reiner Text.
     *
     * Abgemeldet ist es das Wort "Anmelden", das den Anmeldedialog öffnet. Angemeldet ist
     * es der Name der Person und, hinter einem kleinen Punkt, "Abmelden": der Name öffnet
     * das Kontofenster, das Wort meldet sofort ab.
     *
     * Nichts hier ist ein Knopf mit Rahmen, Symbol oder Schatten - es liest sich genau wie
     * der Sprachumschalter daneben und verändert die Höhe der Kopfzeile um gar nichts
     * (siehe app.css).
     */
    function renderAccountSlot() {
        var slot = document.getElementById('account-slot');

        if (slot === null) {
            return;
        }

        slot.textContent = '';

        if (authState.user === null) {
            var open = el('button', 'account__link');
            open.type = 'button';
            open.textContent = t('auth.signIn');
            open.setAttribute('data-i18n', 'auth.signIn');
            open.addEventListener('click', function () {
                openAuthDialog('sign_in');
            });
            slot.appendChild(open);

            return;
        }

        var person = el('div', 'account__person');

        /*
         * Der Name trägt kein data-i18n: er ist eine Angabe und kein Satz, der
         * Sprachumschalter muss ihn also genau so lassen, wie er dasteht.
         */
        var name = el('button', 'account__name', authState.user.name);
        name.type = 'button';
        name.setAttribute('aria-haspopup', 'dialog');
        name.addEventListener('click', function () {
            openAccountDialog();
        });

        var dot = el('span', 'account__dot', '\u00b7');
        dot.setAttribute('aria-hidden', 'true');

        var out = el('button', 'account__link');
        out.type = 'button';
        out.textContent = t('auth.signOut');
        out.setAttribute('data-i18n', 'auth.signOut');
        out.addEventListener('click', function () {
            signOut();
        });

        person.appendChild(name);
        person.appendChild(dot);
        person.appendChild(out);
        slot.appendChild(person);
    }

    /*
     * Abmelden: die Sitzung endet, der Bildschirm tut nicht länger so, und die Seite geht
     * zurück zu ihrem Anfang - mit einer leisen Zeile, die sagt, was passiert ist.
     */
    function signOut() {
        authFetch({ action: 'sign_out', csrf_token: authState.csrfToken }).then(function (result) {
            if (result.ok !== true) {
                showFeedback(errorMessage(result.code));

                return;
            }

            authState.user = null;
            renderAccountSlot();
            goToStartPage('signedOut');
        });
    }

    /*
     * Die Startseite, ohne die Seite neu zu laden: die Adresse wird geändert und die
     * Ansicht aus dem Speicher gezeichnet, genau wie es ein Klick auf eine Karte tut.
     *
     * Warum der Speicher zuerst fallengelassen wird: er trägt noch den Fortschritt der
     * Person, die gerade gegangen ist (oder deren Konto gerade gelöscht wurde), und jede
     * daraus gebaute Liste würde Zahlen zeigen, die niemandem mehr gehören.
     *
     * Ein Fall behält den alten Weg und lädt die Seite: während eine Lerneinheit läuft.
     * Ihre Schlange entstand aus dem Zustand davor, und diese Antworten gehören zu diesem
     * Zustand - die Meldung wartet dann auf die Seite, die folgt.
     */
    function goToStartPage(noteKey) {
        if (learnSession !== null) {
            setPendingNote(noteKey);
            window.location.href = 'index.php';

            return;
        }

        bootstrapDropAll();
        loadBootstrap();

        config.categoryId = null;

        try {
            window.history.pushState({ categoryId: null }, '', 'index.php');
        } catch (error) {
            /* Eine Adresse, die sich nicht ändern lässt, ist keine Meldung wert. */
        }

        clearOverlaysForNavigation();
        showViewFromUrl();

        if (typeof noteKey === 'string' && noteKey !== '') {
            showPageNote(accountNoteText(noteKey));
        }
    }

    /* Welchen Satz die leise Zeile trägt - der Schlüssel entscheidet es. */
    function accountNoteText(noteKey) {
        return noteKey === 'deleted' ? t('account.deleted') : t('account.signedOut');
    }

    /*
     * An- oder Abmelden ändert, was der Server über JEDE Karte weiß - ihren Fortschritt -,
     * im Speicher darf also nichts bleiben: er wird fallengelassen und neu erfragt, genau
     * wie es ein Sprachwechsel tut, und die offene Ansicht wird aus der frischen Antwort
     * gezeichnet. Die Seite wird nicht neu geladen, und genau das brachte früher nach dem
     * Anmelden für Sekunden den Ladebildschirm.
     *
     * Ein Fall behält den alten Weg: während eine Lerneinheit läuft. Ihre Schlange entstand
     * aus dem Zustand davor, und die Antworten dieser Einheit gehören zu diesem Zustand -
     * dort wird die Seite neu geladen, genau wie immer.
     */
    function refreshAfterAuthChange() {
        if (learnSession !== null) {
            window.location.reload();

            return;
        }

        bootstrapDropAll();
        loadBootstrap();
        render();
    }

    /* Der Anmeldedialog ist derselbe Dialogblock wie jedes andere Formular. */
    function openAuthDialog(mode) {
        dialogKind = 'auth';
        dialogEntry = null;
        dialogParentId = null;
        dialogIcon = null;
        dialogMapField = null;
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};

        renderAuthDialog(mode);
        openDialog();

        if (dialogFields.identifier) {
            var first = dialogFields.identifier.control;
            window.setTimeout(function () {
                first.focus();
            }, prefersReducedMotion() ? 0 : 200);
        }
    }

    /*
     * Füllt den Dialog für einen der beiden Modi. Ein Wechsel zwischen ihnen schreibt nur
     * die Felder neu, die Box behält also ihre Größe und ihren Platz: beide Modi tragen
     * genau zwei Felder und einen Verweis.
     */
    function renderAuthDialog(mode) {
        authMode = mode === 'register' ? 'register' : 'sign_in';

        var registering = authMode === 'register';

        elements.dialogTitle.textContent = t(registering ? 'auth.registerTitle' : 'auth.title');
        elements.dialogMessage.hidden = true;
        elements.dialogFields.textContent = '';
        clearDialogErrors();
        dialogFields = {};
        elements.dialogSaveNext.hidden = true;
        elements.dialogClose.setAttribute('aria-label', t('dialog.close'));
        elements.dialogShortcuts.hidden = true;
        elements.dialogSubmit.textContent = t(registering ? 'auth.register' : 'auth.signIn');
        /* setBusy() schreibt während der Anfrage sein eigenes Wort und setzt danach
           dieses zurück, es muss es also kennen. */
        elements.dialogSubmit.dataset.idleLabel = elements.dialogSubmit.textContent;
        elements.dialogSubmit.disabled = false;

        addAuthFields();
    }

    /* Zwei unterstrichene Felder, das Auge im Passwortfeld und der Verweis darunter. */
    function addAuthFields() {
        var registering = authMode === 'register';

        var identifierWrap = el('div', 'dialog__field');
        var identifierLabel = el('p', 'dialog__label');
        var identifierInput = el('input', 'dialog__input');

        identifierLabel.textContent = t('auth.identifier');
        identifierLabel.setAttribute('data-i18n', 'auth.identifier');
        identifierInput.type = 'text';
        identifierInput.id = 'dialog-field-identifier';
        identifierInput.autocomplete = 'username';
        identifierInput.setAttribute('placeholder', t('auth.identifierPlaceholder'));

        var identifierError = el('p', 'dialog__field-error');
        identifierError.hidden = true;
        identifierError.setAttribute('role', 'alert');

        identifierWrap.appendChild(identifierLabel);
        identifierWrap.appendChild(identifierInput);
        identifierWrap.appendChild(identifierError);
        elements.dialogFields.appendChild(identifierWrap);
        dialogFields.identifier = { control: identifierInput, error: identifierError, wrap: identifierWrap };

        var passwordWrap = el('div', 'dialog__field');
        var passwordLabel = el('p', 'dialog__label');
        var passwordRow = el('div', 'password-row');
        var passwordInput = el('input', 'dialog__input');
        var eye = el('button', 'password-eye');

        passwordLabel.textContent = t('auth.password');
        passwordLabel.setAttribute('data-i18n', 'auth.password');
        passwordInput.type = 'password';
        passwordInput.id = 'dialog-field-password';
        /* Der eigene Passwortverwalter des Browsers soll beim Registrieren ein neues
           Passwort anbieten und beim Anmelden das bekannte. */
        passwordInput.autocomplete = registering ? 'new-password' : 'current-password';
        passwordInput.setAttribute('placeholder', t('auth.passwordPlaceholder'));

        eye.type = 'button';
        eye.innerHTML = EYE_SVG;
        setEyeState(eye, false);
        eye.addEventListener('click', function () {
            var visible = passwordInput.type === 'text';
            passwordInput.type = visible ? 'password' : 'text';
            setEyeState(eye, visible !== true);
            passwordInput.focus();
        });

        passwordRow.appendChild(passwordInput);
        passwordRow.appendChild(eye);

        var passwordError = el('p', 'dialog__field-error');
        passwordError.hidden = true;
        passwordError.setAttribute('role', 'alert');

        passwordWrap.appendChild(passwordLabel);
        passwordWrap.appendChild(passwordRow);
        passwordWrap.appendChild(passwordError);
        elements.dialogFields.appendChild(passwordWrap);
        dialogFields.password = { control: passwordInput, error: passwordError, wrap: passwordWrap };

        var switchWrap = el('p', 'auth-switch');
        var switchButton = el('button', 'dialog__link-button');

        switchButton.type = 'button';
        switchButton.textContent = t(registering ? 'auth.toSignIn' : 'auth.toRegister');
        switchButton.addEventListener('click', function () {
            /*
             * Der andere Modus, mit dem, was bisher eingetippt wurde: ein Wechsel ist
             * eine Änderung der Worte und kein neuer Dialog.
             */
            var typed = dialogFields.identifier ? dialogFields.identifier.control.value : '';
            var typedPassword = dialogFields.password ? dialogFields.password.control.value : '';

            renderAuthDialog(authMode === 'register' ? 'sign_in' : 'register');

            if (dialogFields.identifier) { dialogFields.identifier.control.value = typed; }
            if (dialogFields.password) { dialogFields.password.control.value = typedPassword; }
        });

        switchWrap.appendChild(switchButton);
        elements.dialogFields.appendChild(switchWrap);
    }

    /* Ein Knopf, zwei Zeichnungen: der Strich über dem Auge erscheint nur, wenn das
       Passwort lesbar ist. */
    function setEyeState(button, visible) {
        var label = t(visible ? 'auth.hidePassword' : 'auth.showPassword');

        button.classList.toggle('is-on', visible === true);
        button.setAttribute('aria-pressed', visible === true ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    }

    /*
     * Ein Anmelden oder eine Registrierung. Der Browser prüft nur, was er sehen kann (ein
     * leeres Feld, ein kurzes Passwort), damit die Antwort sofort kommt; der Server prüft
     * alles noch einmal und ist die einzige maßgebliche Stelle.
     */
    function runAuthSubmit() {
        var identifier = dialogFields.identifier ? dialogFields.identifier.control.value.trim() : '';
        var password = dialogFields.password ? dialogFields.password.control.value : '';

        if (identifier === '') {
            setFieldError('identifier', t('auth.errorIdentifierRequired'));
            dialogFields.identifier.control.focus();

            return;
        }

        if (password === '') {
            setFieldError('password', t('auth.errorPasswordRequired'));
            dialogFields.password.control.focus();

            return;
        }

        if (authMode === 'register' && password.length < 8) {
            setFieldError('password', t('auth.errorPasswordTooShort', { min: 8 }));
            dialogFields.password.control.focus();

            return;
        }

        setBusy(true);

        authFetch({
            action: authMode,
            identifier: identifier,
            password: password,
            csrf_token: authState.csrfToken
        }).then(function (result) {
            setBusy(false);

            if (result.ok !== true) {
                authFailure(result.code);

                return;
            }

            authState.user = result.data && result.data.user ? result.data.user : null;
            authState.ready = true;
            renderAccountSlot();
            closeDialog();

            /*
             * Ab jetzt kommt der Fortschritt jeder Karte von dieser Person, der Speicher
             * wird also neu erfragt und die offene Ansicht zeichnet sich neu - ohne
             * Seitenaufbau und damit ohne Ladebildschirm.
             */
            window.setTimeout(refreshAfterAuthChange, 300);
        });
    }

    /* Zu welchem Feld eine Antwort des Servers gehört. */
    function authFailure(code) {
        var message = errorMessage(code);
        var passwordCodes = ['credentials', 'password_required', 'password_too_short', 'password_too_long'];
        var identifierCodes = ['identifier_required', 'invalid_email', 'email_too_long', 'name_too_short',
            'name_too_long', 'name_exists', 'email_exists'];

        if (passwordCodes.indexOf(code) !== -1 && dialogFields.password) {
            setFieldError('password', message);
            dialogFields.password.control.focus();

            return;
        }

        if (identifierCodes.indexOf(code) !== -1 && dialogFields.identifier) {
            setFieldError('identifier', message);
            dialogFields.identifier.control.focus();

            return;
        }

        setDialogError(message);
    }

    /* Der Knopf in der Kopfzeile erscheint, sobald die Seite da ist. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            loadAuthState().then(renderAccountSlot);
        });
    } else {
        loadAuthState().then(renderAccountSlot);
    }

    /* Macht aus einem Code der API einen Satz in der aktuellen Sprache. */
    function errorMessage(code) {
        if (code === 'category_exists') {
            return t('dialog.errorDuplicate');
        }

        if (code === 'invalid_icon') {
            return t('dialog.errorIcon');
        }

        /* Der Server weist eine Zeichnung über der Grenze mit einem eigenen Code ab, und
           der Satz ist der, den das Formular für eine lokale Datei schon zeigt. */
        if (code === 'icon_too_large') {
            return t('dialog.icon.tooLarge', { max: Math.round(config.limits.iconBytes / 1024) });
        }

        if (code === 'invalid_icon_scale') {
            return t('dialog.errorScale');
        }

        if (code === 'invalid_front') {
            return t('dialog.errorFront');
        }

        if (code === 'invalid_exercise_type') {
            return t('dialog.errorExerciseType');
        }

        if (code === 'invalid_exercise_params') {
            return t('dialog.errorExerciseParams');
        }

        if (code === 'exercise_unavailable') {
            return t('dialog.errorExerciseUnavailable');
        }

        if (code === 'invalid_map_region') {
            return t('dialog.errorMapRegion');
        }

        if (code === 'invalid_back') {
            return t('dialog.errorBack');
        }

        if (code === 'confirm_required') {
            return t('dialog.errorDelete');
        }

        if (code === 'category_delete_conflict') {
            return t('dialog.errorDeleteConflict');
        }

        if (code === 'category_delete_failed' || code === 'category_update_failed') {
            return t('dialog.errorDelete');
        }

        if (code === 'category_not_found') {
            return t('dialog.errorAlreadyGone');
        }

        if (code === 'invalid_name' || code === 'invalid_request_body') {
            return t('dialog.errorName');
        }

        /*
         * Die Lerneinheit braucht eine angemeldete Person, bevor sie etwas speichern kann.
         * Der Satz sagt das geradeheraus, statt zu behaupten, die Antwort sei gespeichert
         * worden.
         */
        if (code === 'no_user_session') {
            return t('cards.noUser');
        }

        if (code === 'review_failed') {
            return t('learn.savedFailed');
        }

        if (code === 'undo_conflict') {
            return t('learn.undoFailed');
        }

        /* Anmelden. Der Server benutzt die Codes, die das Formular schon kennt. */
        if (code === 'sign_in_not_ready') {
            return t('auth.errorNotReady');
        }

        if (code === 'credentials') {
            return t('auth.errorCredentials');
        }

        /*
         * Das Konto löschen: das Passwort ist das Einzige, was der Server prüft, es ist also
         * auch das Einzige, was auf diesem Weg falsch sein kann.
         */
        if (code === 'wrong_password') {
            return t('account.wrongPassword');
        }

        if (code === 'password_required') {
            return t('account.passwordRequired');
        }

        if (code === 'identifier_required') {
            return t('auth.errorIdentifierRequired');
        }

        if (code === 'invalid_email' || code === 'email_too_long') {
            return t('auth.errorEmail');
        }

        if (code === 'name_too_short') {
            return t('auth.errorNameTooShort', { min: 3 });
        }

        if (code === 'name_too_long') {
            return t('auth.errorNameTooLong', { max: 100 });
        }

        if (code === 'name_exists') {
            return t('auth.errorNameExists');
        }

        if (code === 'email_exists') {
            return t('auth.errorEmailExists');
        }

        if (code === 'password_required') {
            return t('auth.errorPasswordRequired');
        }

        if (code === 'password_too_short') {
            return t('auth.errorPasswordTooShort', { min: 8 });
        }

        if (code === 'invalid_token') {
            return t('auth.errorToken');
        }

        return t('dialog.errorServer');
    }

    /* Nimmt die Meldung weg, zusammen mit jedem Knopf, der dazugehörte. */
    function hideFeedback() {
        window.clearTimeout(feedbackTimer);
        feedbackTimer = null;
        elements.feedbackAction.hidden = true;
        elements.feedbackAction.onclick = null;
        elements.feedback.hidden = true;
    }

    /*
     * Kurze Meldung am unteren Rand der Seite, für einen Augenblick.
     *
     * Mit einer Aktion wird aus der Meldung ein Angebot: sie bleibt etwas länger und der
     * Knopf daneben kann den letzten Schritt noch zurücknehmen. Der Knopf verschwindet mit
     * der Meldung, ein veralteter Knopf kann also nie geklickt werden - und er ist
     * einmalig, denn ein zweiter Klick nach einem Zurücknehmen würde zurücknehmen, was
     * schon behalten wurde.
     *
     * options: { actionLabel, onAction, duration }
     */
    function showFeedback(message, options) {
        var settings = options || {};

        hideFeedback();
        elements.feedbackText.textContent = message;

        if (typeof settings.actionLabel === 'string' && typeof settings.onAction === 'function') {
            elements.feedbackAction.textContent = settings.actionLabel;
            elements.feedbackAction.hidden = false;
            elements.feedbackAction.onclick = function (event) {
                event.preventDefault();
                var run = settings.onAction;
                hideFeedback();
                run();
            };
        }

        elements.feedback.hidden = false;

        feedbackTimer = window.setTimeout(function () {
            hideFeedback();
        }, typeof settings.duration === 'number' ? settings.duration : 3200);
    }

    /* ----------------------------------------------------------------------
       Erscheinungsbild und Sprache
       ---------------------------------------------------------------------- */

    function updateThemeControl() {
        elements.themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        elements.themeToggle.setAttribute(
            'aria-label',
            t(theme === 'dark' ? 'theme.switch.toLight' : 'theme.switch.toDark')
        );
    }

    /* Mond oder Sonne dreht sich bei jedem Umschalten um 90 Grad. */
    function rotateThemeIcon() {
        elements.themeToggle.classList.remove('is-rotating');
        /* Das Lesen eines Layoutwerts startet die Animation bei einem erneuten Klick neu. */
        void elements.themeToggle.offsetWidth;
        elements.themeToggle.classList.add('is-rotating');

        window.setTimeout(function () {
            elements.themeToggle.classList.remove('is-rotating');
        }, 500);
    }

    function applyTheme(nextTheme, persist) {
        var next = nextTheme === 'dark' ? 'dark' : 'light';
        var root = document.documentElement;

        function commit() {
            theme = next;
            root.setAttribute('data-theme', next);
            updateThemeControl();

            if (persist) {
                writeStorage(config.storageKeys.theme, theme);
            }
        }

        if (next === theme) {
            commit();
            rotateThemeIcon();
            return;
        }

        var canReveal = !prefersReducedMotion()
            && typeof document.startViewTransition === 'function';

        if (!canReveal) {
            commit();
            rotateThemeIcon();
            return;
        }

        /* Das Aufdecken beginnt am Erscheinungsbild-Knopf. */
        var rect = elements.themeToggle.getBoundingClientRect();
        root.style.setProperty('--reveal-x', (rect.left + rect.width / 2) + 'px');
        root.style.setProperty('--reveal-y', (rect.top + rect.height / 2) + 'px');
        root.classList.add('is-theme-switching');

        var transition = document.startViewTransition(function () {
            commit();
            rotateThemeIcon();
        });

        Promise.resolve(transition.finished).catch(function () {
            root.classList.remove('is-theme-switching');
        }).then(function () {
            root.classList.remove('is-theme-switching');
        });
    }

    function updateLanguageUnderline() {
        var active = null;

        Array.prototype.forEach.call(elements.localeButtons, function (button) {
            if (button.getAttribute('data-locale') === locale) {
                active = button;
            }
        });

        if (active === null) {
            return;
        }

        elements.langUnderline.style.width = active.offsetWidth + 'px';
        elements.langUnderline.style.transform = 'translateX(' + active.offsetLeft + 'px)';
    }

    function updateLanguageButtons() {
        Array.prototype.forEach.call(elements.localeButtons, function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-locale') === locale ? 'true' : 'false');
        });

        updateLanguageUnderline();
    }

    function applyLocale(nextLocale, persist) {
        var next = typeof translations[nextLocale] === 'object' ? nextLocale : config.defaultLocale;
        var changed = locale !== next;

        if (persist) {
            writeStorage(config.storageKeys.language, next);

            /*
             * Der Speicher hält Kartentexte und Kategorienamen in EINER Sprache, er kann die
             * andere also nicht bedienen. Er wird fallengelassen und neu erfragt, ohne die
             * Seite neu zu laden.
             *
             * Die neue Sprache muss stehen, BEVOR der Speicher neu erfragt wird: die Anfrage
             * nimmt die Sprache aus `locale`. Stand dort noch der alte Wert, kam die frische
             * Antwort in der Sprache zurück, die gerade verlassen wurde - jede Liste war
             * genau einen Wechsel hinterher. (Das wurde im Browser gefunden; das
             * Anfrageprotokoll zeigte zwei "language=de"-Aufrufe direkt nach einem Wechsel
             * auf Englisch.)
             */
            locale = next;
            bootstrapDropAll();
            loadBootstrap();
        }

        function swap() {
            locale = next;
            document.documentElement.setAttribute('lang', locale);
            translateStaticText();
            updateLanguageButtons();
            updateThemeControl();
            render();
        }

        if (!changed || prefersReducedMotion()) {
            swap();
            return;
        }

        elements.page.classList.add('is-lang-switching');

        window.setTimeout(function () {
            swap();
            elements.page.classList.remove('is-lang-switching');
        }, 150);
    }

    /* ----------------------------------------------------------------------
       Die Startseite bauen
       ---------------------------------------------------------------------- */

    /*
     * Der erste Buchstabe eines angezeigten Namens, für den Kreis einer Kategorie ohne
     * eigene Zeichnung. Er ist groß geschrieben, "mathematik" und "Mathematik" zeigen also
     * beide ein "M", und er folgt dem Sprachumschalter, weil der Aufrufer den Namen übergibt,
     * den er ohnehin anzeigt.
     *
     * Ohne Namen gibt es keinen Buchstaben und kein Platzhalterzeichen: ein leerer Kreis ist
     * ehrlich, ein Fragezeichen sieht wie ein Fehler aus.
     */
    function initialLetter(name) {
        var text = String(name === undefined || name === null ? '' : name).trim();

        return text === '' ? '' : text.charAt(0).toLocaleUpperCase();
    }

    /*
     * Füllt den Symbolkreis einer Kachel oder einer Vorschau: die Zeichnung, wenn es eine
     * gibt, sonst den ersten Buchstaben des Namens. Ein leerer Kreis wird nie gezeigt, denn
     * ein Kreis ohne Inhalt liest sich wie ein Ladezustand.
     */
    function fillIconCircle(circle, meta) {
        circle.textContent = '';

        if (meta.icon === null) {
            var initial = document.createElement('span');
            initial.className = 'blob__initial';
            initial.textContent = initialLetter(meta.title);
            circle.appendChild(initial);
            return;
        }

        var icon = document.createElement('img');
        icon.className = 'blob__icon';
        /* Die ganze Kachel ist ein Verweis mit einem zugänglichen Namen, die Zeichnung noch
           einmal zu beschreiben würde ihn also nur wiederholen. */
        icon.alt = '';
        /* Kein loading="lazy": die Zeichnung kommt über api/category_icon.php aus der
           Datenbank, und ein lazily geladenes Bild blieb im Test leer, obwohl die Kachel
           auf dem Bildschirm stand. */
        icon.setAttribute('decoding', 'async');
        icon.style.setProperty('--icon-scale', String(meta.iconScale));
        circle.appendChild(icon);

        /*
         * Die Adresse wird ZULETZT gesetzt, wenn das Element schon im Dokument steht. Ein
         * <img>, das seine Adresse lernt, während es noch abgehängt ist, wird in dem Moment
         * ein zweites Mal geholt, in dem es eingehängt wird, und das waren die doppelten
         * Anfragen für jede Bereichszeichnung: acht Anfragen für vier Kacheln, die zweiten
         * vier mit 0 Bytes direkt aus dem Browser-Speicher.
         */
        icon.src = meta.icon;

        /* Welchen Anteil des Kreises die Zeichnung bedeckt, wird gemessen und nicht geraten. */
        useMeasuredIconScale(icon, meta.icon);
    }

    /*
     * Die vier Zeichnungen der Lernbereiche haben nicht dieselbe Form: ein dünner Pfeil und
     * eine breite Landkarte bedecken sehr verschiedene Teile ihrer quadratischen viewBox,
     * dieselbe Box lässt also eine von ihnen kleiner aussehen, obwohl beide "gleich groß"
     * gezeichnet sind.
     *
     * Die sichtbaren Grenzen werden deshalb einmal je Zeichnung gemessen und in einen Faktor
     * umgerechnet (--icon-scale, siehe das Stylesheet). Gemessen, nie in die Datenbank
     * zurückgeschrieben: wie eine Zeichnung gezeigt wird, ist eine Frage der Oberfläche, und
     * die gespeicherte Zeichnung bleibt genau das, was jemand gezeichnet hat.
     *
     * Die Zeichnung wird über dieselbe Adresse geholt, die das <img> benutzt, die zweite
     * Anfrage beantwortet also der Browser-Speicher - und weil diese Adresse den Fingerabdruck
     * der Zeichnung trägt, ist ein neues Symbol eine neue Adresse: ein gemerkter Wert kann
     * nie zu einer anderen Zeichnung gehören.
     */
    var ICON_BASE_SIZE = 34;      // die Größe, in der .blob__icon gezeichnet wird, in Pixeln
    var ICON_TARGET_MEAN = 30.56; // das geometrische Mittel, auf das jede Zeichnung skaliert wird
    var iconScaleMemory = {};

    function useMeasuredIconScale(icon, url) {
        if (typeof url !== 'string' || url === '') {
            return;
        }

        if (iconScaleMemory[url] !== undefined) {
            icon.style.setProperty('--icon-scale', String(iconScaleMemory[url]));

            return;
        }

        measureIconScale(url).then(function (factor) {
            iconScaleMemory[url] = factor;
            icon.style.setProperty('--icon-scale', String(factor));
        }).catch(function () {
            /* Nichts gemessen, nichts geändert: 1 ist der ehrliche Rückfall. */
        });
    }

    function measureIconScale(url) {
        return window.fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.text() : null;
            })
            .then(function (markup) {
                if (markup === null) {
                    return 1;
                }

                /*
                 * Die Zeichnung kommt in die Messbox, die der Upload benutzt (.icon-measure
                 * im Stylesheet): im Dokument, damit getBBox() antwortet, aber außer Sicht
                 * und ohne Platz zu nehmen.
                 */
                var box = el('div', 'icon-measure');
                box.innerHTML = markup;
                document.body.appendChild(box);

                var factor = 1;
                var svg = box.querySelector('svg');

                if (svg !== null && typeof svg.getBBox === 'function') {
                    var view = (svg.getAttribute('viewBox') || '').split(/[\s,]+/).map(Number);

                    if (view.length === 4 && view[2] > 0 && view[3] > 0) {
                        var bounds = svg.getBBox();
                        var mean = Math.sqrt(bounds.width * bounds.height);

                        /*
                         * getBBox() antwortet im Koordinatensystem der Zeichnung, der Anteil,
                         * den die Zeichnung bedeckt, folgt also aus dem Verhältnis der beiden.
                         * Alles wird in den Einheiten der viewBox gerechnet und erst am Ende
                         * in Pixel umgerechnet, damit der Faktor unabhängig davon bleibt, in
                         * welcher Größe das Symbol gezeigt wird.
                         */
                        if (mean > 0) {
                            factor = (ICON_TARGET_MEAN * view[2]) / mean / ICON_BASE_SIZE;
                        }
                    }
                }

                box.remove();

                /* Eine Zeichnung ohne Formen oder eine, die niemand messen kann, behält ihre 1. */
                return Math.max(0.65, Math.min(1.55, factor));
            });
    }

    function buildAreaTile(area, index) {
        var meta = categoryMeta(area);

        /*
         * Eine Kachel braucht zwei Elemente, weil das Menü in der Ecke ein echtes
         * <button> ist und ein Knopf in einem Verweis weder gültiges Markup noch
         * zuverlässig anklickbar ist. Der Platzhalter trägt die Breite einer Kachel und die
         * Position, die der Kachel ihre Farbe gibt, der Verweis darin bleibt genau, was er
         * war.
         */
        var slot = document.createElement('div');
        slot.className = 'area-card-slot';
        slot.style.setProperty('--reveal-index', String(2 + index));

        var link = document.createElement('a');
        link.className = 'area-card';
        link.href = 'index.php?category=' + encodeURIComponent(area.id);
        link.setAttribute('data-area-id', String(area.id));
        link.setAttribute('aria-label', t('area.open', { name: meta.title }));

        /* Kreis links, das Menü rechts. */
        var head = document.createElement('span');
        head.className = 'area-card__head';

        var blob = document.createElement('span');
        blob.className = 'blob';
        /*
         * Die Zeichnung wird später gefüllt, wenn diese Kachel im Dokument steht - siehe
         * die Anmerkung in renderHome. Ein <img>, das seine Adresse bekommt, während es noch
         * abgehängt ist, wird in dem Moment ein ZWEITES Mal geholt, in dem es eingehängt
         * wird; das war das Doppel, das die Bereichszeichnungen kosteten (gemessen: acht
         * Anfragen für vier Kacheln, die zweiten vier mit 0 Bytes direkt aus dem
         * Browser-Speicher).
         */

        head.appendChild(blob);

        var name = document.createElement('span');
        name.className = 'area-card__name';
        /* textContent, nie innerHTML: der Name kommt aus der Datenbank. */
        name.textContent = meta.title;

        var foot = document.createElement('span');
        foot.className = 'area-card__foot';

        var stat = document.createElement('span');
        stat.className = 'area-card__stat';
        stat.textContent = tileDataLine(area);

        var arrow = document.createElement('span');
        arrow.innerHTML = ARROW_SVG;

        foot.appendChild(stat);
        foot.appendChild(arrow);

        /*
         * Der Kopf behält das Symbol oben, Titel und Informationszeile bilden unten einen
         * Block. Die Höhe dazwischen ist die Luft, die eine höhere Kachel gewinnt.
         */
        var bottom = document.createElement('span');
        bottom.className = 'area-card__bottom';
        bottom.appendChild(name);
        bottom.appendChild(foot);

        /*
         * Kein Schild über der Kachel mehr. Früher stand dort eine kleine Box mit Name und
         * Anzahl, und weil eine Kachelreihe alles abschneidet, was sie verlässt, blieb beim
         * Überfahren nur der untere Rand dieser Box sichtbar: eine kurze Linie, die nichts
         * erklärte. Dieselbe Angabe steht schon auf der Kachel selbst, in der Zeile unter dem
         * Titel.
         */
        link.appendChild(head);
        link.appendChild(bottom);

        slot.appendChild(link);

        /*
         * Das Menü dieser Kachel. Es steht immer im Markup und wird nur sichtbar, solange
         * der Bearbeitungsmodus an ist (siehe das Stylesheet), das Umschalten des Modus muss
         * die Reihe also nie neu bauen.
         */
        var menu = buildMenu([
            {
                label: t('action.edit'),
                run: function () {
                    openCategoryForm('edit', area, area.parent_id);
                }
            },
            {
                label: t('action.delete'),
                danger: true,
                run: function () {
                    requestDelete('category', area, slot);
                }
            }
        ], meta.title, 'tile-menu');

        menu.classList.add('tile-menu');
        slot.appendChild(menu);

        return slot;
    }

    function hideStates() {
        elements.error.hidden = true;
        elements.empty.hidden = true;
        elements.loading.hidden = true;
    }

    function showError() {
        hideStates();
        elements.error.hidden = false;
        elements.grid.hidden = true;
        elements.entryList.hidden = true;
        elements.entryEmpty.hidden = true;
        elements.areaCards.hidden = true;
    }

    /* ----------------------------------------------------------------------
       Startansicht
       ---------------------------------------------------------------------- */

    function renderHome() {
        elements.homeView.hidden = false;
        elements.detailView.hidden = true;

        /*
         * Die Überschrift gibt es für die angemeldete Startseite, wo sie fragt, welcher
         * Lernbereich geöffnet werden soll. Ohne Sitzung gibt es nichts zu wählen, der
         * Begrüßungszustand unten spricht also allein: eine zweite Überschrift darüber würde
         * denselben Satz nur in kleineren Buchstaben wiederholen.
         */
        elements.homeHeader.hidden = false;

        /* Hier ist nichts offen, der Plus-Knopf legt also einen Lernbereich an. */
        currentEntry = null;
        currentEntryCards = [];

        setCrumb(null);
        setHeading(elements.homeHeading, t('home.heading'));
        document.title = t('app.title');

        hideStates();
        elements.grid.hidden = false;
        showSkeletons(elements.grid, 4);

        fetchCategories('').then(function (areas) {
            linkedTiles = [];
            elements.grid.textContent = '';

            /* Wird weiter unten noch einmal gesetzt, wenn die Antwort wirklich leer ist und
               niemand angemeldet ist (siehe Begrüßungszustand). */
            elements.homeView.classList.remove('is-welcome');

            var highlighted = null;

            areas.forEach(function (area, index) {
                var tile = buildAreaTile(area, index);
                elements.grid.appendChild(tile);

                /*
                 * Jetzt, da die Kachel im Dokument steht, kommt die Zeichnung des Bereichs in
                 * ihren Kreis. Vorher gefüllt würde das <img> einmal abgehängt und einmal beim
                 * Einhängen geholt - eine Anfrage je Kachel, nach der niemand gefragt hat.
                 */
                fillIconCircle(tile.querySelector('.blob'), categoryMeta(area));

                linkedTiles.push(tile);

                if (newAreaId !== null && area.id === newAreaId) {
                    highlighted = tile;
                }
            });

            if (areas.length === 0) {
                elements.grid.hidden = true;

                /*
                 * Nichts zum Durchblättern heißt auch kein Karussell: die Kachelreihe, die
                 * Rollleiste darunter und die zwei Pfeile in der Fußzeile gehören zu einer
                 * Reihe von Kacheln, und es gibt keine. Die Klasse versteckt die Reihe und die
                 * Leiste und gibt der Karte die freie Höhe der Seite.
                 */
                elements.homeView.classList.toggle('is-welcome', authState.user === null);

                /*
                 * Wer fragt, entscheidet, was hier steht. Angemeldet heißt eine leere Liste
                 * "du hast noch nichts", und der Weg hinaus ist das Formular, das einen
                 * Lernbereich anlegt. Abgemeldet gibt es nichts anzulegen, der Weg hinaus ist
                 * also das Anmelden - der Knopf, der in "no_user_session" enden würde, wird
                 * nie gezeigt.
                 */
                if (authState.user === null) {
                    elements.homeHeader.hidden = true;
                    elements.emptyTitle.textContent = t('home.welcome.title');
                    elements.emptyHint.textContent = t('home.welcome.hint');
                    elements.emptyAction.textContent = t('auth.signIn');
                    elements.emptyAction.hidden = false;
                    elements.emptyActionSecondary.textContent = t('auth.register');
                    elements.emptyActionSecondary.hidden = false;
                } else {
                    elements.emptyTitle.textContent = t('home.empty.title');
                    elements.emptyHint.textContent = t('home.empty.hint');
                    elements.emptyAction.textContent = t('footer.addAria');
                    elements.emptyAction.hidden = false;
                    elements.emptyActionSecondary.hidden = true;
                }

                elements.empty.hidden = false;
            }

            newAreaId = null;

            if (highlighted !== null) {
                highlighted.classList.add('is-new', 'is-highlighted');

                /*
                 * Beide Klassen werden danach wieder entfernt. "item-in" endet mit
                 * animation-fill-mode "both", das würde die Deckkraft auf 1 halten und das
                 * Abdunkeln beim Überfahren an genau dieser einen Kachel stillschweigend
                 * abstellen.
                 */
                window.setTimeout(function () {
                    highlighted.classList.remove('is-highlighted');
                }, 1200);

                window.setTimeout(function () {
                    highlighted.classList.remove('is-new');
                }, 1400);
            }

            updateFooterControls('home');
            updateTileNavigation();
        }).catch(handleLoadError);
    }

    /* ----------------------------------------------------------------------
       Detailansicht (Verhalten unveraendert)
       ---------------------------------------------------------------------- */

    /*
     * Ein Lernbereich in der Seitenleiste.
     *
     * Kein Zähler mehr vor dem Namen: der Punkt trägt stattdessen die Farbe dieses
     * Bereichs, dieselbe Farbe, die seine Kachel auf der Startseite hat. Die Reihenfolge
     * kommt weiter aus der Liste selbst, es verschiebt sich also nichts.
     */
    function buildSidebarLink(area, index, isActive) {
        var link = document.createElement('a');
        link.className = 'sidebar__link';
        link.href = 'index.php?category=' + encodeURIComponent(area.id);
        link.style.setProperty('--link-color', 'var(--palette-' + ((index % 8) + 1) + ')');

        if (isActive) {
            link.setAttribute('aria-current', 'page');
        }

        var name = document.createElement('span');
        name.textContent = displayName(area);

        link.appendChild(name);

        return link;
    }

    /*
     * Ein kleiner "..."-Knopf mit einem Menü. Er ist die einzige Stelle, an der ein Eintrag
     * geändert oder entfernt werden kann, und er ist wirklich ein Knopf, er funktioniert also
     * mit einer Tastatur: Enter öffnet das Menü, Escape schließt es wieder.
     */
    function buildMenu(actions, label, wrapperClass) {
        var wrap = document.createElement('span');
        wrap.className = wrapperClass ? wrapperClass : 'row__menu';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'menu-button';
        button.setAttribute('aria-haspopup', 'true');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-label', t('action.more', { name: label }));
        /*
         * Die drei Punkte als Zeichnung und nicht als Textzeichen: ein Zeichen
         * sitzt in jeder Schrift anders, und der Knopf muss genau mittig sein.
         * Die Farbe kommt aus CSS (fill: currentColor).
         */
        var dots = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        dots.setAttribute('viewBox', '0 0 24 24');
        dots.setAttribute('width', '16');
        dots.setAttribute('height', '16');
        dots.setAttribute('aria-hidden', 'true');

        [6, 12, 18].forEach(function (x) {
            var dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            dot.setAttribute('cx', String(x));
            dot.setAttribute('cy', '12');
            dot.setAttribute('r', '1.75');
            dots.appendChild(dot);
        });

        button.appendChild(dots);

        var menu = document.createElement('span');
        menu.className = 'menu';
        menu.setAttribute('role', 'menu');
        menu.hidden = true;

        actions.forEach(function (action) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'menu__item' + (action.danger ? ' menu__item--danger' : '');
            item.setAttribute('role', 'menuitem');
            item.textContent = action.label;
            item.addEventListener('click', function () {
                closeMenu();
                action.run();
            });
            menu.appendChild(item);
        });

        button.addEventListener('click', function (event) {
            /* Ohne das würde der Listener am Dokument das Menü sofort wieder schließen,
               weil der Klick noch auf dem Weg nach oben ist. */
            event.stopPropagation();
            toggleMenu(wrap, button, menu);
        });

        wrap.appendChild(button);
        wrap.appendChild(menu);

        return wrap;
    }

    function toggleMenu(wrap, button, menu) {
        if (openMenu !== null && openMenu.menu === menu) {
            closeMenu();
            return;
        }

        closeMenu();

        openMenu = { wrap: wrap, button: button, menu: menu };
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        if (openMenu === null) {
            return;
        }

        openMenu.menu.hidden = true;
        openMenu.button.setAttribute('aria-expanded', 'false');
        openMenu = null;
    }

    /*
     * Eine Unterkategorie-Zeile: der Name als Weg zu ihrer Seite, und ein Knopf, der die
     * Einheit dieser Unterkategorie sofort startet.
     *
     * Vor dem Namen steht keine Zahl mehr, es gibt keine Fortschrittsspur, keinen Pfeil und
     * kein Menü mehr: die ganze Zeile ist der Verweis, und alles, was die Kategorie ändert,
     * passiert auf der Seite, die sie öffnet. Das Einzige, was neben dem Namen geblieben ist,
     * ist der Knopf mit der Zahl der Karten, die gerade fällig sind.
     *
     * "row--category" ist keine Verzierung: der Ladebildschirm in index.php wartet auf
     * ".row--category" (oder ".row--card"), bevor er sich wegnimmt, eine direkt geöffnete
     * Unterkategorieseite - per Neuladen, Lesezeichen oder Verweis - würde ohne sie die
     * Überlagerung weiter zeigen.
     */
    function buildEntryRow(entry, index) {
        var item = document.createElement('li');
        item.className = 'row row--category reveal';
        item.style.setProperty('--reveal-index', String(index));

        var title = displayName(entry);

        var link = document.createElement('a');
        link.className = 'row__link';
        link.href = 'index.php?category=' + encodeURIComponent(entry.id);
        link.setAttribute('aria-label', t('cards.open', { name: title }));

        var name = document.createElement('span');
        name.className = 'row__name';
        name.textContent = title;

        link.appendChild(name);
        item.appendChild(link);
        item.appendChild(buildLearnButton(entry, title));

        return item;
    }

    /*
     * Die eine Aktion einer Unterkategorie-Zeile: sie lernen.
     *
     * Die Zahl im Abzeichen ist die Zahl der Karten, die GERADE fällig sind, nicht die Zahl
     * der Karten, die darin liegen: eine Zeile ist ein Ort, an dem etwas zu tun ist, sie
     * sagt also, wie viel davon heute wartet.
     *
     * Der Knopf gehört nicht zum Verweis darüber, ein Klick darauf kann also nie die Seite
     * öffnen, statt die Einheit zu starten.
     */
    function buildLearnButton(entry, title) {
        var due = entryDueCount(entry);

        var button = el('button', 'row__learn');
        button.type = 'button';
        button.setAttribute('aria-label', t('cards.learnDue', { name: title, count: due }));
        button.addEventListener('click', function () {
            startLearning('all', entry.id, title);
        });

        /*
         * Etwas zu tun oder nichts zu tun: sind Karten fällig, trägt der Knopf die
         * Akzentfarbe des Erscheinungsbilds und die Zahl, sind keine fällig, ist er ein leiser
         * Umriss und die Null verschwindet (das Aussehen entscheidet das Stylesheet, die
         * Klasse sagt nur, welches von beiden es ist).
         */
        if (due > 0) {
            button.classList.add('has-due');
        }

        button.appendChild(el('span', 'row__learn-label', t('cards.learn')));

        /*
         * Der Zähler behält seinen Platz auch bei null: die Zeilen enden dann auf einer Linie,
         * und eine Null ist hier eine Antwort ("nichts fällig") und keine Lücke. Er ist das
         * Einzige, was die Akzentfarbe des Bereichs trägt, er sagt also auch, zu welchem
         * Bereich diese Zeile gehört.
         */
        var badge = el('span', 'row__learn-badge', String(due));
        badge.setAttribute('aria-hidden', 'true');
        badge.classList.add(due === 0 ? 'row__learn-badge--none' : 'row__learn-badge--some');
        button.appendChild(badge);

        return button;
    }

    /*
     * Wie viele Karten dieser Unterkategorie gerade fällig sind.
     *
     * Die Zahl kommt aus derselben Zusammenfassung, die die Kacheln einer Detailseite
     * benutzen - die API zählt sie für jede Kategorie - die Zeile und die Seite, die sie
     * öffnet, können also nie auseinandergehen. Eine Kategorie, die die Antwort nicht
     * abdeckt, zählt als null.
     */
    function entryDueCount(entry) {
        var summary = bootstrapCache.summaries[String(entry.id)];

        if (summary && typeof summary.due === 'number') {
            return summary.due;
        }

        return 0;
    }

    /*
     * Eine Lernkarten-Zeile. Eine Karte hat keine eigene Seite, hier gibt es also keinen
     * Verweis: die Zeile zeigt die Vorderseite, die Rückseite und, wenn die Karte in beide
     * Richtungen geübt werden soll, ein Abzeichen.
     */
    function buildCardRow(card, index) {
        var item = document.createElement('li');
        item.className = 'row row--card reveal';
        item.style.setProperty('--reveal-index', String(index));

        var meta = cardStatusMeta(card);

        /*
         * Die Zeile selbst tut nichts. Das Menü mit den drei Punkten rechts ist der EINE Weg
         * in das Kartenformular, auf jeder Ebene (Kachel, Zeile, Kopf): ein zweiter Weg zum
         * selben Formular ist nur ein Weg, es versehentlich zu öffnen.
         */
        var body = document.createElement('span');
        body.className = 'row__link row__link--static';

        var number = document.createElement('span');
        number.className = 'row__index';
        number.textContent = '[' + pad2(index + 1) + ']';

        var stack = document.createElement('span');
        stack.className = 'row__stack';

        /*
         * Eine Übungskarte zeigt die Aufgabe, die für diese Seite gewürfelt wurde: bei jedem
         * Aufruf neue Zahlen. Die erste Zeile trägt den Titel - oder den Namen der Aufgabenart,
         * wenn die Karte keinen hat - und die zweite die Aufgabe mit ihrer Antwort, genauso wie
         * eine feste Karte beide Seiten zeigt.
         */
        var task = exerciseTask(card);

        var front = document.createElement('span');
        front.className = 'row__name';
        /* textContent, niemals innerHTML: beide Seiten kommen aus der Datenbank. */
        front.textContent = task === null
            ? card.front
            : (card.front !== '' ? card.front : t(task.label));

        var back = document.createElement('span');
        back.className = 'row__back';
        back.textContent = task === null ? card.back : task.question + ' → ' + task.answer;

        /* Eine Karte mit Landkarte zeigt die Karte über ihrem Text: die Frage bleibt
           das Erste, was gelesen wird. */
        var map = el('span', 'card-map card-map--row');
        map.hidden = true;
        stack.appendChild(map);
        showMap(map, card.map_region, 'card-map card-map--row');

        stack.appendChild(front);
        stack.appendChild(back);

        var badge = document.createElement('span');
        badge.className = 'row__badge';
        badge.textContent = t('cards.bidirectionalShort');
        badge.hidden = card.is_bidirectional !== true;

        /*
         * Eine Karte, die es nur in einer Sprache gibt, sagt das, statt in der anderen leer
         * auszusehen. Der Hinweis erscheint erst, wenn die Tabelle wirklich eine zweite
         * Sprache hat.
         */
        var languageBadge = document.createElement('span');
        languageBadge.className = 'row__badge row__badge--language';
        languageBadge.textContent = t(card.language === 'en' ? 'cards.onlyEnglish' : 'cards.onlyGerman');
        languageBadge.hidden = card.missing_language !== true;

        /*
         * Eine erzeugte Übung sagt das selbst: ihre Zahlen sind bei jeder Anzeige neu, die
         * Antwort lässt sich aus dieser Zeile also nicht auswendig lernen. Das Abzeichen folgt
         * der Aufgabe und nicht dem Typ, eine Aufgabenart ohne Erzeuger wird deshalb weiter als
         * die feste Karte gezeigt, die sie in Wahrheit ist.
         */
        var exerciseBadge = null;

        if (task !== null) {
            exerciseBadge = document.createElement('span');
            exerciseBadge.className = 'row__badge row__badge--exercise';
            exerciseBadge.textContent = t('cards.exerciseBadge');
        }

        /*
         * Der Status: ein Punkt und das Wort dafür, immer beides. Die Farbe allein würde
         * jemandem nichts sagen, der die drei Farben nicht unterscheiden kann, und der Titel
         * trägt den längeren Satz.
         */
        var status = document.createElement('span');
        status.className = 'row__status ' + meta.className;
        status.setAttribute('title', meta.hint);

        var dot = document.createElement('span');
        dot.className = 'row__status-dot';
        dot.setAttribute('aria-hidden', 'true');

        var statusText = document.createElement('span');
        statusText.className = 'row__status-text';
        statusText.textContent = meta.label;

        status.appendChild(dot);
        status.appendChild(statusText);

        /*
         * Die rechte Spalte: alles, was kein Text ist - die Merker, der Status
         * und der "..."-Knopf. Sie liegen zusammen in EINEM Kasten, damit sie in
         * jeder Zeile an derselben Stelle sitzen, egal ob links eine Landkarte
         * steht oder nicht.
         *
         * Vorher waren Merker und Status eigene Zellen des Zeilenrasters. Bei
         * einer Karte mit Merker ("Beide Richtungen") waren es damit vier
         * Zellen für drei Spalten, und der Status rutschte in eine zweite
         * Rasterzeile - in der Geografie, wo Landkarte und Merker zusammen
         * vorkommen, ist genau das passiert.
         */
        var metaColumn = document.createElement('span');
        metaColumn.className = 'row__meta';

        /*
         * Merker und Status stehen nebeneinander in EINER Zeile. Dadurch sitzt
         * der Status in jeder Zeile gleich hoch - vorher standen sie
         * untereinander, und eine Zeile mit Merker hatte den Status 29 px tiefer
         * als eine ohne.
         */
        var metaLine = document.createElement('span');
        metaLine.className = 'row__meta-line';
        metaLine.appendChild(badge);

        if (exerciseBadge !== null) {
            metaLine.appendChild(exerciseBadge);
        }

        metaLine.appendChild(languageBadge);
        metaLine.appendChild(status);
        metaColumn.appendChild(metaLine);
        metaColumn.appendChild(buildMenu([
            {
                label: t('action.edit'),
                run: function () {
                    openCardForm(card, card.category_id);
                }
            },
            {
                label: t('action.delete'),
                danger: true,
                run: function () {
                    requestDelete('card', card, item);
                }
            }
        ], card.front));

        body.appendChild(number);
        body.appendChild(stack);
        body.appendChild(metaColumn);

        item.appendChild(body);

        return item;
    }

    /*
     * Die letzte Zeile einer Liste ist der Weg hinein: "Unterkategorie anlegen" oder
     * "Lernkarte anlegen". Es ist ein echter Knopf in der Höhe einer Zeile, die Liste endet
     * also mit dem nächsten Schritt und nicht in einer Sackgasse.
     */

    /*
     * Die Zahlen des geöffneten Eintrags.
     *
     * Eine Zahl erscheint nur, wenn es etwas zu zählen gibt ("1 Unterkategorie",
     * "8 Unterkategorien", "32 Lernkarten"), und wenn es gar nichts gibt, bleibt die ganze
     * Zeile weg: eine Reihe von Nullen sagt niemandem etwas.
     *
     * Das Hauptwort richtet sich nach der Zahl, deshalb steht das Wort hier und nicht im
     * HTML-Teil.
     */
    function renderFigures(count, oneKey, otherKey, cardCount) {
        var showMain = count > 0;
        var showCards = typeof cardCount === 'number' && cardCount > 0;

        elements.detailStats.hidden = !showMain && !showCards;
        elements.detailFigureCount.hidden = !showMain;
        elements.detailFigureCards.hidden = !showCards;

        if (showMain) {
            animateCount(elements.detailCount, count);
            elements.statLabel.textContent = t(count === 1 ? oneKey : otherKey);
        }

        if (showCards) {
            elements.detailCardCount.textContent = String(cardCount);
            elements.detailCardLabel.textContent = t(cardCount === 1 ? 'tile.cards.one' : 'tile.cards.other');
        }
    }

    /*
     * Die Kacheln einer Unterkategorie: wie viel fällig ist, wie viel davon schon sitzt und
     * wie viele Tage jemand hier hintereinander gelernt hat.
     *
     * Jede Zahl wird hereingereicht - keine davon wird im Browser gezählt - die Kacheln und
     * die Seite darunter können also nie auseinandergehen. Sie gibt es auf der Seite einer
     * Unterkategorie und sonst nirgends: ein Lernbereich enthält nur Unterkategorien und hat
     * keine eigenen Karten, er hat also nichts zu zählen.
     *
     * Eine Kachel behält ihren Platz, wenn eine Zahl nicht gezeigt werden kann (die Reihe
     * braucht Zeilen in study_sessions, und es gibt vielleicht noch keine). Dann sagt sie es
     * in einer leisen Zeile, statt eine Null zu zeigen, die gelogen wäre.
     */
    function renderDashboard(summary, streak) {
        var hasSummary = summary !== null && typeof summary === 'object' && typeof summary.total === 'number';
        var hasStreak = streak !== null && typeof streak === 'object';

        if (!hasSummary && !hasStreak) {
            elements.dashboard.hidden = true;

            return;
        }

        var total = hasSummary ? summary.total : 0;
        var due = hasSummary ? (summary.due || 0) : 0;

        elements.dashDue.textContent = String(due);
        elements.dashKnown.textContent = String(hasSummary ? (summary.known || 0) : 0);
        elements.dashUnsure.textContent = String(hasSummary ? (summary.unsure || 0) : 0);

        /*
         * Etwas zu tun oder nichts zu tun: solange Karten fällig sind, trägt die Zahl der
         * ersten Kachel die Akzentfarbe des Erscheinungsbilds (wie es aussieht, entscheidet
         * das Stylesheet - eine Regel kann keine Zahl lesen, deshalb sagt die Klasse es).
         */
        elements.dashDue.parentElement.classList.toggle('has-due', due > 0);

        /* Wie viel der Liste schon sitzt, ist ein Anteil, deshalb bekommt sie den kleinen
           Streifen unter der Zahl: "1 von 34" liest sich als Länge leichter. */
        elements.dashKnownFill.style.setProperty(
            '--share',
            (total > 0 ? Math.round(((summary.known || 0) / total) * 100) : 0) + '%'
        );

        if (hasStreak && streak.available === true) {
            elements.dashStreak.textContent = String(streak.days);
            elements.dashStreakNote.hidden = true;
        } else {
            elements.dashStreak.textContent = '\u2013';
            elements.dashStreakNote.textContent = t('dash.streakNone');
            elements.dashStreakNote.hidden = false;
        }

        elements.dashboard.hidden = false;
    }

    /*
     * Der leere Zustand der Detailansicht.
     *
     * Der Kreis trägt die Zeichnung des Bereichs, zu dem diese Seite gehört - oder den ersten
     * Buchstaben seines Namens, wenn es keine Zeichnung gibt - die leere Seite gehört also
     * weiter zu diesem Bereich. Es folgen ein Satz und ein Knopf; welcher Knopf es ist, hängt
     * von der Seite ab, deshalb wird die Aktion als Funktion hereingereicht.
     */
    function showEntryEmpty(titleKey, meta, actionKey, run) {
        elements.entryList.hidden = true;
        elements.entryEmptyBlob.hidden = false;
        fillIconCircle(elements.entryEmptyBlob, meta);
        elements.entryEmptyTitle.textContent = t(titleKey);
        /* Ein Satz: die zweite Zeile gehört zum "nicht gefunden"-Hinweis. */
        elements.entryEmptyHint.textContent = '';
        elements.entryEmptyHint.hidden = true;
        elements.entryEmptyAction.textContent = t(actionKey);
        elements.entryEmptyAction.hidden = false;
        entryEmptyHandler = run;
        elements.entryEmpty.hidden = false;
    }

    /* Lernkarten, die direkt in einem Lernbereich liegen, nicht in einer Unterkategorie. */
    function renderAreaCards(cards) {
        elements.areaCardList.textContent = '';

        if (cards.length === 0) {
            elements.areaCards.hidden = true;
            return;
        }

        cards.forEach(function (card, index) {
            elements.areaCardList.appendChild(buildCardRow(card, index));
        });

        elements.areaCards.hidden = false;
    }

    /*
     * Die Detailansicht zeigt, was in EINER Kategorie liegt:
     *   - ein Lernbereich: seine Unterkategorien und die Lernkarten, die direkt im Bereich
     *     liegen, falls es welche gibt
     *   - eine Unterkategorie: ihre Lernkarten
     *
     * Vier Anfragen laufen gleichzeitig: die Bereichsliste (für die Seitenleiste und den
     * Zähler im Fuß), die Kategorie selbst, ihre Kinder und ihre Karten. Die Kategorie wird
     * mit apiRequest gelesen, damit "das gibt es nicht" (404) von "der Server antwortet
     * nicht" unterschieden werden kann.
     */
    function renderDetail(categoryId) {
        elements.homeView.hidden = true;
        elements.detailView.hidden = false;

        setCrumb(null);
        setHeading(elements.detailHeading, '');
        document.title = t('app.title');

        hideStates();
        elements.entryList.hidden = true;
        elements.entryEmpty.hidden = true;
        elements.areaCards.hidden = true;
        elements.detailActions.hidden = true;
        elements.detailStats.hidden = true;
        elements.dashboard.hidden = true;

        Promise.all([
            fetchCategories(''),
            fetchCategoryOne(categoryId),
            fetchCategories('?parent_id=' + encodeURIComponent(categoryId)),
            fetchCards(categoryId)
        ]).then(function (results) {
            var allAreas = results[0];
            var single = results[1];
            var children = results[2];
            var cardsResult = results[3];

            elements.sidebarNav.textContent = '';
            allAreas.forEach(function (area, index) {
                elements.sidebarNav.appendChild(buildSidebarLink(area, index, area.id === categoryId));
            });

            if (!single.ok) {
                /* Die Kennung steht in der Adresse, die Kategorie ist aber weg. Dieser Hinweis
                   behält seine zweite Zeile: sie erklärt, was passiert ist. Es gibt keinen
                   Bereich zum Zeichnen, der Kreis bleibt also weg. */
                setHeading(elements.detailHeading, t('detail.notFound.title'));
                elements.entryEmptyBlob.hidden = true;
                elements.entryEmptyBlob.textContent = '';
                elements.entryEmptyTitle.textContent = t('detail.notFound.title');
                elements.entryEmptyHint.textContent = t('detail.notFound.hint');
                elements.entryEmptyHint.hidden = false;
                elements.entryEmptyAction.hidden = true;
                elements.entryEmpty.hidden = false;

                /*
                 * Der Fuß folgt der Ansicht, die wirklich da ist, und nicht der, die gewünscht
                 * war: das ist nicht die Startseite, der Plus-Knopf verschwindet also - und er
                 * hätte ohnehin nichts, worauf er sich beziehen könnte, weil es keinen Eintrag
                 * gibt, zu dem etwas hinzukäme.
                 */
                updateFooterControls('detail');
                return;
            }

            var current = single.data;
            var isSubcategory = current.parent_id !== null;
            var parent = null;

            if (isSubcategory) {
                allAreas.forEach(function (area) {
                    if (area.id === current.parent_id) {
                        parent = area;
                    }
                });
            }

            currentEntry = current;
            /*
             * Die Kartenliste kommt als Karten plus die Zählungen, die die API in derselben
             * Abfrage gemacht hat. Eine Karte aus einer älteren Antwort ohne diesen Umschlag
             * wäre immer noch ein Array, beide Formen werden also verstanden.
             */
            var cardsPayload = cardsResult.ok && cardsResult.data !== null && typeof cardsResult.data === 'object'
                ? cardsResult.data
                : null;

            if (cardsPayload !== null && Array.isArray(cardsPayload.cards)) {
                currentEntryCards = cardsPayload.cards;
                openCardSummary = typeof cardsPayload.summary === 'object' ? cardsPayload.summary : null;
                openCardStreak = typeof cardsPayload.streak === 'object' ? cardsPayload.streak : null;

                /*
                 * Welche Sprachen eine Karte haben kann, entscheidet die Tabelle und nicht
                 * dieses Skript: die API meldet es, und das Kartenfenster zeigt die
                 * Sprachreiter nur, wenn es wirklich zwei gibt.
                 */
                if (Array.isArray(cardsPayload.content_languages) && cardsPayload.content_languages.length > 0) {
                    cardContentLanguages = cardsPayload.content_languages;
                }
            } else {
                currentEntryCards = Array.isArray(cardsResult.data) ? cardsResult.data : [];
                openCardSummary = null;
                openCardStreak = null;
            }

            var pageTitle = displayName(current);

            /*
             * Die Kopfzone trägt die Farbe des Lernbereichs, zu dem diese Seite gehört. Ein
             * Lernbereich ist sein eigener Bereich; eine Unterkategorie übernimmt die Farbe
             * ihres Elternteils, beide Ebenen eines Zweigs sehen also gleich aus. Der Wert ist
             * dasselbe Palettentoken, das die Kachel benutzt, gewählt nach der Position des
             * Bereichs in der Liste.
             */
            var areaRow = isSubcategory && parent !== null ? parent : current;
            var areaIndex = 0;

            allAreas.forEach(function (area, index) {
                if (area.id === areaRow.id) {
                    areaIndex = index;
                }
            });

            elements.detailView.style.setProperty('--detail-palette', 'var(--palette-' + ((areaIndex % 8) + 1) + ')');
            fillIconCircle(elements.detailBlob, categoryMeta(areaRow));

            /*
             * Ein Menü statt zwei beschrifteter Knöpfe: dieselbe Bedienung, die jede Zeile
             * und jede Kachel trägt, mit denselben zwei Einträgen.
             */
            elements.detailActions.textContent = '';
            elements.detailActions.appendChild(buildMenu([
                {
                    label: t('action.edit'),
                    run: openEditForCurrentEntry
                },
                {
                    label: t('action.delete'),
                    danger: true,
                    run: function () {
                        requestDelete('category', currentEntry, null);
                    }
                }
            ], pageTitle, 'detail__menu'));

            var crumbParts = [];

            if (isSubcategory && parent !== null) {
                crumbParts.push({
                    label: displayName(parent),
                    href: 'index.php?category=' + encodeURIComponent(parent.id)
                });
            }

            crumbParts.push({ label: pageTitle });
            setCrumb(crumbParts);
            updateFooterControls(isSubcategory ? 'card' : 'area');

            setHeading(elements.detailHeading, pageTitle);
            document.title = pageTitle + ' — ' + t('app.title');


            elements.detailActions.hidden = false;

            if (isSubcategory) {
                /* Die Kacheln kommen zuerst: sie sind die Zahlen der Arbeit, für die diese
                   Seite da ist, die Zählungen und die Knöpfe folgen ihnen. */
                renderDashboard(openCardSummary, openCardStreak);
                renderFigures(currentEntryCards.length, 'tile.cards.one', 'tile.cards.other', undefined);
                renderCardTools(openCardSummary);
                showHeadActions('card', current.id, currentEntryCards.length, currentEntryCards.length > 0);

                if (currentEntryCards.length === 0) {
                    /* Nichts zu lernen, nichts zu suchen: der Kopf bleibt weg, und die Seite
                       bietet den einen sinnvollen Schritt an. */
                    elements.entryList.textContent = '';
                    openCards = [];

                    showEntryEmpty('cards.empty.title', categoryMeta(areaRow), 'cards.addFirst', function () {
                        openCardForm(null, current.id);
                    });
                } else {
                    openCards = currentEntryCards;
                    cardSearchQuery = '';
                    elements.cardSearch.value = '';
                    renderCardList(current.id);
                }

                /* Der Lernkarten-Abschnitt gehört zur Ebene darüber. */
                elements.areaCards.hidden = true;
                return;
            }

            /* Ein Lernbereich enthält Unterkategorien und keine Karten, er hat also
               weder ein Zahlenfeld noch einen eigenen Kopf. */
            renderDashboard(null, null);
            renderCardTools(null);
            openCards = [];

            /* Alles unterhalb dieses Bereichs lässt sich in einer Einheit lernen, der Knopf
               ist also da, sobald der Zweig eine einzige Karte enthält. */
            showHeadActions(
                'area',
                current.id,
                typeof current.card_count === 'number' ? current.card_count : 0,
                children.length > 0
            );

            renderFigures(children.length, 'tile.subcategories.one', 'tile.subcategories.other', currentEntryCards.length);

            elements.entryList.textContent = '';

            if (children.length === 0) {
                showEntryEmpty('detail.empty.title', categoryMeta(areaRow), 'detail.addSubcategory', function () {
                    openCategoryForm('create', null, current.id);
                });
            } else {
                children.forEach(function (child, index) {
                    elements.entryList.appendChild(buildEntryRow(child, index));
                });

                elements.entryList.hidden = false;
            }

            renderAreaCards(currentEntryCards);
        }).catch(handleLoadError);
    }

    /*
     * Die Brotkrume ist jetzt eine Liste von Schritten: "START - Mathematik" bei einem
     * Lernbereich, "START - Mathematik - Zahlensysteme" bei einer Unterkategorie. Ein Schritt
     * mit href ist ein Verweis zurück, der letzte Schritt ist reiner Text.
     *
     * Die Startseite übergibt null und zeigt gar keine Beschriftung: sie ist die Spitze des
     * Baums, es gibt also nichts darüber, wohin man zurückverweisen könnte.
     */
    /*
     * Der Plus-Knopf und der Bearbeiten-Knopf bedeuten auf jeder Ebene etwas anderes, sie
     * werden also danach beschriftet, was sie in der gerade offenen Ansicht tun werden.
     */
    function updateFooterControls(level) {
        var addKey = 'footer.addAria';

        if (level === 'area') {
            addKey = 'footer.addSubcategoryAria';
        } else if (level === 'card') {
            addKey = 'footer.addCardAria';
        }

        elements.addButton.setAttribute('aria-label', t(addKey));
        elements.addButton.setAttribute('data-i18n-label', addKey);

        /*
         * Die beiden Pfeile gehören zur Kachelreihe, sie erscheinen also nur auf der
         * Startseite. Ob sie zusätzlich ausgegraut sind, entscheidet updateTileNavigation().
         * Ohne angemeldete Person gibt es keine Kachelreihe zum Schieben: der
         * Willkommenszustand blendet sie aus, die Pfeile gehen also mit ihr.
         */
        elements.tilesButtons.hidden = level !== 'home' || authState.user === null;

        /*
         * Der runde Plus-Knopf ist die eigene Aktion der Startseite und bleibt dort: auf einer
         * Detailseite sitzt der Weg zum nächsten Eintrag im Kopf, wo er zu sehen ist, ohne ans
         * Ende der Liste zu blättern.
         *
         * Ohne angemeldete Person verschwindet er ebenfalls: er legt einen Lernbereich an,
         * und ein Bereich braucht einen Besitzer. Das ist derselbe Grund, aus dem die leere
         * Startseite die Anmeldung anbietet statt dieses Formulars - ein Knopf, der nur in
         * "no_user_session" enden kann, hat auf der Seite keinen Platz.
         */
        elements.addButton.hidden = level !== 'home' || authState.user === null;
    }

    /* Der Plus-Knopf bezieht sich auf das, was die Detailansicht gerade zeigt. */
    function openAddForCurrentEntry() {
        if (currentEntry === null) {
            openCategoryForm('create', null, null);
            return;
        }

        if (currentEntry.parent_id === null) {
            openCategoryForm('create', null, currentEntry.id);
            return;
        }

        openCardForm(null, currentEntry.id);
    }

    function openEditForCurrentEntry() {
        if (currentEntry === null) {
            return;
        }

        openCategoryForm('edit', currentEntry, currentEntry.parent_id);
    }

    function setCrumb(parts) {
        elements.crumb.textContent = '';

        if (parts === null || parts.length === 0) {
            return;
        }

        var home = document.createElement('a');
        home.href = 'index.php';
        home.setAttribute('data-i18n', 'crumb.start');
        home.textContent = t('crumb.start');

        elements.crumb.appendChild(home);

        parts.forEach(function (part) {
            var separator = document.createElement('span');
            separator.setAttribute('aria-hidden', 'true');
            separator.textContent = ' — ';

            elements.crumb.appendChild(separator);

            if (part.href) {
                var link = document.createElement('a');
                link.href = part.href;
                link.textContent = part.label;
                elements.crumb.appendChild(link);
                return;
            }

            var current = document.createElement('span');
            current.textContent = part.label;
            elements.crumb.appendChild(current);
        });
    }

    /* ----------------------------------------------------------------------
       Die waagerechte Kachelreihe
       ---------------------------------------------------------------------- */

    /*
     * Die Reihe zeigt nur ganze Kacheln - wie viele pro Ansicht, entscheidet das Stylesheet
     * (4 auf dem Schreibtisch, 3 auf dem Laptop, 2 auf dem Tablet, 1 auf dem Telefon), und die
     * Breite einer Kachel folgt daraus und aus der Breite der Spalte.
     *
     * Dieser Block hält den Zähler, die Spur, den Griff und die beiden Knöpfe mit der echten
     * Scrollposition im Takt, und er blendet die ganze Navigation aus, solange jede Kachel
     * ohne Scrollen hineinpasst.
     */
    var tileObserver = null;
    var tilePointerStart = null;
    var tilePointerMoved = false;

    function tilesPerView() {
        if (elements.tiles === null) {
            return 1;
        }

        var value = parseInt(window.getComputedStyle(elements.tiles).getPropertyValue('--tiles-per-view'), 10);

        return isNaN(value) || value < 1 ? 1 : value;
    }

    /* Eine Kachel plus ein Abstand: die Strecke, um die die Reihe pro Kachel weiterzieht. */
    function tileStep() {
        if (elements.grid === null) {
            return 0;
        }

        var tile = elements.grid.querySelector('.area-card');

        if (tile === null) {
            return 0;
        }

        var styles = window.getComputedStyle(elements.grid);
        var gap = parseFloat(styles.columnGap);

        if (isNaN(gap)) {
            gap = parseFloat(styles.gap);
        }

        return tile.getBoundingClientRect().width + (isNaN(gap) ? 0 : gap);
    }

    /*
     * Sammelt die Arbeit eines Bildes in einem Aufruf.
     *
     * Ein Scrollen, eine Größenänderung und eine geänderte Reihenbreite können innerhalb
     * desselben Bildes mehrfach feuern, und jeder Aufruf liest das Layout und schreibt zwei
     * Stile. Auf das nächste Bild zu warten liefert dasselbe Ergebnis - die Linie ist
     * aktualisiert, bevor dieses Bild gezeichnet wird - und führt die Arbeit einmal statt
     * einmal pro Ereignis aus.
     */
    var tileNavigationFrame = null;

    function scheduleTileNavigation() {
        if (typeof window.requestAnimationFrame !== 'function') {
            updateTileNavigation();
            return;
        }

        if (tileNavigationFrame !== null) {
            return;
        }

        tileNavigationFrame = window.requestAnimationFrame(function () {
            tileNavigationFrame = null;
            updateTileNavigation();
        });
    }

    function updateTileNavigation() {
        if (elements.grid === null || elements.tilesNav === null) {
            return;
        }

        var maximum = elements.grid.scrollWidth - elements.grid.clientWidth;
        var scrollable = maximum > 2;

        /*
         * Solange jede Kachel hineinpasst, gibt es nichts zu scrollen, und das ist kein Grund,
         * etwas auszublenden: die Linie bleibt, wo sie ist - sie ist die Haarlinie über dem
         * Fuß - sie wird aber ausgegraut, der Griff bedeckt die ganze Spur und die beiden
         * Pfeile sind gesperrt. Siehe das Stylesheet.
         */
        elements.tilesNav.classList.toggle('is-static', !scrollable);
        elements.tilesPrev.disabled = !scrollable || elements.grid.scrollLeft <= 1;
        elements.tilesNext.disabled = !scrollable || elements.grid.scrollLeft >= maximum - 1;

        var trackWidth = elements.tilesTrack.clientWidth;
        var ratio = elements.grid.scrollWidth > 0 ? elements.grid.clientWidth / elements.grid.scrollWidth : 1;
        /* Solange alles hineinpasst, ist der Griff genau so breit wie die Spur. */
        var thumbWidth = scrollable
            ? Math.max(28, Math.round(trackWidth * ratio))
            : trackWidth;

        /*
         * Die Breite ändert sich nur, wenn die Reihe ihre Größe ändert, der Versatz bei jedem
         * Scrollen. Ein Wert zu schreiben, der schon dasteht, würde trotzdem den Stil des
         * Elements ungültig machen, beide werden also nur geschrieben, wenn sie abweichen.
         */
        var widthValue = thumbWidth + 'px';

        if (elements.tilesThumb.style.width !== widthValue) {
            elements.tilesThumb.style.width = widthValue;
        }

        var travel = Math.max(0, trackWidth - thumbWidth);
        var progress = maximum > 0 ? elements.grid.scrollLeft / maximum : 0;
        var offsetValue = 'translateX(' + Math.round(progress * travel) + 'px)';

        if (elements.tilesThumb.style.transform !== offsetValue) {
            elements.tilesThumb.style.transform = offsetValue;
        }
    }

    function scrollTilesBy(direction) {
        var step = tileStep();

        if (step === 0) {
            return;
        }

        elements.grid.scrollBy({
            left: direction * step * tilesPerView(),
            behavior: prefersReducedMotion() ? 'auto' : 'smooth'
        });
    }

    /*
     * Springt auf einmal zu einem Versatz. "scroll-behavior: smooth" ist für die beiden Knöpfe
     * gedacht; während ein Zeiger zieht, würde weiches Scrollen hinterherhinken.
     */
    function setTilesScroll(left) {
        var previous = elements.grid.style.scrollBehavior;

        elements.grid.style.scrollBehavior = 'auto';
        elements.grid.scrollLeft = left;
        elements.grid.style.scrollBehavior = previous;
    }

    /*
     * Das Einrasten wird für die Dauer eines Zugs abgeschaltet, damit die Reihe dem Zeiger
     * folgt statt von Kachel zu Kachel zu springen; beim Loslassen kommt es zurück, und die
     * Reihe landet auf der nächsten ganzen Kachel, was "Kachel für Kachel" eben heißt.
     */
    function beginTileDrag() {
        elements.grid.style.scrollSnapType = 'none';
    }

    function endTileDrag() {
        var step = tileStep();

        elements.grid.style.scrollSnapType = '';

        if (step > 0) {
            setTilesScroll(Math.round(elements.grid.scrollLeft / step) * step);
        }
    }

    /* Wo ein Klick oder ein Zug auf der Spur landet, ausgedrückt als Scrollversatz. */
    function scrollTilesToPointer(clientX) {
        var rect = elements.tilesTrack.getBoundingClientRect();
        var thumbWidth = elements.tilesThumb.getBoundingClientRect().width;
        var travel = Math.max(1, rect.width - thumbWidth);
        var offset = Math.min(Math.max(clientX - rect.left - thumbWidth / 2, 0), travel);
        var maximum = elements.grid.scrollWidth - elements.grid.clientWidth;

        setTilesScroll((offset / travel) * maximum);
    }

    function wireTileNavigation() {
        if (elements.grid === null || elements.tilesNav === null) {
            return;
        }

        elements.grid.addEventListener('scroll', scheduleTileNavigation, { passive: true });

        elements.tilesPrev.addEventListener('click', function () {
            scrollTilesBy(-1);
        });

        elements.tilesNext.addEventListener('click', function () {
            scrollTilesBy(1);
        });

        /* Klicken und Ziehen auf der Spur, und das Ziehen am Griff selbst. */
        elements.tilesTrack.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            elements.tilesTrack.setPointerCapture(event.pointerId);
            /* Der Griff wird auf 3 px dick, solange er gezogen wird. */
            elements.tilesTrack.classList.add('is-dragging');
            beginTileDrag();
            scrollTilesToPointer(event.clientX);
        });

        elements.tilesTrack.addEventListener('pointerup', function (event) {
            if (!elements.tilesTrack.hasPointerCapture(event.pointerId)) {
                return;
            }

            elements.tilesTrack.releasePointerCapture(event.pointerId);
            elements.tilesTrack.classList.remove('is-dragging');
            endTileDrag();
        });

        elements.tilesTrack.addEventListener('pointermove', function (event) {
            if (!elements.tilesTrack.hasPointerCapture(event.pointerId)) {
                return;
            }

            scrollTilesToPointer(event.clientX);
        });

        /*
         * Das Ziehen einer Kachel darf sie nicht öffnen. Ein Zeiger, der mehr als ein paar
         * Pixel gewandert ist, zählt als Zug, und der Klick danach wird geschluckt.
         */
        elements.grid.addEventListener('pointerdown', function (event) {
            tilePointerStart = { x: event.clientX, y: event.clientY, scrollLeft: elements.grid.scrollLeft };
            tilePointerMoved = false;

            if (event.pointerType === 'mouse') {
                beginTileDrag();
            }
        });

        elements.grid.addEventListener('pointermove', function (event) {
            if (tilePointerStart === null) {
                return;
            }

            var dx = event.clientX - tilePointerStart.x;

            if (Math.abs(dx) > 6 || Math.abs(event.clientY - tilePointerStart.y) > 6) {
                tilePointerMoved = true;
            }

            /*
             * Eine Maus oder ein Stift zieht die Reihe, genau wie ein Finger und ein
             * Trackpad. Den Finger selbst überlässt das dem Browser: seine Standardaktion
             * abzubrechen würde das native Schieben abschalten.
             */
            if (tilePointerMoved && event.pointerType === 'mouse') {
                event.preventDefault();
                setTilesScroll(tilePointerStart.scrollLeft - dx);
            }
        });

        window.addEventListener('pointerup', function () {
            if (tilePointerStart !== null && tilePointerMoved) {
                endTileDrag();
            }

            tilePointerStart = null;
        });

        elements.grid.addEventListener('click', function (event) {
            if (!tilePointerMoved) {
                return;
            }

            tilePointerMoved = false;
            event.preventDefault();
            event.stopPropagation();
        }, true);

        /*
         * Die Breite der Spalte ändert sich mit dem Fenster und mit der Bildlaufleiste, der
         * Griff wird also neu gerechnet, sobald die Reihe ihre Größe ändert.
         */
        if (typeof window.ResizeObserver === 'function') {
            tileObserver = new window.ResizeObserver(scheduleTileNavigation);
            tileObserver.observe(elements.grid);
            tileObserver.observe(elements.tiles);
        }

        window.addEventListener('resize', scheduleTileNavigation);
    }

    function handleLoadError(error) {
        window.console.error(error);
        showError();
    }

    wireTileNavigation();

    function render() {
        clearLinked();

        if (config.categoryId === null) {
            renderHome();
            return;
        }

        renderDetail(config.categoryId);
    }

    /* ----------------------------------------------------------------------
       EIN Fenster für jedes Formular und jede Rückfrage
       ---------------------------------------------------------------------- */

    /*
     * Alles, was das geöffnete Fenster über sich selbst wissen muss, steckt in diesen
     * Variablen. Sie werden beim Öffnen gefüllt und von dem einen Absende-Handler gelesen,
     * Öffnen, Prüfen, Speichern und Schließen passieren also an genau einer Stelle - egal,
     * welches Formular gerade auf dem Bildschirm ist.
     */
    var dialogKind = null;      // 'category' | 'card' | 'delete'
    var dialogEntry = null;     // die Zeile, die gerade bearbeitet oder gelöscht wird
    var dialogParentId = null;  // wohin ein neuer Eintrag gehört
    var dialogOpener = null;    // das Element, das geöffnet hat (dorthin geht der Fokus zurück)
    var dialogUsed = false;     // true, sobald ein Feld berührt wurde
    var dialogIcon = null;      // { svg, name, removed, storedUrl, preview }
    var dialogFields = {};      // Name -> { control, error, wrap }
    var dialogRound = 0;        // welches Fenster das offene ist, siehe closeDialog()

    /*
     * Lässt ein Textfeld mit seinem Inhalt wachsen. Die Höhe wird nach jeder Änderung aus der
     * Scrollhöhe gesetzt, es wird also nie etwas abgeschnitten und es erscheint keine
     * Bildlaufleiste im Feld.
     */
    function growTextarea(control) {
        if (!control || control.offsetParent === null) {
            return;
        }

        control.style.height = 'auto';
        control.style.height = (control.scrollHeight + 2) + 'px';
    }

    /* Eine winzige Element-Fabrik: kürzer als createElement + className + text. */
    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (typeof text === 'string') {
            node.textContent = text;
        }

        return node;
    }

    /* Öffnet das gemeinsame Fenster und lässt es einfliegen. */
    function openDialog() {
        /* Ein neues Fenster: die Aufräumarbeit des vorigen darf nicht mehr laufen,
           worauf sie auch immer noch wartet. */
        dialogRound++;

        if (typeof elements.dialog.showModal === 'function') {
            elements.dialog.showModal();
        } else {
            elements.dialog.setAttribute('open', '');
        }

        window.requestAnimationFrame(function () {
            elements.dialog.classList.add('is-open');
        });
    }

    /*
     * Schließt das Fenster und gibt den Fokus an das zurück, was es geöffnet hat.
     *
     * Das Warten ist die Schließanimation (200 ms, 0 bei reduzierter Bewegung); ohne sie
     * würde die Fläche in einem Bild verschwinden.
     */
    function closeDialog() {
        elements.dialog.classList.remove('is-open');
        elements.dialogSubmit.classList.remove('dialog__button--danger-pill');

        /* Zu welchem Fenster diese Aufräumarbeit gehört. */
        var round = dialogRound;

        window.setTimeout(function () {
            /* Ein neueres Fenster ist offen: dieses ist längst weg und muss die Felder,
               den Fokus und die Aufgabenstellung des neuen in Ruhe lassen. */
            if (round !== dialogRound) {
                return;
            }

            if (typeof elements.dialog.close === 'function' && elements.dialog.open) {
                elements.dialog.close();
            } else {
                elements.dialog.removeAttribute('open');
            }

            var target = dialogOpener;

            if (target === null || !document.contains(target) || typeof target.focus !== 'function') {
                target = elements.addButton;
            }

            if (target && typeof target.focus === 'function') {
                target.focus();
            }

            dialogKind = null;
            dialogEntry = null;
            dialogParentId = null;
            dialogIcon = null;
            dialogOpener = null;
            dialogUsed = false;
            dialogFields = {};
            /* Das wartende Beispiel: ohne die Felder gibt es nichts mehr, woraus es sich
               bauen ließe, und eine Antwort, die später ankommt, würde in ein längst
               geschlossenes Fenster schreiben. */
            dialogExerciseField = null;
            importState = null;
            importPanel = null;
        }, prefersReducedMotion() ? 0 : 200);
    }

    function setDialogError(message) {
        elements.dialogError.textContent = message === null ? '' : message;
        elements.dialogError.hidden = message === null;
    }

    /*
     * Ein Fehler an einem Feld. Die Meldung erscheint direkt unter dem Feld, das ihn ausgelöst
     * hat, und das Feld selbst wird markiert, niemand muss also raten, welche Eingabe gemeint ist.
     */
    function setFieldError(name, message) {
        var field = dialogFields[name];

        if (!field) {
            setDialogError(message);
            return;
        }

        field.error.textContent = message === null ? '' : message;
        field.error.hidden = message === null;
        field.wrap.classList.toggle('is-invalid', message !== null);
    }

    function clearFieldError(name) {
        setFieldError(name, null);

        if (!elements.dialogError.hidden) {
            setDialogError(null);
        }
    }

    function clearDialogErrors() {
        setDialogError(null);

        Object.keys(dialogFields).forEach(function (name) {
            setFieldError(name, null);
        });
    }

    /* Fügt dem offenen Fenster ein beschriftetes Feld hinzu und merkt es sich unter seinem Namen. */
    function addField(name, kind, options) {
        var settings = options || {};
        var control;
        var id = 'dialog-field-' + name;

        if (kind === 'textarea') {
            control = el('textarea', 'dialog__input dialog__input--area');
            control.rows = settings.rows || 2;
            /* Kein Ziehgriff vom Browser: das Feld wächst mit dem, was geschrieben wird, der
               ganze Text ist also immer zu sehen, ohne in einem Kasten zu scrollen. */
            control.addEventListener('input', function () {
                growTextarea(control);
            });
            window.requestAnimationFrame(function () {
                growTextarea(control);
            });
        } else if (kind === 'checkbox') {
            control = el('input', 'dialog__check');
            control.type = 'checkbox';
        } else if (kind === 'select') {
            control = el('select', 'dialog__select');

            (settings.options || []).forEach(function (option) {
                control.appendChild(new Option(option.label, option.value));
            });
        } else if (kind === 'number') {
            control = el('input', 'dialog__input dialog__input--number');
            control.type = 'number';
            /* Ein Telefon zeigt für ein Zahlenfeld den Ziffernblock, und der Browser weist
               alles ab, was keine Zahl ist - das Feld ist also ein echtes Zahlenfeld. */
            control.setAttribute('inputmode', 'numeric');

            if (settings.min !== undefined) {
                control.min = String(settings.min);
            }

            if (settings.max !== undefined) {
                control.max = String(settings.max);
            }
        } else {
            control = el('input', 'dialog__input');
            control.type = 'text';
        }

        control.id = id;
        control.name = name;

        if (settings.maxLength) {
            control.maxLength = settings.maxLength;
        }

        if (settings.placeholderKey) {
            control.setAttribute('placeholder', t(settings.placeholderKey));
            control.setAttribute('data-i18n-placeholder', settings.placeholderKey);
        }

        if (typeof settings.value === 'string') {
            control.value = settings.value;
        }

        if (settings.checked === true) {
            control.checked = true;
        }

        control.addEventListener('input', function () {
            dialogUsed = true;
            clearFieldError(name);

            if (typeof settings.onInput === 'function') {
                settings.onInput(control);
            }
        });

        control.addEventListener('change', function () {
            dialogUsed = true;
        });

        var wrap = el('div', 'dialog__field');
        var label = el('label', 'dialog__label', t(settings.labelKey));
        label.setAttribute('for', id);
        label.setAttribute('data-i18n', settings.labelKey);

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');

        if (kind === 'checkbox') {
            /* Das Aussehen setzt den Kasten vor seine Beschriftung. */
            wrap.classList.add('dialog__field--check');
            wrap.appendChild(control);
            wrap.appendChild(label);
        } else {
            wrap.appendChild(label);
            wrap.appendChild(control);
        }

        /* Die Meldung dieses Feldes kommt zuerst: sie gehört zu der Eingabe direkt darüber,
           der leise Hinweis folgt darunter. */
        wrap.appendChild(error);

        if (settings.hintKey) {
            var hint = el('p', 'dialog__hint', t(settings.hintKey));
            hint.setAttribute('data-i18n', settings.hintKey);
            wrap.appendChild(hint);
        }

        dialogFields[name] = { control: control, error: error, wrap: wrap };

        /* Die Übersetzungen kommen stattdessen in ihre eigene Gruppe, damit die Felder
           wirklich im eingeklappten Teil liegen. */
        var container = settings.container || elements.dialogFields;

        container.appendChild(wrap);

        return control;
    }

    /* Die einklappbare Gruppe "Übersetzungen (optional)". */
    function addTranslationGroup(entry) {
        var details = el('details', 'dialog__group');
        var summary = el('summary', 'dialog__summary', t('dialog.translations'));
        summary.setAttribute('data-i18n', 'dialog.translations');

        details.appendChild(summary);

        var hint = el('p', 'dialog__hint', t('dialog.translationsHint'));
        hint.setAttribute('data-i18n', 'dialog.translationsHint');
        details.appendChild(hint);

        var grid = el('div', 'dialog__grid');

        /*
         * Ein Name pro Sprache, sonst nichts. Die Beschreibungsspalten stehen noch in der
         * Tabelle, aber kein Teil der Anwendung liest oder schreibt sie noch (siehe den
         * Hinweis in category_service.php).
         */
        [
            { name: 'name_en', labelKey: 'dialog.category.nameEn' },
            { name: 'name_de', labelKey: 'dialog.category.nameDe' }
        ].forEach(function (def) {
            addField(def.name, 'text', {
                labelKey: def.labelKey,
                maxLength: config.limits.name,
                value: entry === null || typeof entry[def.name] !== 'string' ? '' : entry[def.name],
                container: grid
            });
        });

        details.appendChild(grid);
        elements.dialogFields.appendChild(details);

        return details;
    }

    /* ----------------------------------------------------------------------
       Das Zeichnungsfeld: Klicken, Ziehen und Ablegen, Vorschau, Entfernen
       ---------------------------------------------------------------------- */

    /*
     * Schreibt ein SVG so um, dass jede Zeichnung denselben Kreis füllt.
     *
     * Der Browser misst den echten Rahmen der Zeichnung (getBBox), und der viewBox wird durch
     * ein QUADRAT um diesen Rahmen mit einem kleinen Rand ersetzt. Unterschiedliche
     * viewBox-Größen, Zeichnungsmaße, Seitenverhältnisse und leerer Weißraum um die Zeichnung
     * sehen im Kreis dadurch gleich aus - ohne dass eine einzige Zahl von Hand eingetippt
     * werden muss.
     *
     * Nichts wird mit innerHTML eingefügt: der Text wird von DOMParser gelesen, das ein
     * inaktives Dokument erzeugt, und es werden nur Attribute geändert.
     */
    function normaliseIconSvg(text) {
        return new Promise(function (resolve) {
            var parsed = new window.DOMParser().parseFromString(text, 'image/svg+xml');
            var root = parsed && parsed.documentElement;

            if (!root || root.nodeName.toLowerCase() !== 'svg' || parsed.querySelector('parsererror')) {
                resolve({ ok: false });
                return;
            }

            /* Doppelt gesichert: der Server prüft die Zeichnung vor dem Speichern noch
               einmal, aber auch hier kommt nichts Riskantes in die Seite. */
            Array.prototype.forEach.call(parsed.querySelectorAll('script, foreignObject, iframe, image, use'), function (node) {
                node.remove();
            });

            Array.prototype.forEach.call(parsed.querySelectorAll('*'), function (node) {
                Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
                    var name = attribute.name.toLowerCase();

                    if (name.indexOf('on') === 0 || name.indexOf('href') >= 0) {
                        node.removeAttribute(attribute.name);
                    }
                });
            });

            var viewBox = (root.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number);

            if (viewBox.length !== 4 || viewBox.some(function (value) { return !isFinite(value); })) {
                var width = parseFloat(root.getAttribute('width')) || 0;
                var height = parseFloat(root.getAttribute('height')) || 0;

                if (width <= 0 || height <= 0) {
                    /* Ohne viewBox und ohne Größe gibt es nichts zu messen, die Zeichnung
                       wird also abgelehnt, statt in einer Form gespeichert zu werden, die
                       niemand vorhersagen kann. */
                    resolve({ ok: false });
                    return;
                }

                viewBox = [0, 0, width, height];
            }

            root.setAttribute('viewBox', viewBox.join(' '));
            root.removeAttribute('width');
            root.removeAttribute('height');

            /* Gemessen wird in einem versteckten Kasten, der im Dokument hängt, weil getBBox
               nur für eine Zeichnung antwortet, die wirklich gesetzt ist. */
            var box = el('div', 'icon-measure');
            var clone = root.cloneNode(true);
            box.appendChild(clone);
            document.body.appendChild(box);

            var rectangle = null;

            try {
                rectangle = clone.getBBox();
            } catch (error) {
                rectangle = null;
            }

            box.remove();

            var useBox = viewBox;
            var padding = 0.04;

            if (rectangle && rectangle.width > 0 && rectangle.height > 0) {
                var size = Math.max(rectangle.width, rectangle.height) * (1 + padding * 2);
                useBox = [
                    rectangle.x + rectangle.width / 2 - size / 2,
                    rectangle.y + rectangle.height / 2 - size / 2,
                    size,
                    size
                ];
            } else {
                /* Keine brauchbare Messung: dann wenigstens den Kasten quadratisch machen,
                   denn das ist es, was das Seitenverhältnis vor dem Verzerren bewahrt. */
                var side = Math.max(viewBox[2], viewBox[3]);
                useBox = [
                    viewBox[0] + viewBox[2] / 2 - side / 2,
                    viewBox[1] + viewBox[3] / 2 - side / 2,
                    side,
                    side
                ];
            }

            var round = function (value) { return Math.round(value * 1000) / 1000; };

            root.setAttribute('viewBox', useBox.map(round).join(' '));

            var serialised = new window.XMLSerializer().serializeToString(root);

            resolve({ ok: true, svg: serialised });
        });
    }

    /* Die Vorschau im Kreis der Zeichnung, genauso wie eine Kachel sie zeigt. */
    function renderIconPreview() {
        var circle = dialogIcon.preview;
        circle.textContent = '';

        var source = null;

        if (!dialogIcon.removed) {
            if (dialogIcon.svg !== null) {
                source = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(dialogIcon.svg);
            } else if (dialogIcon.storedUrl !== null) {
                source = dialogIcon.storedUrl;
            }
        }

        if (source === null) {
            var letter = initialLetter(dialogFields.name ? dialogFields.name.control.value : '');

            if (letter !== '') {
                var initial = el('span', 'blob__initial');
                initial.textContent = letter;
                circle.appendChild(initial);
            }

            return;
        }

        var icon = el('img', 'blob__icon');
        icon.src = source;
        icon.alt = '';
        circle.appendChild(icon);
    }

    /*
     * Die Zeile mit der Zeichnung.
     *
     * Eine Zeile: genau der Kreis, den eine Kachel benutzt, der Name der gewählten Datei und
     * die Aktionen, die dazugehören. Kein gestrichelter Rahmen, keine zweite Erklärung - der
     * Kreis zeigt schon, was die Kachel zeigen wird, und die Vorschau folgt dem Namen, während
     * er getippt wird.
     */
    function buildIconField() {
        var wrap = el('div', 'dialog__field');
        var label = el('span', 'dialog__label', t('dialog.category.iconLabel'));
        label.setAttribute('data-i18n', 'dialog.category.iconLabel');
        wrap.appendChild(label);

        var row = el('div', 'icon-row');

        var circle = el('span', 'blob icon-row__circle');
        dialogIcon.preview = circle;

        var text = el('span', 'icon-row__text');
        var action = el('button', 'icon-row__action', t('dialog.icon.choose'));
        action.type = 'button';
        action.setAttribute('data-i18n', 'dialog.icon.choose');

        var fileName = el('span', 'icon-row__name');
        fileName.hidden = true;

        text.appendChild(action);
        text.appendChild(fileName);

        var remove = el('button', 'icon-row__remove', t('dialog.icon.remove'));
        remove.type = 'button';
        remove.setAttribute('data-i18n', 'dialog.icon.remove');
        remove.hidden = true;

        row.appendChild(circle);
        row.appendChild(text);
        row.appendChild(remove);
        wrap.appendChild(row);

        /*
         * Was erlaubt ist, in einer Zeile unter der Reihe: welche Dateitypen und wie groß
         * eine Datei sein darf. Der Text trägt den Übersetzungsschlüssel, der Sprachwechsel
         * übersetzt ihn also wie jeden anderen festen Text.
         */
        var hint = el('p', 'dialog__hint', t('dialog.category.iconHint'));
        hint.setAttribute('data-i18n', 'dialog.category.iconHint');
        wrap.appendChild(hint);

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');
        wrap.appendChild(error);

        var file = document.createElement('input');
        file.type = 'file';
        file.accept = 'image/svg+xml,.svg';
        file.className = 'icon-row__file';
        file.hidden = true;
        wrap.appendChild(file);

        function showError(message) {
            error.textContent = message === null ? '' : message;
            error.hidden = message === null;
        }

        function updateRow() {
            var staged = !dialogIcon.removed && dialogIcon.svg !== null;
            var stored = !dialogIcon.removed && dialogIcon.storedUrl !== null;

            remove.hidden = !staged && !stored;
            action.textContent = t(staged || stored ? 'dialog.icon.replace' : 'dialog.icon.choose');
            action.setAttribute('data-i18n', staged || stored ? 'dialog.icon.replace' : 'dialog.icon.choose');

            fileName.textContent = staged && dialogIcon.name !== '' ? dialogIcon.name : '';
            fileName.hidden = fileName.textContent === '';

            renderIconPreview();
        }

        function acceptFile(selected) {
            showError(null);

            if (!selected) {
                return;
            }

            if (!/\.svg$/i.test(selected.name) && selected.type !== 'image/svg+xml') {
                showError(t('dialog.icon.onlySvg'));
                return;
            }

            if (selected.size > config.limits.iconBytes) {
                showError(t('dialog.icon.tooLarge', { max: Math.round(config.limits.iconBytes / 1024) }));
                return;
            }

            var reader = new window.FileReader();

            reader.addEventListener('load', function () {
                normaliseIconSvg(String(reader.result)).then(function (result) {
                    if (!result.ok) {
                        showError(t('dialog.icon.notSvg'));
                        return;
                    }

                    dialogIcon.svg = result.svg;
                    dialogIcon.name = selected.name;
                    dialogIcon.removed = false;
                    dialogUsed = true;
                    updateRow();
                });
            });

            reader.addEventListener('error', function () {
                showError(t('dialog.icon.notSvg'));
            });

            reader.readAsText(selected);
        }

        /* Die ganze Reihe ist das Ziel: ein Klick darauf, oder Enter darauf, öffnet die
           Dateiauswahl. */
        row.addEventListener('click', function (event) {
            if (event.target === remove) {
                return;
            }

            file.click();
        });

        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                file.click();
            }
        });

        file.addEventListener('change', function () {
            acceptFile(file.files && file.files.length > 0 ? file.files[0] : null);
            /* Damit dieselbe Datei nach einer Ablehnung noch einmal gewählt werden kann. */
            file.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            row.addEventListener(type, function (event) {
                event.preventDefault();
                row.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach(function (type) {
            row.addEventListener(type, function () {
                row.classList.remove('is-dragover');
            });
        });

        row.addEventListener('drop', function (event) {
            event.preventDefault();
            row.classList.remove('is-dragover');
            acceptFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0
                ? event.dataTransfer.files[0]
                : null);
        });

        remove.addEventListener('click', function (event) {
            event.stopPropagation();
            dialogIcon.svg = null;
            dialogIcon.name = '';
            dialogIcon.removed = true;
            dialogUsed = true;
            showError(null);
            updateRow();
        });

        updateRow();

        elements.dialogFields.appendChild(wrap);

        return wrap;
    }

    /* ----------------------------------------------------------------------
       Die drei Formulare, die das gemeinsame Fenster benutzen
       ---------------------------------------------------------------------- */


    /* mode "create"/"edit", entry die Zeile, parentId wohin eine neue Zeile gehört. */
    function openCategoryForm(mode, entry, parentId) {
        var isEdit = mode === 'edit' && entry !== null;
        var isSubcategory = isEdit ? entry.parent_id !== null : parentId !== null;
        var title;

        if (isEdit) {
            title = t(isSubcategory ? 'dialog.category.editSubcategory' : 'dialog.category.editArea');
        } else {
            title = t(isSubcategory ? 'dialog.category.createSubcategory' : 'dialog.category.createArea');
        }

        dialogKind = 'category';
        dialogEntry = isEdit ? entry : null;
        dialogParentId = isEdit ? entry.parent_id : parentId;
        dialogIcon = {
            svg: null,
            name: '',
            removed: false,
            storedUrl: isEdit && typeof entry.icon_url === 'string' ? entry.icon_url : null,
            preview: null
        };
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};

        elements.dialogTitle.textContent = title;
        elements.dialogMessage.hidden = true;
        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogClose.setAttribute('aria-label', t('dialog.close'));
        elements.dialogShortcuts.hidden = true;
        elements.dialogSubmit.textContent = t('dialog.save');
        elements.dialogSubmit.disabled = false;
        elements.dialogSubmit.dataset.busy = t('dialog.saving');

        addField('name', 'text', {
            labelKey: 'dialog.nameLabel',
            placeholderKey: 'dialog.namePlaceholder',
            maxLength: config.limits.name,
            value: isEdit ? entry.name : '',
            /* Ohne Zeichnung zeigt der Kreis den ersten Buchstaben des Namens, er muss dem
               also folgen, was gerade getippt wird. */
            onInput: function () {
                renderIconPreview();
            }
        });

        addTranslationGroup(isEdit ? entry : null);
        buildIconField();

        openDialog();

        /*
         * Das erste Feld bekommt den Fokus (siehe die Vorgabe zum Fenstersystem): der Name, das
         * einzige Feld, das niemand überspringen kann.
         */
        dialogFields.name.control.focus();
    }

    /* Die Zeichnungsdaten des offenen Kategorieformulars, oder null, wenn sich nichts geändert hat. */
    function iconPayload() {
        if (dialogIcon.removed) {
            return null;
        }

        if (dialogIcon.svg !== null) {
            return dialogIcon.svg;
        }

        return undefined;
    }

    function validateCategoryForm() {
        var name = dialogFields.name.control.value.trim();
        var firstBad = null;

        if (name === '') {
            setFieldError('name', t('dialog.errorNameRequired'));
            firstBad = firstBad || dialogFields.name.control;
        } else if (name.length > config.limits.name) {
            setFieldError('name', t('dialog.errorNameTooLong', { max: config.limits.name }));
            firstBad = firstBad || dialogFields.name.control;
        }

        ['name_en', 'name_de'].forEach(function (field) {
            var value = dialogFields[field].control.value.trim();

            if (value.length > config.limits.name) {
                setFieldError(field, t('dialog.errorNameTooLong', { max: config.limits.name }));
                firstBad = firstBad || dialogFields[field].control;
            }
        });

        if (firstBad !== null) {
            firstBad.focus();
            return null;
        }

        var payload = {
            name: name,
            name_en: dialogFields.name_en.control.value.trim(),
            name_de: dialogFields.name_de.control.value.trim()
        };

        var icon = iconPayload();

        if (icon !== undefined) {
            payload.icon_svg = icon;
        }

        return payload;
    }

    function openCardForm(card, categoryId) {
        dialogKind = 'card';
        dialogMapField = null;
        dialogExerciseField = null;
        dialogEntry = card;
        dialogParentId = categoryId;
        dialogIcon = null;
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};

        elements.dialogTitle.textContent = t(card === null ? 'dialog.card.create' : 'dialog.card.edit');
        elements.dialogMessage.hidden = true;
        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogClose.setAttribute('aria-label', t('dialog.close'));
        /* Nur dieser Dialog hat Tastenkuerzel, also zeigt nur er die Chips. */
        elements.dialogShortcuts.hidden = false;
        elements.dialogSubmit.textContent = t('dialog.save');
        elements.dialogSubmit.disabled = false;

        /*
         * Beide Sprachen dieser Karte, solange das Fenster offen ist. Ein Wechsel des Reiters
         * verliert nie, was in dem anderen getippt wurde: die Felder werden vor dem
         * Sprachwechsel in diesen Entwurf geschrieben.
         */
        cardDraft = {
            de: {
                front: card === null ? '' : cardText(card, 'front', 'de'),
                back: card === null ? '' : cardText(card, 'back', 'de')
            },
            en: {
                front: card === null ? '' : cardText(card, 'front', 'en'),
                back: card === null ? '' : cardText(card, 'back', 'en')
            }
        };

        /* Die Sprache der Oberfläche öffnet zuerst, niemand muss also vor dem Tippen
           umschalten. */
        cardTab = cardContentLanguages.indexOf(locale) === -1 ? cardContentLanguages[0] : locale;

        if (cardContentLanguages.length > 1) {
            elements.dialogFields.appendChild(buildLanguageTabs());
        }

        /*
         * Die Kartenart kommt zuerst: sie entscheidet, wonach der Rest des Formulars fragt,
         * und die beiden Arten sind dieselbe Karte in derselben Tabelle.
         */
        addField('card_kind', 'select', {
            labelKey: 'dialog.card.kindLabel',
            options: [
                { value: 'fixed', label: t('dialog.card.kindFixed') },
                { value: 'exercise', label: t('dialog.card.kindExercise') }
            ]
        });

        dialogFields.card_kind.control.id = 'dialog-field-card_kind';
        dialogFields.card_kind.control.value = card !== null && card.exercise ? 'exercise' : 'fixed';
        dialogFields.card_kind.control.addEventListener('change', function () {
            setCardKind(dialogFields.card_kind.control.value);
        });

        addField('front', 'textarea', {
            labelKey: 'dialog.card.frontLabel',
            placeholderKey: 'dialog.card.frontPlaceholder',
            maxLength: config.limits.cardText,
            rows: 2,
            value: cardDraft[cardTab].front
        });

        addExerciseFields(card !== null ? card.exercise : null);

        addField('back', 'textarea', {
            labelKey: 'dialog.card.backLabel',
            placeholderKey: 'dialog.card.backPlaceholder',
            maxLength: config.limits.cardText,
            rows: 2,
            value: cardDraft[cardTab].back
        });

        /* Die freiwillige Landkarte: Bereich, Region und die markierte Karte darunter. */
        addMapField(card !== null && typeof card.map_region === 'string' ? card.map_region : null);

        addField('is_bidirectional', 'checkbox', {
            labelKey: 'dialog.card.bidirectionalLabel',
            checked: card !== null && card.is_bidirectional === true
        });

        /* Die lebende Vorschau: dieselbe Kartenform, die die Lerneinheit zeigt. */
        elements.dialogFields.appendChild(buildCardPreview());

        /*
         * Eine neue Karte lässt sich eine nach der anderen tippen: der zweite Knopf speichert
         * und lässt das Fenster offen. Während eine vorhandene Karte bearbeitet wird, gibt es
         * keine "nächste" Karte, der Knopf bleibt also weg.
         */
        cardSaveAndNext = false;
        elements.dialogSaveNext.hidden = card !== null;
        elements.dialogSaveNext.textContent = t('dialog.card.saveNext');
        elements.dialogSaveNext.disabled = false;

        /* Welche Felder zu sehen sind, folgt aus der Art, die gerade gesetzt wurde. */
        setCardKind(dialogFields.card_kind.control.value);

        wireCardDialogShortcuts();

        openDialog();
        dialogFields.front.control.focus();
        updateCardPreview();
    }

    function validateCardForm() {
        /* Was in den Feldern steht, gehört zu der Sprache, die offen ist. */
        if (cardDraft !== null) {
            cardDraft[cardTab].front = dialogFields.front.control.value;
            cardDraft[cardTab].back = dialogFields.back.control.value;
        }

        var payload = {
            is_bidirectional: dialogFields.is_bidirectional.control.checked,
            /* null heißt "keine Landkarte": die Spalte ist dann leer. */
            map_region: dialogMapValue()
        };

        /*
         * Eine Übungskarte schickt die Aufgabenart und die Zahlen, die ihre Aufgabe benutzen
         * darf; ein leeres exercise_type ist die Art, wie das Fenster "das ist keine Übung"
         * sagt, und genauso wird eine Übung auch wieder weggenommen.
         */
        var exercise = cardKindValue() === 'exercise';

        payload.exercise_type = exercise ? dialogFields.exercise_type.control.value : '';

        if (exercise) {
            /*
             * Die schnelle Prüfung im Browser, damit die Antwort nicht auf eine Anfrage warten
             * muss. Der Server prüft dasselbe noch einmal und ist der, auf den es ankommt.
             */
            var read = readExerciseParams(payload.exercise_type);

            if (read.error !== undefined) {
                setFieldError(read.error.name, read.error.message);

                var wrongField = dialogFields[read.error.name];

                /* Eine Gruppe von Kästchen hat kein einzelnes Feld, das den Fokus bekommen könnte. */
                if (wrongField !== undefined && wrongField.boxes === undefined) {
                    wrongField.control.focus();
                }

                return null;
            }

            payload.exercise_params = read.params;
        }
        var complete = 0;
        var half = [];

        cardContentLanguages.forEach(function (code) {
            var front = cardDraft[code].front.trim();
            var back = cardDraft[code].back.trim();

            payload['front_' + code] = front;
            payload['back_' + code] = back;

            /* Eine Übungskarte braucht einen Titel, keine Antwort. */
            var filled = exercise ? front !== '' : front !== '' && back !== '';

            if (filled) {
                complete++;
            }

            if (!exercise && (front === '') !== (back === '')) {
                half.push(code);
            }
        });

        /*
         * Die beiden ursprünglichen Spalten der Tabelle tragen den deutschen Text, derselbe
         * Wert reist also unter beiden Namen. Eine Karte darf nur deutsch, nur englisch oder
         * beides sein.
         */
        if (cardContentLanguages.indexOf('de') !== -1) {
            payload.front = payload.front_de;
            payload.back = payload.back_de;
        }

        /*
         * Eine Sprache, die nur eine ihrer beiden Seiten hat, ist der eine Fehler, der eine
         * Karte speichern würde, die niemand beantworten kann. Der Reiter dieser Sprache
         * öffnet sich, die fehlende Seite ist also direkt da.
         */
        if (half.length > 0 && complete === 0) {
            var broken = half[0];

            if (broken !== cardTab) {
                switchCardLanguage(broken);
            }

            var missingFront = cardDraft[broken].front.trim() === '';

            setFieldError(missingFront ? 'front' : 'back', t(missingFront ? 'dialog.errorFrontRequired' : 'dialog.errorBackRequired'));
            dialogFields[missingFront ? 'front' : 'back'].control.focus();

            return null;
        }

        if (complete === 0) {
            setDialogError(t(exercise ? 'dialog.card.keepExerciseTitle' : 'dialog.card.keepOneLanguage'));
            dialogFields.front.control.focus();

            return null;
        }

        return payload;
    }

    /* "2 Unterkategorien, 1 Lernkarte" in der Sprache, die gerade eingestellt ist. */
    function deletePreviewParts(preview) {
        var parts = [];

        if (preview.categories > 0) {
            parts.push(t(
                preview.categories === 1 ? 'dialog.delete.subcategories.one' : 'dialog.delete.subcategories.other',
                { count: preview.categories }
            ));
        }

        if (preview.cards > 0) {
            parts.push(t(
                preview.cards === 1 ? 'dialog.delete.cards.one' : 'dialog.delete.cards.other',
                { count: preview.cards }
            ));
        }

        return parts.join(', ');
    }

    /*
     * Wie viel in einer Kategorie liegt, soweit diese Seite es weiß.
     *
     * Zwei Quellen, eine Form: eine einzeln gelesene Kategorie
     * (api/categories.php?id=N) trägt eine delete_preview für den ganzen Teilbaum, eine
     * Kachel aus der Liste zählt ihre direkten Unterkategorien und die Karten dieses Zweigs.
     * null heißt "unbekannt" - dann wird das Fenster geöffnet, statt zu raten, und der
     * Server zählt in seiner eigenen Transaktion ohnehin noch einmal.
     */
    function knownDependents(target) {
        if (target === null || typeof target !== 'object') {
            return null;
        }

        if (typeof target.delete_preview === 'object' && target.delete_preview !== null) {
            return {
                categories: Number(target.delete_preview.categories) || 0,
                cards: Number(target.delete_preview.cards) || 0
            };
        }

        if (typeof target.subcategory_count === 'number' && typeof target.card_count === 'number') {
            return { categories: target.subcategory_count, cards: target.card_count };
        }

        return null;
    }

    /*
     * Der eine Einstiegspunkt jedes Löschknopfes in dieser Anwendung.
     *
     * Drei Fälle, eine Regel: je mehr darin liegt, desto mehr wird gefragt.
     *
     *   - der Eintrag, dessen eigene Seite offen ist: nichts zu entscheiden, er geht also
     *     sofort (die Seite muss ohnehin verlassen werden)
     *   - eine Kategorie mit Unterkategorien oder Karten: das gemeinsame Fenster nennt die
     *     Zahlen und fragt einmal, es wird nichts getippt
     *   - alles andere - eine Karte oder eine leere Kategorie: es geht sofort, und die Meldung,
     *     die erscheint, kann es noch zurücknehmen (siehe queueDelete)
     *
     * $node ist das Element, das den Eintrag zeigt; es wird sofort entfernt, damit die Seite
     * keine Zeile behält, die die Person gerade gelöscht hat.
     *
     * Jedes Löschen wird vorher gefragt - ein leerer Eintrag genauso. Das war früher anders:
     * ein leerer Eintrag verschwand sofort und nur einer mit Inhalt wurde gefragt, derselbe
     * Klick löschte also manchmal etwas und manchmal nicht. Eine Frage vor jedem Löschen ist
     * das einzige Verhalten, das eine Person vorhersagen kann.
     */
    function requestDelete(kind, target, node) {
        /* Nur ein Löschen wartet zugleich: ein zweites führt das erste zu Ende. */
        finishPendingDelete();

        openDeleteDialog(kind, target, node === undefined ? null : node);
    }

    /* Was passiert, nachdem die Frage mit "Löschen" beantwortet wurde. */
    function confirmDelete(kind, target, node) {
        var isCategory = kind === 'category';
        var openEntry = isCategory && currentEntry !== null && currentEntry.id === target.id;

        if (openEntry) {
            /* Die Seite selbst geht weg, es gibt also keine Zeile zum Zurücknehmen. */
            sendDelete(kind, target, false, false);
            return;
        }

        /*
         * Eine Zeile, die auf der Seite bleibt, wird zuerst vom Bildschirm genommen und
         * wirklich gelöscht, wenn die Frist zum Zurücknehmen verstrichen ist, damit
         * "Rückgängig" sie mit allem, was dazugehört, zurückbringen kann.
         */
        queueDelete(kind, target, node);
    }

    /*
     * Das Löschen wartet, damit "Rückgängig" möglich ist.
     *
     * An den Server geht noch nichts: die Zeile wird nur vom Bildschirm genommen, und die
     * Anfrage folgt, wenn die Frist zum Zurücknehmen verstrichen ist. Ein Zurücknehmen stellt
     * den Eintrag dadurch genau so wieder her, wie er war - samt jeder Karte und jedem Stück
     * Lernfortschritt, weil nichts davon je angefasst wurde.
     */
    function queueDelete(kind, target, node) {
        var label = displayName(target);

        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }

        pendingDelete = {
            kind: kind,
            target: target,
            url: (kind === 'category' ? config.endpoints.category : config.endpoints.card)
                + '?id=' + encodeURIComponent(target.id),
            timer: window.setTimeout(function () {
                var entry = pendingDelete;
                pendingDelete = null;
                sendDelete(entry.kind, entry.target, false, true);
            }, UNDO_WINDOW_MS)
        };

        showFeedback(t('feedback.deleted', { name: label }), {
            actionLabel: t('feedback.undo'),
            onAction: undoPendingDelete,
            duration: UNDO_WINDOW_MS
        });
    }

    /* "Rückgängig" wurde gedrückt: das Löschen hat nie stattgefunden, ein Neuzeichnen genügt. */
    function undoPendingDelete() {
        if (pendingDelete === null) {
            return;
        }

        window.clearTimeout(pendingDelete.timer);
        pendingDelete = null;

        render();
        showFeedback(t('feedback.undone'));
    }

    /* Ein weiteres Löschen beginnt: das wartende wird vorher abgeschickt. */
    function finishPendingDelete() {
        if (pendingDelete === null) {
            return;
        }

        var entry = pendingDelete;
        window.clearTimeout(entry.timer);
        pendingDelete = null;

        sendDelete(entry.kind, entry.target, false, true);
    }

    /*
     * Die Seite wird verlassen, während ein Löschen noch wartet.
     *
     * Die wartende Anfrage wird ein letztes Mal geschickt, diesmal mit "keepalive", damit der
     * Browser sie noch zu Ende bringt, wenn die Seite schon weg ist. Ohne das könnte ein
     * geschlossener Reiter einen Eintrag hinterlassen, von dem der Person gesagt wurde, dass er
     * gelöscht ist. Die Meldung wird vorher weggenommen, denn ein Knopf, der ins Leere führt,
     * darf nicht auf dem Bildschirm bleiben.
     */
    function flushPendingDelete() {
        if (pendingDelete === null) {
            return;
        }

        var entry = pendingDelete;
        window.clearTimeout(entry.timer);
        pendingDelete = null;
        hideFeedback();

        window.fetch(entry.url, {
            method: 'DELETE',
            headers: { Accept: 'application/json' },
            keepalive: true
        }).catch(function () {
            /* Die Seite geht gerade weg, es gibt nichts mehr zu zeigen. */
        });
    }

    /*
     * Die eine Stelle, die wirklich etwas löscht.
     *
     * $confirm ist nur dann true, wenn die Person im Fenster zugestimmt hat, und genau das
     * verlangt der Server, wenn Unterkategorien oder Karten an der Kategorie hängen. $quiet
     * unterdrückt die Meldung, weil das wartende Löschen seine eigene schon gezeigt hat.
     */
    function sendDelete(kind, target, confirm, quiet) {
        var isCategory = kind === 'category';
        var url = (isCategory ? config.endpoints.category : config.endpoints.card)
            + '?id=' + encodeURIComponent(target.id);
        var label = isCategory ? displayName(target) : target.front;
        var wasOpen = isCategory && currentEntry !== null && currentEntry.id === target.id;

        return apiRequest(url, 'DELETE', confirm === true ? { confirm: true } : undefined)
            .then(function (result) {
                if (!result.ok) {
                    /*
                     * Die Seite wusste weniger als die Datenbank: es liegt doch etwas in dieser
                     * Kategorie. Sie wird noch einmal gelesen - die Antwort trägt die Zahlen des
                     * ganzen Teilbaums - und die Frage wird mit den richtigen Zahlen erneut
                     * gestellt.
                     */
                    if (result.code === 'confirm_required') {
                        bootstrapDropAll();
                        render();
                        askDeleteAgain(kind, target);
                        return;
                    }

                    /*
                     * Ein Löschen, das 404 antwortet, ist kein Fehlschlag der Anfrage: die
                     * Zeile ist wirklich weg (jemand anders hat sie gelöscht, oder sie war
                     * schon entfernt). Die Seite wird neu geladen, damit sie die Wahrheit zeigt
                     * statt eines Eintrags, der nie gelöscht werden kann.
                     */
                    if (result.status === 404) {
                        bootstrapDropAll();
                        render();
                        showFeedback(t('dialog.errorAlreadyGone'));
                        return;
                    }

                    /*
                     * Die Zeile steht noch in der Datenbank und wurde vom Bildschirm genommen,
                     * während die Anfrage unterwegs war, die Seite wird also wieder aus der API
                     * aufgebaut.
                     */
                    bootstrapDropAll();
                    render();
                    showFeedback(t('dialog.errorDelete'));
                    return;
                }

                /* Alles, was die Seite zeigt, kommt wieder aus der API. */
                /* Nur die Karten dieser Kategorie und die Zaehlungen am Baum. */
                bootstrapDropCards(config.categoryId);
                bootstrapDropCategories();

                if (wasOpen) {
                    /* Die Seite selbst ist weg, der Browser geht also eine Ebene höher. */
                    window.location.href = target.parent_id === null
                        ? 'index.php'
                        : 'index.php?category=' + encodeURIComponent(target.parent_id);
                    return;
                }

                render();

                if (quiet !== true) {
                    showFeedback(t('feedback.deleted', { name: label }));
                }
            });
    }

    /* Liest die Kategorie neu und fragt mit den Zahlen, die jetzt stimmen. */
    function askDeleteAgain(kind, target) {
        apiRequest(config.endpoints.categories + '?id=' + encodeURIComponent(target.id), 'GET')
            .then(function (result) {
                if (!result.ok || typeof result.data !== 'object' || result.data === null) {
                    showFeedback(t('dialog.errorDelete'));
                    return;
                }

                openDeleteDialog(kind, result.data);
            });
    }

    /* ---------------------------------------------------------------------------
       Das Konto: der Text im Kopf, das Fenster und die leise Zeile
       --------------------------------------------------------------------------- */

    var pageNoteTimer = null;
    var NOTE_STORAGE_KEY = 'lernkartei.note';

    /* Die eine Anfrage dieses Blocks: sie ändert das Konto selbst. */
    function accountFetch(payload) {
        return window.fetch(config.endpoints.account, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                var data = body && body.data ? body.data : null;

                if (response.ok === true && body && body.success === true) {
                    return { ok: true, code: null, data: data };
                }

                return {
                    ok: false,
                    code: body && body.error ? String(body.error.code) : 'request_failed',
                    data: data
                };
            }).catch(function () {
                return { ok: false, code: 'request_failed', data: null };
            });
        }).catch(function () {
            /* Die Anfrage hat den Server nie erreicht. */
            return { ok: false, code: 'network_error', data: null };
        });
    }

    /*
     * Opens the account window, always at its first step: what the account is made
     * of. The second step (the password and the last question) is reached from
     * there and never remembered - opening it again always starts calm.
     */
    function openAccountDialog() {
        var dialog = elements.accountDialog;

        if (dialog === null || authState.user === null) {
            return;
        }

        buildAccountList();
        showAccountStep('data');

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        window.requestAnimationFrame(function () {
            dialog.classList.add('is-open');
        });
    }

    function closeAccountDialog() {
        var dialog = elements.accountDialog;

        if (dialog === null) {
            return;
        }

        dialog.classList.remove('is-open');

        window.setTimeout(function () {
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }, prefersReducedMotion() ? 0 : 200);
    }

    /*
     * Die beiden Schritte liegen im SELBEN Fenster: getauscht wird nur der Inhalt, der Rahmen
     * bleibt, wo er ist. Nichts öffnet sich über etwas anderem, niemand steht also je vor zwei
     * Fragen auf einmal.
     */
    function showAccountStep(step) {
        if (elements.accountViewData === null || elements.accountViewConfirm === null
            || elements.accountPassword === null || elements.accountError === null) {
            return;
        }

        var confirm = step === 'confirm';

        elements.accountViewData.hidden = confirm;
        elements.accountViewConfirm.hidden = !confirm;
        elements.accountError.hidden = true;
        elements.accountPassword.value = '';
        elements.accountPassword.classList.remove('is-invalid');

        if (confirm) {
            elements.accountPassword.focus();
        }
    }

    /* Die leise Liste: gedämpfte Beschriftungen, Werte in der Textfarbe, großzügige Zeilen. */
    function buildAccountList() {
        var list = elements.accountList;
        var user = authState.user;

        if (list === null || user === null) {
            return;
        }

        list.textContent = '';

        addAccountRow(list, t('account.name'), user.name);

        if (typeof user.email === 'string' && user.email !== '') {
            addAccountRow(list, t('account.email'), user.email);
        }

        if (typeof user.created_at === 'string' && user.created_at !== '') {
            addAccountRow(list, t('account.memberSince'), formatMemberSince(user.created_at));
        }
    }

    function addAccountRow(list, label, value) {
        list.appendChild(el('dt', 'account-dialog__term', label));
        list.appendChild(el('dd', 'account-dialog__value', value));
    }

    /* Aus "2026-09-20 14:03:11" wird "September 2026" in der gewählten Sprache. */
    function formatMemberSince(value) {
        var parsed = new Date(String(value).replace(' ', 'T'));

        if (isNaN(parsed.getTime())) {
            return String(value);
        }

        return parsed.toLocaleDateString(locale === 'de' ? 'de-DE' : 'en-GB', {
            month: 'long',
            year: 'numeric'
        });
    }

    /*
     * Der letzte Schritt: das Passwort. Der Browser fragt nur, ob überhaupt etwas getippt wurde
     * - die Antwort, auf die es ankommt, kommt von user_password_matches() auf der anderen
     * Seite, eine von Hand geschriebene Anfrage ohne das richtige Passwort kann also nichts
     * löschen.
     */
    function submitAccountDelete() {
        var password = elements.accountPassword.value;

        elements.accountError.hidden = true;
        elements.accountPassword.classList.remove('is-invalid');

        if (password === '') {
            showAccountError(t('account.passwordRequired'));

            return;
        }

        setAccountBusy(true);

        accountFetch({
            action: 'delete',
            password: password,
            csrf_token: authState.csrfToken
        }).then(function (result) {
            setAccountBusy(false);

            if (result.ok !== true) {
                showAccountError(errorMessage(result.code));
                elements.accountPassword.focus();

                return;
            }

            /*
             * Das Konto ist weg. Nichts auf dem Bildschirm darf weiter so tun, als wäre es da,
             * der Kopf wird also neu gebaut und die Seite geht mit der Zeile, die sagt, was
             * passiert ist, zu ihrem Anfang zurück.
             */
            authState.user = null;
            closeAccountDialog();
            renderAccountSlot();
            goToStartPage('deleted');
        });
    }

    function showAccountError(message) {
        elements.accountError.textContent = message;
        elements.accountError.hidden = false;
        elements.accountPassword.classList.add('is-invalid');
    }

    /* Eine Beschriftung, solange gearbeitet wird, eine, solange gewartet wird - und nichts klickt zweimal. */
    function setAccountBusy(busy) {
        elements.accountConfirm.disabled = busy;
        elements.accountCancel.disabled = busy;
        elements.accountConfirm.textContent = busy ? t('account.deleting') : t('account.deleteConfirm');
    }

    /*
     * Die leise Zeile über dem Inhalt. Sie sagt, was gerade passiert ist, und nimmt sich nach
     * ein paar Sekunden wieder weg - kein Knopf, nichts zum Klicken, nichts zu beantworten.
     */
    function showPageNote(message, duration) {
        var note = elements.pageNote;

        if (note === null) {
            return;
        }

        if (pageNoteTimer !== null) {
            window.clearTimeout(pageNoteTimer);
            pageNoteTimer = null;
        }

        note.textContent = message;
        note.hidden = false;

        /* Das Lesen eines Layoutwerts startet das Einblenden neu, wenn dieselbe Zeile zweimal erscheint. */
        void note.offsetWidth;
        note.classList.add('is-visible');

        pageNoteTimer = window.setTimeout(function () {
            hidePageNote();
        }, typeof duration === 'number' ? duration : 4200);
    }

    function hidePageNote() {
        var note = elements.pageNote;

        if (pageNoteTimer !== null) {
            window.clearTimeout(pageNoteTimer);
            pageNoteTimer = null;
        }

        if (note === null || note.hidden) {
            return;
        }

        note.classList.remove('is-visible');

        window.setTimeout(function () {
            note.hidden = true;
        }, prefersReducedMotion() ? 0 : 420);
    }

    /*
     * Eine Meldung für die Seite, die gerade geladen wird: sie wird im Sitzungsspeicher übergeben
     * und von showPendingNote() genau einmal abgeholt. Das ist der einzige Fall, in dem ein
     * Abmelden die Seite lädt - siehe goToStartPage.
     */
    function setPendingNote(key) {
        try {
            window.sessionStorage.setItem(NOTE_STORAGE_KEY, String(key));
        } catch (error) {
            /* Ohne Speicher ist die Zeile einfach weg; daran scheitert nichts. */
        }
    }

    function showPendingNote() {
        var key = null;

        try {
            key = window.sessionStorage.getItem(NOTE_STORAGE_KEY);

            if (key !== null) {
                window.sessionStorage.removeItem(NOTE_STORAGE_KEY);
            }
        } catch (error) {
            return;
        }

        if (key === 'deleted' || key === 'signedOut') {
            showPageNote(accountNoteText(key));
        }
    }

    /*
     * Das Fenster hört auf alles, worauf ein Fenster hören muss: das X, einen Klick auf den
     * dunklen Hintergrund, Escape, die beiden Knöpfe des zweiten Schritts und Enter im
     * Passwortfeld.
     */
    function wireAccountDialog() {
        var dialog = elements.accountDialog;

        if (dialog === null) {
            return;
        }

        elements.accountClose.addEventListener('click', closeAccountDialog);

        elements.accountDeleteOpen.addEventListener('click', function () {
            showAccountStep('confirm');
        });

        /* "Abbrechen" ist kein geschlossenes Fenster: es geht zurück zum Konto selbst. */
        elements.accountCancel.addEventListener('click', function () {
            showAccountStep('data');
        });

        elements.accountConfirm.addEventListener('click', submitAccountDelete);

        elements.accountPassword.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitAccountDelete();
            }
        });

        elements.accountPassword.addEventListener('input', function () {
            elements.accountError.hidden = true;
            elements.accountPassword.classList.remove('is-invalid');
        });

        /* Ein Klick auf das Fensterelement selbst - nicht auf seinen Inhalt - ist der Hintergrund. */
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                closeAccountDialog();
            }
        });

        /*
         * Escape wird absichtlich doppelt behandelt, wie im Formularfenster: "cancel" ist das
         * eigene Ereignis eines modalen Fensters, und der Tastaturhandler deckt jede Situation
         * ab, in der dieses Ereignis nicht ankommt.
         */
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeAccountDialog();
        });

        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeAccountDialog();
            }
        });

        /* Geschlossen ist geschlossen: das nächste Öffnen beginnt wieder beim Konto selbst. */
        dialog.addEventListener('close', function () {
            dialog.classList.remove('is-open');
            showAccountStep('data');
        });
    }

    /*
     * Die eine Frage, die diese Anwendung stellt, bevor etwas mit Inhalt verschwindet.
     *
     * Nur eine Kategorie, in der noch Unterkategorien oder Karten liegen, kommt überhaupt bis
     * hierher - eine Karte und eine leere Kategorie werden ohne Frage gelöscht (siehe
     * requestDelete) - der Satz hat also immer Zahlen zu nennen.
     *
     * Er nennt den Eintrag und im selben Satz, was mit ihm ginge: die Zahlen aus der Datenbank
     * und das Wort "endgültig". Es muss nichts getippt werden und es gibt kein Kästchen zum
     * Ankreuzen: der rote Knopf ist die Antwort, und der Fokus steht zu Beginn auf Abbrechen,
     * die sichere Antwort ist also die schon ausgewählte.
     */
    function openDeleteDialog(kind, target, node) {
        dialogKind = 'delete';
        dialogEntry = { kind: kind, target: target, node: node };
        dialogParentId = null;
        dialogIcon = null;
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};

        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogClose.setAttribute('aria-label', t('dialog.close'));
        elements.dialogShortcuts.hidden = true;
        elements.dialogSubmit.disabled = false;
        elements.dialogSubmit.textContent = t('dialog.delete.submit');

        /* Der einzige rote Knopf der Anwendung, und nur solange diese Frage offen ist:
           closeDialog() nimmt die Klasse wieder weg. */
        elements.dialogSubmit.classList.add('dialog__button--danger-pill');

        var parts = deletePreviewParts(knownDependents(target) || { categories: 0, cards: 0 });

        elements.dialogTitle.textContent = t('dialog.delete.title', { name: displayName(target) });
        elements.dialogMessage.textContent = parts === ''
            ? t('dialog.delete.nothingBelow')
            : t('dialog.delete.consequence', { parts: parts });
        elements.dialogMessage.hidden = false;

        openDialog();
        elements.dialogClose.focus();
    }

    /* ----------------------------------------------------------------------
       Speichern und Löschen über den einen Absende-Handler
       ---------------------------------------------------------------------- */

    function setBusy(busy) {
        elements.dialogSubmit.disabled = busy;
        elements.dialogClose.disabled = busy;
        elements.dialogSubmit.textContent = busy
            ? t('dialog.saving')
            : (elements.dialogSubmit.dataset.idleLabel || t('dialog.save'));
    }

    /*
     * Die eine Stelle, die für ein Fenster mit der API spricht.
     *
     * Manche Fehler gehören zu einem Feld, die Antwort wird also, wo das möglich ist, dem Feld
     * zugeordnet, das sie ausgelöst hat; alles andere landet in der Fehlerzeile des Fensters.
     * Während die Anfrage läuft, ist der Knopf gesperrt, ein Doppelklick kann dieselbe Zeile
     * also nicht zweimal anlegen.
     */
    function fieldForErrorCode(code) {
        var map = {
            invalid_name: 'name',
            category_exists: 'name',
            invalid_name_en: 'name_en',
            invalid_name_de: 'name_de',
            invalid_icon: 'icon',
            icon_too_large: 'icon'
        };

        return map[code] || null;
    }

    function handleSubmitFailure(result) {
        /*
         * Eine Anfrage, die den Server nie erreicht hat, oder eine Antwort, die niemand
         * zugeordnet hat, muss trotzdem den Vorgang nennen, der fehlgeschlagen ist.
         */
        if (dialogKind === 'delete' && (result.code === 'network_error' || result.code === 'request_failed')) {
            setDialogError(t('dialog.errorDelete'));
            return;
        }

        var field = fieldForErrorCode(result.code);

        if (field === null || field === 'icon') {
            if (field === 'icon' && dialogFields.icon) {
                setFieldError('icon', errorMessage(result.code));
                return;
            }

            setDialogError(errorMessage(result.code));
            return;
        }

        setFieldError(field, errorMessage(result.code));
        dialogFields[field].control.focus();
    }

    function submitDialog() {
        clearDialogErrors();

        /*
         * Das Importfenster ist kein Formular: die Datei wird hochgeladen und der Server
         * schreibt die Zeilen, es nimmt sich also seinen eigenen Weg hier heraus.
         */
        if (dialogKind === 'import') {
            runImport();
            return;
        }

        /* Anmelden ist auch kein Formular für eine Zeile: es hat seine eigene Anfrage. */
        if (dialogKind === 'auth') {
            runAuthSubmit();
            return;
        }

        var isDelete = dialogKind === 'delete';
        var isCard = dialogKind === 'card';

        /*
         * Die Frage wurde mit "Löschen" beantwortet: die Zeile verlässt den Bildschirm, und
         * die Anfrage folgt, wenn die Frist zum Zurücknehmen verstrichen ist. Das Fenster
         * schließt zuerst, der Fokus geht also dorthin zurück, wo er herkam.
         */
        if (isDelete) {
            var question = dialogEntry;
            closeDialog();
            confirmDelete(question.kind, question.target, question.node);
            return;
        }

        /*
         * Speichern ist eine Änderung derselben Liste, zu der ein wartendes Löschen gehört, das
         * Löschen wird also vorher abgeschickt, statt in eine Seite zu laufen, die gerade neu
         * aufgebaut wird.
         */
        finishPendingDelete();
        var payload = null;
        var url = '';
        var method = 'POST';
        var successMessage = '';

        if (isDelete) {
            var target = dialogEntry.target;
            var isCategory = dialogEntry.kind === 'category';

            /*
             * Dieser Weg wird nur für einen Eintrag benutzt, in dem noch etwas liegt - ein leerer
             * wird sofort gelöscht, siehe requestDelete - der Server bekommt also das eine, wonach
             * er fragt: eine schlichte Bestätigung. Es wird nichts getippt, und für eine Karte
             * geht überhaupt kein Inhalt mit, weil die Kennung in der Adresse schon sagt, was
             * gemeint ist.
             */
            payload = isCategory ? { confirm: true } : undefined;

            url = (isCategory ? config.endpoints.category : config.endpoints.card)
                + '?id=' + encodeURIComponent(target.id);
            method = 'DELETE';
        } else if (isCard) {
            payload = validateCardForm();

            if (payload === null) {
                return;
            }

            var isCardEdit = dialogEntry !== null;

            if (!isCardEdit) {
                payload.category_id = dialogParentId;
            }

            /* Wird gemerkt, bevor das Fenster schließt: der Weg "speichern und weiter" braucht
               ihn, um das leere Formular für dieselbe Unterkategorie wieder zu öffnen. */
            cardSubmitCategoryId = dialogParentId;

            url = isCardEdit
                ? config.endpoints.card + '?id=' + encodeURIComponent(dialogEntry.id)
                : config.endpoints.cards;
            method = isCardEdit ? 'PATCH' : 'POST';
        } else {
            payload = validateCategoryForm();

            if (payload === null) {
                return;
            }

            var isEdit = dialogEntry !== null;

            if (!isEdit) {
                payload.parent_id = dialogParentId;
            }

            url = isEdit
                ? config.endpoints.category + '?id=' + encodeURIComponent(dialogEntry.id)
                : config.endpoints.categories;
            method = isEdit ? 'PATCH' : 'POST';
        }

        var removedName = null;

        elements.dialogSubmit.dataset.idleLabel = elements.dialogSubmit.textContent;
        setBusy(true);

        apiRequest(url, method, payload).then(function (result) {
            setBusy(false);

            if (!result.ok) {
                /*
                 * Ein Löschen, das 404 antwortet, ist kein Fehlschlag der Anfrage: die Zeile ist
                 * wirklich weg (jemand anders hat sie gelöscht, oder sie war schon entfernt).
                 * Die Liste wird neu geladen, damit die Seite die Wahrheit zeigt statt einer
                 * Kachel, die nie gelöscht werden kann.
                 */
                if (isDelete && result.status === 404) {
                    closeDialog();
                    bootstrapDropAll();
                    render();
                    showFeedback(t('dialog.errorAlreadyGone'));
                    return;
                }

                handleSubmitFailure(result);
                return;
            }

            /*
             * Alles, was die Seite zeigt, kommt wieder aus der API, eine gespeicherte Zeile ist
             * also wirklich da und eine bearbeitete zeigt wirklich ihren neuen Text.
             */
            /* Die Karten der gezeigten Kategorie und die Zaehlungen am Baum. */
            bootstrapDropCards(config.categoryId);
            bootstrapDropCategories();

            if (isDelete) {
                var removedTarget = dialogEntry.target;
                var removedLabel = dialogEntry.kind === 'category'
                    ? displayName(removedTarget)
                    : removedTarget.front;
                var removedOpenEntry = dialogEntry.kind === 'category'
                    && currentEntry !== null
                    && currentEntry.id === removedTarget.id;

                closeDialog();
                showFeedback(t('feedback.deleted', { name: removedLabel }));

                if (removedOpenEntry) {
                    /* Die Seite selbst ist weg, der Browser geht also eine Ebene höher. */
                    window.location.href = removedTarget.parent_id === null
                        ? 'index.php'
                        : 'index.php?category=' + encodeURIComponent(removedTarget.parent_id);
                    return;
                }

                render();
                return;
            }

            var createdName = dialogEntry === null && result.data && typeof result.data.name === 'string'
                ? (locale === 'de' && typeof result.data.name_de === 'string' && result.data.name_de !== ''
                    ? result.data.name_de
                    : result.data.name)
                : null;

            newAreaId = dialogEntry === null && !isCard && result.data && typeof result.data.id === 'number'
                ? result.data.id
                : null;

            closeDialog();

            if (createdName !== null && !isCard) {
                showFeedback(t('feedback.created', { name: createdName }));
            } else {
                showFeedback(t('feedback.updated', { name: isCard ? payload.front : displayName(dialogEntry) }));
            }

            /*
             * "Speichern und nächste Karte": die Liste wird zuerst aus der API neu aufgebaut,
             * damit die gerade gespeicherte Karte wirklich darin steht, und dann öffnet sich das
             * leere Formular wieder für dieselbe Unterkategorie.
             */
            if (isCard && cardSaveAndNext) {
                cardSaveAndNext = false;
                render();

                window.setTimeout(function () {
                    openCardForm(null, cardSubmitCategoryId);
                }, prefersReducedMotion() ? 0 : 140);

                return;
            }

            render();
        });
    }

    /* ----------------------------------------------------------------------
       Start
       ---------------------------------------------------------------------- */


    /* ----------------------------------------------------------------------
       Wandern durch die Anwendung, ohne die Seite neu zu laden
       ---------------------------------------------------------------------- */

    /*
     * Jede Ansicht hat eine echte Adresse, und die Verweise behalten ihr echtes href: ein Klick
     * mit der mittleren Maustaste, ein Rechtsklick, "in neuem Reiter öffnen" und ein Suchroboter
     * verhalten sich wie bisher. Übernommen wird nur ein einfacher Linksklick - dann wird die
     * Ansicht aus dem Speicher gezeichnet, statt die ganze Seite neu zu laden.
     */
    function routeFromUrl() {
        var match = /[?&]category=(\d+)/.exec(window.location.search);
        var value = match === null ? null : Number(match[1]);

        config.categoryId = value === null || !isFinite(value) ? null : value;
    }

    /* Ist das eine der eigenen Ansichtsadressen? */
    function isOwnViewLink(link) {
        if (link.origin !== window.location.origin) {
            return false;
        }

        if (link.hasAttribute('download') || (link.target !== '' && link.target !== '_self')) {
            return false;
        }

        if (link.pathname.indexOf('/index.php') !== -1) {
            return true;
        }

        /* "index.php" als Ordnerindex ist dieselbe Seite. */
        return link.search.indexOf('category=') !== -1;
    }

    /*
     * Was über der Seite liegt, verschwindet, bevor eine andere Ansicht erscheint: ein offenes
     * Fenster, ein offenes Zeilenmenü, eine laufende Lerneinheit. Die Einheit wird hier ohne die
     * übliche Frage verlassen, weil die Adresse schon gewechselt hat - die gespeicherten
     * Antworten behalten ihre Zeilen.
     */
    function clearOverlaysForNavigation() {
        if (elements.dialog.open) {
            closeDialog();
        }

        closeMenu();

        if (learnSession !== null) {
            closeLearnView();
        }
    }

    function showViewFromUrl() {
        routeFromUrl();
        render();
        window.scrollTo(0, 0);
    }

    /*
     * Zwei Wege führen an dieselbe Stelle: ein Klick in der Seite und der Zurück- oder
     * Vorwärtsknopf des Browsers. Beide ändern nur die Adresse und lassen die Seite sich dann aus
     * dem Speicher zeichnen - nie ein Neuladen.
     */
    function wireNavigation() {
        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;

            if (link === null || !isOwnViewLink(link)) {
                return;
            }

            event.preventDefault();
            clearOverlaysForNavigation();

            var target = new URL(link.href);
            var match = /[?&]category=(\d+)/.exec(target.search);

            window.history.pushState(
                { categoryId: match === null ? null : Number(match[1]) },
                '',
                target.pathname + target.search
            );

            showViewFromUrl();
        });

        window.addEventListener('popstate', function () {
            clearOverlaysForNavigation();
            showViewFromUrl();
        });
    }
    function wireEvents() {
        elements.themeToggle.addEventListener('click', function () {
            applyTheme(theme === 'dark' ? 'light' : 'dark', true);
        });

        Array.prototype.forEach.call(elements.localeButtons, function (button) {
            button.addEventListener('click', function () {
                applyLocale(button.getAttribute('data-locale'), true);
            });
        });

        /* Anlegen folgt der Ebene, die gerade offen ist. */
        elements.addButton.addEventListener('click', openAddForCurrentEntry);


        /*
         * Der Knopf der leeren Startseite fragt, wer da ist: angemeldet öffnet er das Formular
         * für einen Lernbereich, abgemeldet öffnet er die Anmeldung. Der Server prüft die Sitzung
         * ohnehin - das hier hält nur einen Knopf aus der Seite, der in "no_user_session" enden
         * könnte.
         */
        elements.emptyAction.addEventListener('click', function () {
            if (authState.user === null) {
                openAuthDialog('sign_in');

                return;
            }

            openCategoryForm('create', null, null);
        });

        elements.emptyActionSecondary.addEventListener('click', function () {
            openAuthDialog('register');
        });

        elements.entryEmptyAction.addEventListener('click', function () {
            if (entryEmptyHandler !== null) {
                entryEmptyHandler();
            }
        });

        /* Ein Formular, ein Absende-Handler, drei Wege hinaus. */
        elements.dialogForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitDialog();
        });

        /*
         * Ein Löschen, das noch wartet, wird zu Ende gebracht, wenn die Seite verlassen wird,
         * ein geschlossener Reiter kann also keinen Eintrag hinterlassen, von dem der Person
         * gesagt wurde, dass er gelöscht ist. "keepalive" lässt den Browser die Anfrage nach dem
         * Entladen noch beenden.
         */
        window.addEventListener('pagehide', flushPendingDelete);

        elements.dialogClose.addEventListener('click', function () {
            closeDialog();
        });

        /*
         * Ein Klick, der auf dem Fensterelement selbst landet - nicht auf dem Formular darin - ist
         * ein Klick auf den Hintergrund. Er schließt das Fenster, aber nur, solange nichts
         * getippt wurde: mit Eingaben im Formular tut der Klick nichts, es geht also nie Arbeit
         * durch einen versehentlichen Klick verloren.
         */
        elements.dialog.addEventListener('click', function (event) {
            if (event.target !== elements.dialog || dialogUsed) {
                return;
            }

            closeDialog();
        });

        /*
         * Escape nimmt denselben Weg wie der Abbrechen-Knopf, der Fokus geht also immer dorthin
         * zurück, wo das Fenster geöffnet wurde.
         *
         * Es wird absichtlich doppelt behandelt: "cancel" ist das eigene Ereignis eines modalen
         * Fensters, und der Tastaturhandler deckt jede Situation ab, in der dieses Ereignis nicht
         * ankommt (ein eingebetteter Browser, ein Fenster, das nicht modal ist).
         */
        elements.dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDialog();
        });

        elements.dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDialog();
            }
        });

        elements.dialog.addEventListener('close', function () {
            elements.dialog.classList.remove('is-open');
        });

        /* Ein Klick irgendwo sonst, oder Escape, schließt ein offenes Menü. */
        document.addEventListener('click', closeMenu);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
            }
        });

        window.addEventListener('resize', updateLanguageUnderline);
    }

    /*
     * Die Sprache, die diese Person gewählt hat, oder die Sprache, die gerade auf dem Bildschirm
     * ist.
     *
     * Sie wird gelesen, bevor irgendetwas angefordert wird: die erste Antwort trägt die
     * Kartentexte und die Namen der Bereiche in EINER Sprache, und das muss die richtige sein.
     * Sie hier statt in init() zu lesen war der Grund, warum eine deutsche Oberfläche englische
     * Karten zeigte: die erste Antwort war schon unterwegs, als init() von der Wahl erfuhr.
     */
    function storedLocale() {
        var saved = readStorage(config.storageKeys.language);

        return typeof translations[saved] === 'object' ? saved : locale;
    }

    function init() {
        /* Das Startskript im Kopf hat das Erscheinungsbild schon gesetzt. */
        theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';

        locale = storedLocale();

        /* Ablaufreihenfolge für die beiden festen Blöcke über dem Raster. */
        applyRevealOrder(document.querySelectorAll('.view--start .reveal'), 0);

        wireEvents();
        wireAccountDialog();
        wireNewCardsDialog();

        applyLocale(locale, false);

        /* Eine Zeile, die für die gerade geladene Seite gedacht war: das Abmelden oder das
           Löschen, das direkt davor passiert ist. */
        showPendingNote();
    }

    /* ----------------------------------------------------------------------
       The card list of a subcategory
       ---------------------------------------------------------------------- */

    /* Zu welcher Unterkategorie ein "Speichern und nächste Karte" gehört. */
    var cardSubmitCategoryId = null;

    /*
     * Die drei Zustände, die eine Karte haben kann, mit der Formulierung und dem Klassennamen,
     * der zu jedem gehört. Der Zustand selbst kommt aus der API: sie zählt, was in der Datenbank
     * steht, inklusive ob eine Karte fällig ist, und der Browser wiederholt es nur.
     */
    function cardStatusMeta(card) {
        var status = card.progress && typeof card.progress.status === 'string' ? card.progress.status : 'new';
        var name = status === 'known' ? 'known' : (status === 'unsure' ? 'unsure' : 'new');
        var hint = t('cards.status.' + name + 'Hint');

        /*
         * Eine fällige Karte sagt, seit wann. Das Datum wird in der eingestellten Sprache gezeigt,
         * und es ist nur ein Hinweis: das Wort neben dem Punkt sagt schon, dass etwas zu tun ist.
         */
        if (card.progress && card.progress.is_due === true && typeof card.progress.due_at === 'string') {
            hint = hint + ' · ' + t('cards.dueHint', { date: formatDueDate(card.progress.due_at) });
        }

        return {
            status: name,
            label: t('cards.status.' + name),
            hint: hint,
            className: 'row__status--' + name
        };
    }

    /* Macht aus "2026-09-21 15:04:05" ein kurzes Datum in der aktuellen Sprache. */
    function formatDueDate(value) {
        var parsed = new Date(String(value).replace(' ', 'T'));

        if (isNaN(parsed.getTime())) {
            return String(value);
        }

        return parsed.toLocaleDateString(locale === 'de' ? 'de-DE' : 'en-GB', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    }

    /*
     * Der Kopf einer Kartenliste: wie viele Karten es gibt, wie viele davon warten, und der
     * Streifen, der die drei Zustände als Anteile zeigt.
     *
     * null heißt "diese Seite hat keine Kartenliste", dann geht der ganze Kopf weg.
     */
    function renderCardTools(summary) {
        var usable = summary !== null
            && typeof summary === 'object'
            && typeof summary.total === 'number'
            && summary.total > 0;

        /*
         * Die Zahlen dieser Liste stehen jetzt in den Kacheln unter dem Kopf, das Einzige, was
         * dieser Streifen noch trägt, ist also das Suchfeld - und eine Suche über drei Karten ist
         * mehr Arbeit, als sie anzusehen, es erscheint deshalb erst ab etwa fünfzehn Karten. Ohne
         * es bleibt der Streifen ganz weg.
         */
        var withSearch = usable && summary.total >= cardSearchMin;

        elements.cardTools.hidden = !withSearch;
        elements.cardSearchWrap.hidden = !withSearch;

        if (!withSearch) {
            return;
        }

        elements.cardSearch.setAttribute('placeholder', t('cards.searchPlaceholder'));
        elements.cardSearch.setAttribute('aria-label', t('cards.search'));
    }

    /*
     * Baut die Zeilen, die gerade sichtbar sind.
     *
     * Der Filter läuft über die Liste, die schon geladen ist, und fragt den Server nach nichts:
     * eine Suche ist eine Ansicht derselben Daten, und aus dem Getippten wird keine Abfrage
     * gebaut.
     */
    function renderCardList(categoryId) {
        var query = cardSearchQuery.trim().toLowerCase();
        var visible = [];
        var index;

        elements.entryList.textContent = '';

        for (index = 0; index < openCards.length; index++) {
            if (query === '' || cardMatches(openCards[index], query)) {
                visible.push({ card: openCards[index], index: index });
            }
        }

        /* Nichts passt zur Suche: das ist nicht "noch keine Karten". */
        elements.cardSearchEmpty.textContent = query === '' ? '' : t('cards.searchEmpty');
        elements.cardSearchEmpty.hidden = query === '' || visible.length > 0;

        if (visible.length === 0 && query !== '') {
            elements.entryList.hidden = true;
            return;
        }

        visible.forEach(function (entry) {
            elements.entryList.appendChild(buildCardRow(entry.card, entry.index));
        });

        elements.entryList.hidden = false;
    }

    function cardMatches(card, query) {
        var front = typeof card.front === 'string' ? card.front.toLowerCase() : '';
        var back = typeof card.back === 'string' ? card.back.toLowerCase() : '';

        return front.indexOf(query) !== -1 || back.indexOf(query) !== -1;
    }

    /*
     * Die lebende Vorschau im Kartenfenster: die beiden Textfelder so, wie sie getippt werden, in
     * der Form, die die Lerneinheit benutzt, das Geschriebene ist also das, was später gefragt
     * wird.
     */
    function buildCardPreview() {
        var wrap = el('div', 'dialog__field dialog__field--preview');

        var head = el('div', 'dialog__preview-head');
        var label = el('p', 'dialog__label', t('dialog.card.preview'));
        label.setAttribute('data-i18n', 'dialog.card.preview');

        /* Welche Sprache gerade in der Vorschau steht. */
        var language = el('span', 'dialog__preview-language', '');
        language.id = 'card-preview-language';

        head.appendChild(label);
        head.appendChild(language);

        /*
         * Die Vorschau lässt sich umdrehen, beide Seiten des Geschriebenen sind also zu sehen,
         * bevor gespeichert wird. Sie ist mit der Tastatur erreichbar wie jede andere Bedienung
         * auf der Seite.
         */
        var card = el('div', 'card-preview');
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-label', t('dialog.card.preview'));
        card.addEventListener('click', function () {
            card.classList.toggle('is-flipped');
        });
        card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                card.classList.toggle('is-flipped');
            }
        });
        var frontLabel = el('p', 'card-preview__label', t('dialog.card.previewFront'));
        frontLabel.setAttribute('data-i18n', 'dialog.card.previewFront');

        var front = el('p', 'card-preview__text', '');
        front.id = 'card-preview-front';

        var backLabel = el('p', 'card-preview__label', t('dialog.card.previewBack'));
        backLabel.setAttribute('data-i18n', 'dialog.card.previewBack');

        var back = el('p', 'card-preview__text', '');
        back.id = 'card-preview-back';

        /*
         * Zwei Seiten, genau wie die Karte in einer Einheit: die Vorderseite ist das, was gefragt
         * wird, die Rückseite das, was geantwortet wird, und der Klick auf die Vorschau zeigt
         * das eine oder das andere.
         */
        var frontSide = el('div', 'card-preview__side card-preview__side--front');
        frontSide.appendChild(frontLabel);
        frontSide.appendChild(front);

        var backSide = el('div', 'card-preview__side card-preview__side--back');
        backSide.appendChild(backLabel);
        backSide.appendChild(back);

        /* Die lebende Vorschau der Landkarte, auf der Seite, die die Antwort trägt. */
        var previewMap = el('div', 'card-map card-map--preview');
        previewMap.id = 'card-preview-map';
        previewMap.hidden = true;
        backSide.appendChild(previewMap);

        card.appendChild(frontSide);
        card.appendChild(backSide);

        var count = el('p', 'dialog__hint', '');
        count.id = 'card-preview-count';

        /*
         * Der Hinweis auf die Tastenkuerzel steht nicht mehr hier, sondern als
         * Chip in der Fusszeile (siehe index.php): dort bleibt er an seinem
         * Platz, statt mit dem Formular wegzuscrollen.
         */
        wrap.appendChild(head);
        wrap.appendChild(card);
        wrap.appendChild(count);

        return wrap;
    }

    /* Schreibt die beiden Felder in die Vorschau und behält die Zeilenumbrüche bei. */
    function updateCardPreview() {
        if (dialogKind !== 'card' || !dialogFields.front || !dialogFields.back) {
            return;
        }

        var front = document.getElementById('card-preview-front');
        var back = document.getElementById('card-preview-back');
        var count = document.getElementById('card-preview-count');

        if (front !== null) {
            front.textContent = dialogFields.front.control.value;
            front.classList.toggle('is-empty', dialogFields.front.control.value.trim() === '');
        }

        if (back !== null) {
            back.textContent = dialogFields.back.control.value;
            back.classList.toggle('is-empty', dialogFields.back.control.value.trim() === '');
        }

        showMap(document.getElementById('card-preview-map'), dialogMapValue(), 'card-map card-map--preview');

        if (count !== null) {
            count.textContent = t('dialog.card.frontCount', {
                count: dialogFields.front.control.value.length,
                max: config.limits.cardText
            });
        }

        var language = document.getElementById('card-preview-language');

        if (language !== null) {
            language.textContent = t(cardTab === 'en' ? 'dialog.card.languageEn' : 'dialog.card.languageDe');
        }
    }

    /*
     * Die beiden Tastenkürzel des Kartenfensters: Tippen in den Feldern aktualisiert die Vorschau,
     * und Strg+Enter speichert, ohne zur Maus zu greifen. Die Tabulatortaste wird in Ruhe
     * gelassen - sie geht von der Vorderseite zur Rückseite von selbst, weil das die Reihenfolge
     * der Felder ist.
     */
    function wireCardDialogShortcuts() {
        ['front', 'back'].forEach(function (name) {
            var field = dialogFields[name];

            if (!field) {
                return;
            }

            field.control.addEventListener('input', function () {
                /* Was getippt wird, gehört zu der Sprache, die offen ist. */
                if (cardDraft !== null) {
                    cardDraft[cardTab].front = dialogFields.front.control.value;
                    cardDraft[cardTab].back = dialogFields.back.control.value;
                }

                updateCardPreview();
                updateCardLanguageTabs();
            });

            field.control.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    submitDialog();
                }
            });
        });
    }

    /* ----------------------------------------------------------------------
       Die Lerneinheit
       ---------------------------------------------------------------------- */

    /*
     * Eine Einheit, nur im Arbeitsspeicher gehalten:
     *
     *   queue     die Aufgaben, die durchzuarbeiten sind, in der Reihenfolge der API
     *   index     die Aufgabe, die auf dem Bildschirm steht
     *   flipped   ob die Antwort zu sehen ist
     *   results   Kartenkennung -> der Zustand, den die API dafür gespeichert hat
     *   ratings   jede gegebene Antwort, der Reihe nach (für die Zusammenfassung)
     *   undo      die letzte Antwort, mit allem, was zum Zurücknehmen nötig ist
     *
     * Vom Zeitplan wird hier nichts gerechnet. Die Reihenfolge der Aufgaben, der Zustand und
     * der Abstand kommen alle von der API; diese Seite zeigt sie nur und zählt, was ihr gesagt
     * wurde.
     */
    var learnSession = null;

    var learnTimer = null;


    /* Öffnet die Einheit für die offene Unterkategorie. */
    function startLearning(mode, targetCategoryId, label) {
        /*
         * Die Einheit gehört zu dem Eintrag, der angeklickt wurde: dem offenen, der
         * Unterkategorie hinter einer Zeile der Liste oder dem ganzen Lernbereich hinter
         * "Alles lernen". Was dazugehört, entscheidet der Server.
         */
        var categoryId = typeof targetCategoryId === 'number'
            ? targetCategoryId
            : (currentEntry === null ? null : currentEntry.id);

        if (categoryId === null) {
            return;
        }

        var url = config.endpoints.review
            + '?category_id=' + encodeURIComponent(categoryId)
            + '&language=' + encodeURIComponent(locale)
            + '&mode=' + (mode === 'difficult' ? 'difficult' : 'all');

        elements.learnButton.disabled = true;

        apiRequest(url, 'GET').then(function (result) {
            elements.learnButton.disabled = false;

            if (!result.ok || result.data === null || !Array.isArray(result.data.queue)) {
                showFeedback(errorMessage(result.code));
                return;
            }

            if (result.data.queue.length === 0) {
                showFeedback(t('learn.noCards'));
                return;
            }

            /*
             * Angemeldet fragt die Einheit zuerst, wie viele neue Karten sie vorstellen soll.
             * Vierzig neue Karten in einer Einheit sind ein Versprechen, das niemand halten
             * kann, und wie viele davon jemand auf sich nimmt, ist eine Entscheidung der lernenden
             * Person und nicht der Aufgabenliste.
             *
             * Ohne Konto gibt es nichts zu merken und nichts zu begrenzen; der Modus
             * "schwierig" wiederholt Karten, die schon im Lernzustand sind. Beide haben nichts
             * zu wählen.
             *
             * Die Frage wird hier gestellt und nicht beim Druck auf den Knopf, weil erst nach
             * dem Abruf der Aufgabenliste bekannt ist, wie viele neue Karten es wirklich gibt.
             */
            var queue = result.data.queue.slice();
            var counts = result.data.counts !== null && typeof result.data.counts === 'object' ?
                result.data.counts : {};
            var fresh = typeof counts['new'] === 'number' ? counts['new'] : 0;

            if (result.data.has_user === true && mode !== 'difficult' && fresh > 0) {
                openNewCardsDialog(fresh, function (limit) {
                    beginLearnSession(categoryId, result.data, label, limitNewCards(queue, limit));
                });

                return;
            }

            beginLearnSession(categoryId, result.data, label, queue);
        });
    }

    /*
     * Öffnet die Einheit mit der geholten Aufgabenliste - und, wenn jemand angemeldet ist, auf
     * die Zahl der neuen Karten eingegrenzt, die gewünscht wurde.
     */
    function beginLearnSession(categoryId, data, label, queue) {
        /* Nichts mehr zu zeigen: alles wurde weggefiltert. */
        if (queue.length === 0) {
            showFeedback(t('learn.noCards'));
            return;
        }

        learnSession = {
            categoryId: categoryId,
            mode: data.mode === 'difficult' ? 'difficult' : 'all',
            label: typeof label === 'string' ? label : (currentEntry === null ? '' : displayName(currentEntry)),
            queue: queue,
            hasUser: data.has_user === true,
            /*
             * Der Lernlauf in der Datenbank. Er ist hier noch null: die Zeile entsteht mit der
             * ERSTEN Antwort dieses Laufs, ein Lauf, der geöffnet und ohne Antwort wieder
             * geschlossen wird, lässt also nichts zurück. Die Kennung kommt mit dieser ersten
             * Antwort zurück (siehe rateLearnCard).
             */
            sessionId: null,
            index: 0,
            flipped: false,
            busy: false,
            results: {},
            ratings: [],
            again: {},
            undo: null,
            saved: 0
        };

        openLearnView();
        renderLearnCard();
    }

    /*
     * Behält jede fällige Karte und nur so viele neue, wie gewünscht wurden.
     *
     * Gezählt wird pro KARTE und nicht pro Aufgabe: eine Karte, die in beide Richtungen geübt
     * wird, steht zweimal in der Liste, und beide ihrer Aufgaben müssen zusammen bleiben oder
     * zusammen gehen - sonst würde eine Einheit dieselbe Karte zweimal zeigen und sie als zwei
     * zählen. Die fälligen Karten behalten ihren Platz vorne, die gewählten neuen folgen, und
     * die Reihenfolge der Liste bleibt so, wie der Server sie gebaut hat.
     */
    function limitNewCards(queue, limit) {
        var kept = [];
        var seen = {};
        var taken = 0;

        queue.forEach(function (entry) {
            if (entry.status !== 'new') {
                kept.push(entry);
                return;
            }

            var key = String(entry.card_id);

            if (Object.prototype.hasOwnProperty.call(seen, key) === false) {
                if (taken >= limit) {
                    return;
                }

                seen[key] = true;
                taken++;
            }

            kept.push(entry);
        });

        return kept;
    }

    /* ----------------------------------------------------------------------
       The question before a session starts
       ---------------------------------------------------------------------- */

    /* The sizes that are offered as one tap. */
    var NEW_CARDS_CHOICES = [5, 10, 20];

    /* What the dialog does when it is answered, and how many cards there are. */
    var newCardsHandler = null;
    var newCardsAvailable = 0;

    /*
     * Asks how many new cards this session should introduce and calls the handler
     * with the answer. Closing the window answers nothing at all.
     */
    function openNewCardsDialog(available, handler) {
        var dialog = elements.newCardsDialog;

        /* No window in the page: take everything rather than block the session. */
        if (dialog === null) {
            handler(available);
            return;
        }

        newCardsHandler = handler;
        newCardsAvailable = available;

        elements.newCardsHint.textContent = t(
            available === 1 ? 'learn.newCardsHintOne' : 'learn.newCardsHintOther',
            { count: available }
        );
        elements.newCardsInput.setAttribute('max', String(available));
        elements.newCardsError.hidden = true;
        elements.newCardsError.textContent = '';

        buildNewCardsQuick(available);

        /* What is offered first: the usual ten, or everything if there is less. */
        var start = Math.min(10, available);
        elements.newCardsInput.value = String(start);
        markNewCardsQuick(start);

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        window.requestAnimationFrame(function () {
            dialog.classList.add('is-open');
        });

        /* The number is the only thing to do here, so the focus goes there. */
        window.setTimeout(function () {
            elements.newCardsInput.focus();
            elements.newCardsInput.select();
        }, prefersReducedMotion() ? 0 : 200);
    }

    function closeNewCardsDialog() {
        var dialog = elements.newCardsDialog;

        if (dialog === null) {
            return;
        }

        dialog.classList.remove('is-open');

        window.setTimeout(function () {
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }, prefersReducedMotion() ? 0 : 200);
    }

    /* The shortcuts: the three usual sizes and "all" - and only what is there. */
    function buildNewCardsQuick(available) {
        elements.newCardsQuick.textContent = '';

        NEW_CARDS_CHOICES.forEach(function (count) {
            if (count >= available) {
                return;
            }

            elements.newCardsQuick.appendChild(buildNewCardsChoice(String(count), count));
        });

        elements.newCardsQuick.appendChild(buildNewCardsChoice(t('learn.newCardsAll'), available));
    }

    function buildNewCardsChoice(label, count) {
        var button = document.createElement('button');
        button.type = 'button';
        button.dataset.count = String(count);
        button.setAttribute('aria-pressed', 'false');
        button.textContent = label;
        button.addEventListener('click', function () {
            elements.newCardsInput.value = String(count);
            elements.newCardsError.hidden = true;
            markNewCardsQuick(count);
        });

        return button;
    }

    /* Which shortcut is the chosen one: the one whose number stands in the field. */
    function markNewCardsQuick(count) {
        Array.prototype.forEach.call(elements.newCardsQuick.children, function (button) {
            var chosen = Number(button.dataset.count) === count;
            button.setAttribute('aria-pressed', chosen ? 'true' : 'false');
        });
    }

    /*
     * The answer: a whole number between 0 and what is there. Anything else is
     * answered with one line under the field and the window stays open.
     */
    function confirmNewCards() {
        var raw = elements.newCardsInput.value.trim();
        var count = /^\d+$/.test(raw) ? parseInt(raw, 10) : NaN;

        if (isNaN(count) || count > newCardsAvailable) {
            elements.newCardsError.textContent = t('learn.newCardsError', { max: newCardsAvailable });
            elements.newCardsError.hidden = false;
            elements.newCardsInput.focus();
            return;
        }

        var handler = newCardsHandler;
        newCardsHandler = null;
        closeNewCardsDialog();

        /* Zero is a real answer: only the cards that are due. */
        if (handler !== null) {
            handler(count);
        }
    }

    function wireNewCardsDialog() {
        var dialog = elements.newCardsDialog;

        if (dialog === null) {
            return;
        }

        elements.newCardsStart.addEventListener('click', confirmNewCards);
        elements.newCardsInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                confirmNewCards();
            }
        });

        /* Typing a number moves the mark to whichever shortcut it belongs to. */
        elements.newCardsInput.addEventListener('input', function () {
            elements.newCardsError.hidden = true;
            markNewCardsQuick(Number(elements.newCardsInput.value));
        });

        /* Leaving the window: the session starts only when it was answered. */
        [elements.newCardsCancel, elements.newCardsClose].forEach(function (button) {
            button.addEventListener('click', function () {
                newCardsHandler = null;
                closeNewCardsDialog();
            });
        });

        /* Escape closes it like the X - and starts nothing. */
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            newCardsHandler = null;
            closeNewCardsDialog();
        });
    }

    function openLearnView() {
        elements.learn.hidden = false;
        elements.learnSummary.hidden = true;
        elements.learnStage.hidden = false;
        elements.learnAsk.hidden = true;
        elements.learnNotice.hidden = true;
        document.body.classList.add('is-learning');

        /* The focus goes into the session, so the keyboard works right away. */
        elements.learnCard.focus();

        if (learnSession.hasUser !== true) {
            showLearnNotice(t('learn.noUser'), true);
        }
    }

    /*
     * Shows the turn that is on screen: the counter, the line, the two sides and
     * the four answers with the interval each of them would lead to.
     */
    function renderLearnCard() {
        if (learnSession === null) {
            return;
        }

        var entry = learnSession.queue[learnSession.index];

        if (entry === undefined) {
            showLearnSummary();
            return;
        }

        elements.learnCounter.textContent = t('learn.counter', {
            position: learnSession.index + 1,
            total: learnSession.queue.length
        });

        var percent = Math.min(100, Math.round((learnSession.index / learnSession.queue.length) * 100));
        elements.learnProgressFill.style.width = percent + '%';
        elements.learnProgress.setAttribute('aria-valuenow', String(percent));
        elements.learnProgress.setAttribute('aria-valuetext', t('learn.progress', { percent: percent }));

        /*
         * An exercise card shows the task that was rolled for this session. Both
         * sides come from the same card, so the answer belongs to the numbers on
         * the other side - and the next session draws new ones.
         */
        var task = exerciseTask(entry);

        elements.learnFrontText.textContent = task === null ? entry.front : task.question;
        elements.learnBackText.textContent = task === null ? entry.back : task.answer;

        /*
         * Both sides of the card carry the map, and only the marking makes the
         * difference: the question shows the country or the continent pale, so the
         * answer is not given away, and the answer side marks the region. On a card
         * that asks "where is Bavaria?" the person therefore sees the outline of
         * Germany first and the marked state only after turning the card. A card
         * that is studied the other way round behaves the same, because the front of
         * the card is the question whichever text stands on it.
         *
         * A card without a region passes null, and then both sides stay empty.
         */
        showMap(elements.learnMapFront, entry.map_region, 'card-map card-map--learn', false);
        showMap(elements.learnMapBack, entry.map_region, 'card-map card-map--learn');
        elements.learnCard.setAttribute('aria-label', task === null
            ? (learnSession.flipped ? entry.back : entry.front)
            : (learnSession.flipped ? task.answer : task.question));

        /* Both faces are written; which one is visible is the flip. */
        elements.learnCard.classList.toggle('is-flipped', learnSession.flipped);
        elements.learnStage.classList.toggle('is-flipped', learnSession.flipped);
        var sideKey = learnSession.flipped ? 'learn.answer' : 'learn.question';

        if (task === null) {
            elements.learnSideLabel.textContent = t(sideKey);
            elements.learnSideLabel.setAttribute('data-i18n', sideKey);
        } else {
            /* A title is data, not a translation key: switching the language must
               not overwrite it. */
            elements.learnSideLabel.textContent = entry.front !== '' ? entry.front : t(task.label);
            elements.learnSideLabel.removeAttribute('data-i18n');
        }
        elements.learnHint.hidden = learnSession.flipped;

        buildLearnButtons(entry);
    }

    /*
     * The four answers. The interval under each of them comes from the API, which
     * calculated it with the same scheduler that will store the answer.
     */
    function buildLearnButtons(entry) {
        var previews = entry.preview_minutes && typeof entry.preview_minutes === 'object' ? entry.preview_minutes : null;

        elements.learnButtons.textContent = '';

        learnRatingKeys().forEach(function (rating) {
            var button = el('button', 'learn__button learn__button--' + rating.name);
            button.type = 'button';
            button.disabled = !learnSession.flipped;
            button.dataset.rating = String(rating.value);

            var label = el('span', 'learn__button-label', t(rating.labelKey));
            label.setAttribute('data-i18n', rating.labelKey);

            var key = el('span', 'learn__button-key', rating.key);
            key.setAttribute('aria-hidden', 'true');

            button.appendChild(label);
            button.appendChild(key);

            if (previews !== null) {
                var minutes = previews[rating.name];
                var interval = el('span', 'learn__button-interval', '');
                interval.setAttribute(
                    'title',
                    t('learn.shortcut', { key: rating.key })
                );

                if (typeof minutes === 'number') {
                    interval.textContent = t('learn.nextInterval', { interval: formatLearnInterval(minutes) });
                }

                button.appendChild(interval);
            }

            button.setAttribute(
                'aria-label',
                t(rating.labelKey) + (typeof previews === 'object' && previews !== null && typeof previews[rating.name] === 'number'
                    ? ', ' + t('learn.nextInterval', { interval: formatLearnInterval(previews[rating.name]) })
                    : '')
            );

            button.addEventListener('click', function () {
                rateLearnCard(rating.value);
            });

            elements.learnButtons.appendChild(button);
        });
    }

    /* The four answers, in the order they are shown and pressed. */
    function learnRatingKeys() {
        return [
            { value: 1, key: '1', name: 'again', labelKey: 'learn.again' },
            { value: 2, key: '2', name: 'hard', labelKey: 'learn.hard' },
            { value: 3, key: '3', name: 'good', labelKey: 'learn.good' },
            { value: 4, key: '4', name: 'easy', labelKey: 'learn.easy' }
        ];
    }

    /* "10 min", "19 h", "1 day", "3 days": the number comes from the server. */
    function formatLearnInterval(minutes) {
        if (minutes < 60) {
            return t('learn.intervalMinutes', { count: minutes });
        }

        if (minutes < 60 * 24) {
            return t('learn.intervalHours', { count: Math.round(minutes / 60) });
        }

        var days = Math.round((minutes / (60 * 24)) * 10) / 10;

        return days === 1
            ? t('learn.intervalOneDay')
            : t('learn.intervalDays', { count: days });
    }

    function flipLearnCard() {
        if (learnSession === null || learnSession.busy) {
            return;
        }

        learnSession.flipped = !learnSession.flipped;

        elements.learnCard.classList.toggle('is-flipped', learnSession.flipped);
        elements.learnStage.classList.toggle('is-flipped', learnSession.flipped);
        elements.learnSideLabel.textContent = t(learnSession.flipped ? 'learn.answer' : 'learn.question');
        elements.learnHint.hidden = learnSession.flipped;

        var entry = learnSession.queue[learnSession.index];

        if (entry !== undefined) {
            elements.learnCard.setAttribute('aria-label', learnSession.flipped ? entry.back : entry.front);
        }

        /* The answers keep their place: before the flip they are there, but out
           of reach. */
        Array.prototype.forEach.call(elements.learnButtons.children, function (button) {
            button.disabled = !learnSession.flipped;
        });

        /*
         * The focus stays on the card while it is turned over. If it jumped to
         * the first answer, the space bar would press that answer instead of
         * turning the card back - and the space bar is meant to turn the card,
         * always. The four answers are reached with Tab, and their number keys
         * work at any time.
         */
        elements.learnCard.focus();
    }

    /*
     * Stores one answer. The API decides everything: the new status, the interval
     * and when the card comes back. This function only shows what came back.
     */
    function rateLearnCard(rating) {
        if (learnSession === null || learnSession.busy || !learnSession.flipped) {
            return;
        }

        if (learnSession.hasUser !== true) {
            showLearnNotice(t('learn.noUser'));
            return;
        }

        var entry = learnSession.queue[learnSession.index];

        if (entry === undefined) {
            return;
        }

        learnSession.busy = true;
        setLearnButtonsDisabled(true);

        apiRequest(config.endpoints.review, 'POST', {
            action: 'rate',
            category_id: learnSession.categoryId,
            card_id: entry.card_id,
            rating: rating,
            /* Null with the first answer of the run: that is what creates it. */
            session_id: learnSession.sessionId
        }).then(function (result) {
            /*
             * The session can be closed while the answer is on its way - with
             * Escape, for example. Then there is nothing left to update, and
             * touching it would throw instead of simply doing nothing.
             */
            if (learnSession === null) {
                return;
            }

            learnSession.busy = false;
            setLearnButtonsDisabled(false);

            if (!result.ok) {
                /* The answer was NOT stored, so the card stays where it is and
                   the message says why. */
                showLearnNotice(errorMessage(result.code));
                return;
            }

            var data = result.data;

            /* The run this answer was counted in - the id arrives with the first
               answer of the run and is carried on with every further one. */
            if (typeof data.session_id === 'number') {
                learnSession.sessionId = data.session_id;
            }

            learnSession.saved++;
            learnSession.ratings.push(rating);
            learnSession.results[entry.card_id] = data.status;

            /*
             * "Again" comes back inside the same session. The card is put behind
             * everything that is left, twice at most, so a card that is simply
             * not there yet cannot keep the session running forever.
             */
            var repeats = learnSession.again[entry.card_id] || 0;
            var pushedAgain = false;

            if (rating === 1 && repeats < 2) {
                learnSession.again[entry.card_id] = repeats + 1;
                learnSession.queue.push(entry);
                pushedAgain = true;
            }

            learnSession.undo = {
                index: learnSession.index,
                cardId: entry.card_id,
                rating: rating,
                stored: data.stored,
                previous: data.previous_state,
                hadProgressBefore: data.had_progress_before === true,
                pushedAgain: pushedAgain
            };

            if (rating === 1) {
                showLearnNotice(t('learn.rateAgain'));
            } else {
                hideLearnNotice();
            }

            moveToNextLearnCard();
        });
    }

    function setLearnButtonsDisabled(disabled) {
        Array.prototype.forEach.call(elements.learnButtons.children, function (button) {
            button.disabled = disabled || !learnSession.flipped;
        });
    }

    /* The card leaves to the left, the next one comes in from the right. */
    function moveToNextLearnCard() {
        var reduced = prefersReducedMotion();

        elements.learnCard.classList.add('is-leaving-left');

        window.clearTimeout(learnTimer);
        learnTimer = window.setTimeout(function () {
            elements.learnCard.classList.remove('is-leaving-left', 'is-flipped');
            learnSession.index++;
            learnSession.flipped = false;

            if (learnSession.index >= learnSession.queue.length) {
                showLearnSummary();
                return;
            }

            renderLearnCard();
            elements.learnCard.classList.add('is-entering-right');

            learnTimer = window.setTimeout(function () {
                elements.learnCard.classList.remove('is-entering-right');
            }, reduced ? 0 : 260);
        }, reduced ? 0 : 250);
    }

    /*
     * Takes the last answer back. The API checks that nothing changed since, so a
     * card that was rated again in another tab is never overwritten silently.
     */
    function undoLearnRating() {
        if (learnSession === null || learnSession.busy || learnSession.undo === null) {
            return;
        }

        var undo = learnSession.undo;

        learnSession.busy = true;

        apiRequest(config.endpoints.review, 'POST', {
            action: 'undo',
            category_id: learnSession.categoryId,
            card_id: undo.cardId,
            stored: undo.stored,
            previous: undo.previous,
            /* The run and the answer, so its counters follow the undo. */
            session_id: learnSession.sessionId,
            rating: undo.rating
        }).then(function (result) {
            learnSession.busy = false;

            if (!result.ok) {
                showLearnNotice(errorMessage(result.code));
                return;
            }

            learnSession.saved = Math.max(0, learnSession.saved - 1);
            learnSession.ratings.pop();
            delete learnSession.results[undo.cardId];

            if (undo.pushedAgain) {
                var last = learnSession.queue.length - 1;

                while (last > undo.index && learnSession.queue[last].card_id === undo.cardId) {
                    learnSession.queue.splice(last, 1);
                    last--;
                }
            }

            learnSession.undo = null;
            learnSession.index = undo.index;
            learnSession.flipped = false;

            elements.learnSummary.hidden = true;
            elements.learnStage.hidden = false;

            renderLearnCard();
            showLearnNotice(t('learn.undone'));
        });
    }

    /*
     * The end of a session: how many cards are known now, how the answers were
     * spread, and the two ways on.
     */
    function showLearnSummary() {
        if (learnSession === null) {
            return;
        }

        var counts = { again: 0, hard: 0, good: 0, easy: 0 };
        var known = 0;
        var total = 0;
        var seen = {};

        learnSession.ratings.forEach(function (rating) {
            var found = learnRatingKeys().filter(function (item) {
                return item.value === rating;
            })[0];

            if (found) {
                counts[found.name]++;
            }
        });

        Object.keys(learnSession.results).forEach(function (cardId) {
            seen[cardId] = true;
        });

        total = Math.max(1, Object.keys(seen).length);

        Object.keys(learnSession.results).forEach(function (cardId) {
            if (learnSession.results[cardId] === 'known') {
                known++;
            }
        });

        elements.learnStage.hidden = true;
        elements.learnSummary.hidden = false;
        elements.learnSummaryNumber.textContent = t('learn.done.known', { known: known, total: total });

        elements.learnSummaryBars.textContent = '';

        var highest = Math.max(1, counts.again, counts.hard, counts.good, counts.easy);

        learnRatingKeys().forEach(function (rating) {
            var item = el('li', 'learn__bar learn__bar--' + rating.name);
            var label = el('span', 'learn__bar-label', t(rating.labelKey));
            label.setAttribute('data-i18n', rating.labelKey);
            var track = el('span', 'learn__bar-track');
            var fill = el('span', 'learn__bar-fill');
            fill.style.setProperty('--share', (counts[rating.name] / highest) * 100 + '%');
            track.appendChild(fill);
            var value = el('span', 'learn__bar-value', String(counts[rating.name]));
            item.appendChild(label);
            item.appendChild(track);
            item.appendChild(value);
            elements.learnSummaryBars.appendChild(item);
        });

        /* The difficult cards of this session can be repeated right away. */
        var difficult = learnSession.ratings.filter(function (rating) {
            return rating === 1 || rating === 2;
        }).length;

        elements.learnRepeat.hidden = difficult === 0;
        elements.learnSummaryLeft.textContent = t('learn.done.left', { count: counts.again });
        elements.learnSummaryLeft.hidden = counts.again === 0 && difficult === 0;

        elements.learnFinish.focus();
    }

    /* Leaves the session and reloads the list, so the dots are up to date. */
    /*
     * Tells the server that the run is over, so the row in study_sessions gets its
     * ended_at.
     *
     * Not awaited: leaving the learning view must never wait for the network. A
     * call that fails costs nothing - the row stays open, exactly what a killed
     * browser tab leaves behind, and the streak counts by started_at anyway (see
     * database/add_study_sessions.sql).
     */
    function endLearnSessionOnServer() {
        if (learnSession === null || !learnSession.sessionId || learnSession.hasUser !== true) {
            return;
        }

        apiRequest(config.endpoints.review, 'POST', {
            action: 'session_end',
            session_id: learnSession.sessionId
        });
    }

    function closeLearnView() {
        window.clearTimeout(learnTimer);

        /* The run ends with the view, and it is closed before the session object
           is dropped - the id is needed for the call. */
        endLearnSessionOnServer();

        /*
         * Which category was studied, before the session is dropped: the cards of
         * exactly this category changed their status and leave the store.
         */
        bootstrapDropCards(learnSession === null ? null : learnSession.categoryId);
        learnSession = null;

        elements.learn.hidden = true;
        elements.learnAsk.hidden = true;
        elements.learnNotice.hidden = true;
        elements.learnCard.classList.remove('is-flipped', 'is-leaving-left', 'is-entering-right');
        document.body.classList.remove('is-learning');

        /* The tree carries the counters of the area and the subcategory, so it
           is dropped as well - the cards of the other categories stay. */
        bootstrapDropCategories();
        render();

        /* Back to the way in that was used - if it is still on the page. */
        if (!elements.learnButton.hidden) {
            elements.learnButton.focus();
        }
    }

    /*
     * Closing asks first when answers were already stored. A session that has
     * only been looked at closes straight away - there is nothing to lose.
     */
    function askBeforeClosingLearn() {
        if (learnSession === null) {
            return;
        }

        if (learnSession.saved === 0) {
            closeLearnView();
            return;
        }

        elements.learnAskText.textContent = t('learn.askEnd.text', { count: learnSession.saved });
        elements.learnAsk.hidden = false;
        elements.learnAskCancel.focus();
    }

    function showLearnNotice(message, show) {
        elements.learnNotice.textContent = message;
        elements.learnNotice.hidden = show === false;
    }

    function hideLearnNotice() {
        elements.learnNotice.hidden = true;
        elements.learnNotice.textContent = '';
    }

    /* ----------------------------------------------------------------------
       Wiring: the study button, the search, the session keys and the swipe
       ---------------------------------------------------------------------- */

    function wireLearning() {
        elements.learnButton.addEventListener('click', function () {
            startLearning('all');
        });

        /*
         * The search field arrives read-only, which is what keeps the browser's
         * autofill out of it - the reason is written next to the input in
         * index.php. A read-only field still takes focus and still receives
         * clicks, so the first interaction is where it is handed over to the
         * person. Setting readOnly to false twice is harmless.
         */
        ['focus', 'mousedown', 'touchstart'].forEach(function (name) {
            elements.cardSearch.addEventListener(name, function () {
                elements.cardSearch.readOnly = false;
            });
        });

        /* The search filters the loaded list; it never asks the server. */
        elements.cardSearch.addEventListener('input', function () {
            cardSearchQuery = elements.cardSearch.value;

            if (currentEntry !== null) {
                renderCardList(currentEntry.id);
            }
        });

        elements.learnClose.addEventListener('click', askBeforeClosingLearn);
        elements.learnAskCancel.addEventListener('click', function () {
            elements.learnAsk.hidden = true;
            elements.learnCard.focus();
        });
        elements.learnAskConfirm.addEventListener('click', closeLearnView);
        elements.learnFinish.addEventListener('click', closeLearnView);

        elements.learnRepeat.addEventListener('click', function () {
            startLearning('difficult');
        });

        /* A click on the card turns it over - on a phone this is the tap. */
        elements.learnCard.addEventListener('click', function (event) {
            if (event.target.closest('button') === null) {
                flipLearnCard();
            }
        });

        wireLearnKeyboard();
        wireLearnSwipe();
    }

    /*
     * The keys of a session: space and Enter turn the card over, 1 to 4 answer
     * it, the left arrow takes the last answer back and Escape ends the session.
     *
     * The listener sits on the document, so it works no matter which element has
     * the focus - but it stays out of the way while a button is focused, because
     * Enter and space belong to that button then.
     */
    function wireLearnKeyboard() {
        document.addEventListener('keydown', function (event) {
            if (learnSession === null) {
                return;
            }

            /* A question about ending the session owns the keyboard. */
            if (!elements.learnAsk.hidden) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    elements.learnAsk.hidden = true;
                    elements.learnCard.focus();
                }

                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                askBeforeClosingLearn();
                return;
            }

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                undoLearnRating();
                return;
            }

            var focusedIsButton = document.activeElement !== null
                && typeof document.activeElement.closest === 'function'
                && document.activeElement.closest('button') !== null;

            if (event.key === ' ' || event.key === 'Enter') {
                if (focusedIsButton && event.target !== elements.learnCard) {
                    return;
                }

                event.preventDefault();
                flipLearnCard();
                return;
            }

            if (event.key >= '1' && event.key <= '4') {
                event.preventDefault();
                rateLearnCard(Number(event.key));
            }
        });
    }

    /*
     * Swiping: to the left means "Again", to the right means "Good". The four
     * buttons stay where they are - a swipe is a shortcut, not the only way.
     */
    function wireLearnSwipe() {
        var start = null;

        elements.learnStage.addEventListener('pointerdown', function (event) {
            start = { x: event.clientX, y: event.clientY, id: event.pointerId };
        });

        elements.learnStage.addEventListener('pointerup', function (event) {
            if (start === null || start.id !== event.pointerId) {
                start = null;
                return;
            }

            var dx = event.clientX - start.x;
            var dy = event.clientY - start.y;
            start = null;

            if (learnSession === null || learnSession.busy || !learnSession.flipped) {
                return;
            }

            /* Only a clearly horizontal movement counts as a swipe. */
            if (Math.abs(dx) < 70 || Math.abs(dy) > 60) {
                return;
            }

            rateLearnCard(dx < 0 ? 1 : 3);
        });

        elements.learnStage.addEventListener('pointercancel', function () {
            start = null;
        });
    }

    wireLearning();
    /* ----------------------------------------------------------------------
       The two languages of a card
       ---------------------------------------------------------------------- */

    /*
     * Which languages a card can carry. There is one until the English columns
     * exist in the table - the API reports the truth and this side only follows
     * it, so the language tabs appear by themselves the moment the migration has
     * been run.
     */
    var cardContentLanguages = ['de'];

    /* What is in the two fields, per language, while the card dialog is open. */
    var cardDraft = null;
    var cardTab = 'de';
    var cardTabs = {};

    /*
     * One side of a card in one language.
     *
     * The German text lives in the two original columns (`front` and `back`), so
     * this falls back to them when the language-specific key is not there. That
     * keeps the dialog correct with and without the English columns.
     */
    function cardText(card, side, language) {
        var value = card[side + '_' + language];

        if (typeof value !== 'string' && language === 'de') {
            value = card[side];
        }

        return typeof value === 'string' ? value : '';
    }

    /* The name of a language, in the language of the interface. */
    function cardLanguageName(code) {
        return t(code === 'en' ? 'dialog.card.languageEn' : 'dialog.card.languageDe');
    }

    /*
     * The small switch above the two fields: "Deutsch" and "English". It only
     * exists while the table really holds two languages.
     */
    function buildLanguageTabs() {
        var wrap = el('div', 'dialog__field dialog__field--languages');
        var label = el('p', 'dialog__label', t('dialog.card.languageLabel'));
        label.setAttribute('data-i18n', 'dialog.card.languageLabel');

        var row = el('div', 'lang-tabs');
        row.setAttribute('role', 'tablist');

        cardTabs = {};

        cardContentLanguages.forEach(function (code) {
            var button = el('button', 'lang-tab');
            button.type = 'button';
            button.setAttribute('role', 'tab');

            var name = el('span', 'lang-tab__name', cardLanguageName(code));
            var mark = el('span', 'lang-tab__mark');
            mark.setAttribute('aria-hidden', 'true');

            button.appendChild(name);
            button.appendChild(mark);

            button.addEventListener('click', function () {
                switchCardLanguage(code);
            });

            row.appendChild(button);
            cardTabs[code] = button;
        });

        var hint = el('p', 'dialog__hint', t('dialog.card.languageHint'));
        hint.setAttribute('data-i18n', 'dialog.card.languageHint');

        wrap.appendChild(label);
        wrap.appendChild(row);
        wrap.appendChild(hint);

        updateCardLanguageTabs();

        return wrap;
    }

    /* Changes which language the two fields are showing. */
    function switchCardLanguage(code) {
        if (cardDraft === null || dialogFields.front === undefined || code === cardTab) {
            return;
        }

        /* Nothing is lost: what was typed stays with its language. */
        cardDraft[cardTab].front = dialogFields.front.control.value;
        cardDraft[cardTab].back = dialogFields.back.control.value;

        cardTab = code;

        dialogFields.front.control.value = cardDraft[code].front;
        dialogFields.back.control.value = cardDraft[code].back;

        updateCardLanguageTabs();
        updateCardPreview();

        var preview = document.querySelector('.card-preview');

        if (preview !== null) {
            preview.classList.remove('is-flipped');
        }

        dialogFields.front.control.focus();
    }

    /* Marks which language is open and which one still needs work. */
    function updateCardLanguageTabs() {
        if (cardDraft === null) {
            return;
        }

        Object.keys(cardTabs).forEach(function (code) {
            var button = cardTabs[code];
            var draft = cardDraft[code];
            var front = draft.front.trim();
            var back = draft.back.trim();
            var filled = front !== '' && back !== '';
            var half = (front !== '') !== (back !== '');
            var mark = button.querySelector('.lang-tab__mark');

            button.classList.toggle('is-active', code === cardTab);
            button.classList.toggle('is-filled', filled);
            button.classList.toggle('is-half', half);
            button.setAttribute('aria-selected', code === cardTab ? 'true' : 'false');
            button.setAttribute(
                'aria-label',
                cardLanguageName(code) + ', ' + t(filled ? 'dialog.card.languageFilled' : 'dialog.card.languageEmpty')
            );

            if (mark !== null) {
                mark.textContent = filled ? '\u2713' : (half ? '\u00b7' : '');
            }
        });
    }

    /* ----------------------------------------------------------------------
       The two actions in the head of an entry
       ---------------------------------------------------------------------- */

    /*
     * Fills the head of the detail view.
     *
     * level      'card' for a subcategory, 'area' for a learning area
     * cardCount  how many cards can be studied (the whole branch for an area)
     * hasEntries whether the list below has rows - if it has none, the empty
     *            state already offers the step of adding one, and a second
     *            button for the same thing would be one too many
     */
    /* ----------------------------------------------------------------------
       Importing cards from a CSV file
       ---------------------------------------------------------------------- */

    /*
     * The import writes into the subcategory that is open, so the button only
     * exists on a subcategory page. The dialog has two steps:
     *
     *   1. choose a file   -> the server reads it and answers with the summary,
     *                         the first rows and every row it cannot import
     *   2. press the button -> the same file is uploaded again, the server checks
     *                         it once more and writes all rows in ONE transaction
     *
     * Nothing is stored before step 2. A file with a single bad row is refused as
     * a whole, and the dialog says so before the button can be pressed at all.
     */
    function openImportDialog(categoryId) {
        dialogKind = 'import';
        dialogEntry = { categoryId: categoryId };
        dialogParentId = categoryId;
        dialogIcon = null;
        dialogUsed = false;
        dialogOpener = document.activeElement;
        dialogFields = {};
        importState = { categoryId: categoryId, file: null, preview: null, ready: false, busy: false };

        elements.dialogFields.textContent = '';
        clearDialogErrors();
        elements.dialogClose.setAttribute('aria-label', t('dialog.close'));
        elements.dialogShortcuts.hidden = true;
        elements.dialogSubmit.classList.remove('dialog__button--danger-pill');
        elements.dialogSubmit.textContent = t('dialog.import.submit');
        elements.dialogSubmit.disabled = true;
        elements.dialogTitle.textContent = t('dialog.import.title');
        elements.dialogMessage.hidden = true;

        elements.dialogFields.appendChild(buildImportPanel());

        openDialog();
        importPanel.zone.focus();
    }

    /*
     * The dashed area, the format hint and the link to the sample file.
     *
     * The area is ONE button: a click, Enter and Space open the file chooser, and
     * a file can be dropped on it as well. The file input itself is invisible but
     * real - it is what the browser needs to hand a file over.
     */
    function buildImportPanel() {
        var wrap = el('div', 'import');

        var zone = el('div', 'dropzone');
        zone.tabIndex = 0;
        zone.setAttribute('role', 'button');
        zone.setAttribute('aria-controls', 'import-file');

        var icon = el('span', 'dropzone__icon');
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = UPLOAD_SVG;

        var title = el('p', 'dropzone__title', t('dialog.import.dropTitle'));
        title.setAttribute('data-i18n', 'dialog.import.dropTitle');

        var fileText = el('p', 'dropzone__file');
        fileText.hidden = true;

        var input = el('input', 'import__input');
        input.type = 'file';
        input.id = 'import-file';
        input.accept = '.csv,text/csv';
        input.setAttribute('tabindex', '-1');

        zone.appendChild(icon);
        zone.appendChild(title);
        zone.appendChild(fileText);

        wrap.appendChild(zone);
        wrap.appendChild(input);

        zone.addEventListener('click', function () {
            input.click();
        });

        zone.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input.click();
            }
        });

        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                dialogUsed = true;
                chooseImportFile(input.files[0]);
            }
        });

        zone.addEventListener('dragover', function (event) {
            event.preventDefault();
            zone.classList.add('is-dragging');
        });

        zone.addEventListener('dragleave', function () {
            zone.classList.remove('is-dragging');
        });

        zone.addEventListener('drop', function (event) {
            event.preventDefault();
            zone.classList.remove('is-dragging');

            if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
                dialogUsed = true;
                chooseImportFile(event.dataTransfer.files[0]);
            }
        });

        var format = el('p', 'import__format', t('dialog.import.format'));
        format.setAttribute('data-i18n', 'dialog.import.format');

        var columns = el('code', 'import__columns', t('dialog.import.columns'));

        var languages = el('p', 'import__hint', t('dialog.import.languages'));
        languages.setAttribute('data-i18n', 'dialog.import.languages');

        var limits = el('p', 'import__hint', t('dialog.import.limits', {
            rows: config.limits.importRows,
            size: Math.round(config.limits.importBytes / (1024 * 1024))
        }));

        var sample = el('a', 'import__sample', t('dialog.import.sample'));
        sample.href = config.sampleCsv;
        sample.setAttribute('download', '');
        sample.setAttribute('data-i18n', 'dialog.import.sample');

        /* The answer of the server is put in here: summary, table, error list. */
        var result = el('div', 'import__result');

        wrap.appendChild(format);
        wrap.appendChild(columns);

        /* The optional sixth column: without it a file behaves exactly as before. */
        var columnsOptional = el('code', 'import__columns import__columns--optional', t('dialog.import.columnsOptional'));
        columnsOptional.setAttribute('data-i18n', 'dialog.import.columnsOptional');
        wrap.appendChild(columnsOptional);

        var exerciseHint = el('p', 'import__hint', t('dialog.import.exerciseHint'));
        exerciseHint.setAttribute('data-i18n', 'dialog.import.exerciseHint');
        wrap.appendChild(exerciseHint);

        wrap.appendChild(languages);
        wrap.appendChild(limits);
        wrap.appendChild(sample);
        wrap.appendChild(result);

        importPanel = {
            zone: zone,
            input: input,
            fileText: fileText,
            result: result
        };

        return wrap;
    }

    /*
     * A file was chosen. The two obvious things are checked here so the answer is
     * immediate; everything else is decided by the server.
     */
    function chooseImportFile(file) {
        var name = String(file.name || '');
        var extension = name.indexOf('.') === -1 ? '' : name.slice(name.lastIndexOf('.') + 1).toLowerCase();

        importPanel.fileText.hidden = false;
        importPanel.fileText.textContent = name;
        importPanel.zone.classList.add('has-file');
        importState.ready = false;
        elements.dialogSubmit.disabled = true;

        if (extension !== 'csv') {
            showImportProblem(t('import.error.file_type'));
            return;
        }

        if (file.size > config.limits.importBytes) {
            showImportProblem(t('import.error.file_too_large'));
            return;
        }

        checkImportFile(file);
    }

    /* One sentence above the drop zone, and no preview below it. */
    function showImportProblem(message) {
        importPanel.result.textContent = '';
        setDialogError(message);
    }

    /* Sends the file for a check and shows what came back. */
    function checkImportFile(file) {
        importState.file = file;
        importState.busy = true;
        setDialogError(null);
        importPanel.result.textContent = '';
        importPanel.result.appendChild(el('p', 'import__checking', t('dialog.import.checking')));

        uploadImport('preview').then(function (result) {
            importState.busy = false;
            importPanel.result.textContent = '';

            if (!result.ok) {
                showImportProblem(importMessage(result.code));
                return;
            }

            importState.preview = result.data;
            renderImportResult(result.data);
        });
    }

    /*
     * The upload itself. The file goes along as a file (multipart), so the server
     * reads it with fgetcsv() - the browser never has to understand CSV.
     */
    function uploadImport(mode) {
        var body = new FormData();
        body.append('mode', mode);
        body.append('category_id', String(importState.categoryId));
        body.append('file', importState.file, importState.file.name);

        return window.fetch(config.endpoints.importCards, { method: 'POST', body: body })
            .then(function (response) {
                return response.json()
                    .catch(function () {
                        return null;
                    })
                    .then(function (payload) {
                        if (response.ok && payload && payload.success === true) {
                            return { ok: true, data: payload.data };
                        }

                        return {
                            ok: false,
                            code: payload && payload.error ? String(payload.error.code) : 'request_failed'
                        };
                    });
            })
            .catch(function () {
                /* Die Anfrage hat den Server nie erreicht. */
                return { ok: false, code: 'network_error' };
            });
    }

    /* The summary, the first rows and every row that cannot be imported. */
    function renderImportResult(data) {
        importState.ready = false;

        if (data.fatal !== null && data.fatal !== undefined) {
            showImportProblem(importMessage(data.fatal.code, data.fatal.params));
            return;
        }

        var importable = Number(data.importable) || 0;
        var duplicates = Number(data.duplicates) || 0;
        var invalid = Number(data.invalid) || 0;

        importPanel.result.appendChild(el(
            'p',
            importable > 0 ? 'import__summary' : 'import__summary import__summary--quiet',
            importable === 1 ? t('dialog.import.summaryOne') : t('dialog.import.summary', { count: importable })
        ));

        if (duplicates > 0) {
            importPanel.result.appendChild(el('p', 'import__skipped', duplicates === 1
                ? t('dialog.import.duplicatesOne')
                : t('dialog.import.duplicates', { count: duplicates })));
        }

        if (invalid > 0) {
            importPanel.result.appendChild(el('p', 'import__problem', invalid === 1
                ? t('dialog.import.invalidOne')
                : t('dialog.import.invalid', { count: invalid })));

            var list = el('ul', 'import__errors');

            data.errors.forEach(function (entry) {
                list.appendChild(el('li', 'import__error', importRowMessage(entry)));
            });

            importPanel.result.appendChild(list);
            importPanel.result.appendChild(el('p', 'import__fix', t('dialog.import.fix')));
        }

        if (Array.isArray(data.preview) && data.preview.length > 0) {
            importPanel.result.appendChild(buildImportTable(data.preview, Number(data.hidden) || 0));
        }

        /*
         * Only a file without a single bad row and with at least one new card may
         * be imported - and only the button in the footer starts that.
         */
        if (invalid === 0 && importable > 0) {
            importState.ready = true;
            elements.dialogSubmit.disabled = false;
            elements.dialogSubmit.textContent = importable === 1
                ? t('dialog.import.submitOne')
                : t('dialog.import.submitMany', { count: importable });
        }
    }

    /* One row problem as a sentence, with the line number in front. */
    function importRowMessage(entry) {
        var params = entry.params || {};
        var key = 'import.row.' + String(entry.code || '');
        var sentence = t(key, {
            line: entry.line,
            found: params.found,
            expected: params.expected,
            max: params.max,
            value: params.value,
            language: cardLanguageName(params.language === 'en' ? 'en' : 'de')
        });

        /* An unknown code still says something useful. */
        return sentence === key ? t('dialog.import.fix') : sentence;
    }

    /* The first rows of the file, exactly as the server read them. */
    function buildImportTable(rows, hidden) {
        var wrap = el('div', 'import__table-wrap');
        var table = el('table', 'import__table');
        var head = el('thead');
        var headRow = el('tr');

        [
            'dialog.import.colLine',
            'dialog.import.colFrontDe',
            'dialog.import.colBackDe',
            'dialog.import.colFrontEn',
            'dialog.import.colBackEn',
            'dialog.import.colExercise',
            'dialog.import.colState'
        ].forEach(function (key) {
            var cell = el('th', '', t(key));
            cell.setAttribute('scope', 'col');
            cell.setAttribute('data-i18n', key);
            headRow.appendChild(cell);
        });

        head.appendChild(headRow);
        table.appendChild(head);

        var body = el('tbody');

        rows.forEach(function (row) {
            var state = String(row.state);
            var tr = el('tr', 'import__row import__row--' + state);
            var stateKey = state === 'ok'
                ? 'dialog.import.stateOk'
                : (state === 'duplicate' ? 'dialog.import.stateDuplicate' : 'dialog.import.stateInvalid');

            tr.appendChild(el('td', 'import__line', String(row.line)));
            tr.appendChild(el('td', 'import__text', String(row.front_de || '')));
            tr.appendChild(el('td', 'import__text', String(row.back_de || '')));
            tr.appendChild(el('td', 'import__text', String(row.front_en || '')));
            tr.appendChild(el('td', 'import__text', String(row.back_en || '')));

            /* The exercise column: what the file says, or nothing. */
            tr.appendChild(el('td', 'import__text import__text--exercise', String(row.exercise || '')));

            var stateCell = el('td', 'import__state');
            var badge = el('span', 'import__badge import__badge--' + state, t(stateKey));
            badge.setAttribute('data-i18n', stateKey);
            stateCell.appendChild(badge);
            tr.appendChild(stateCell);

            body.appendChild(tr);
        });

        table.appendChild(body);
        wrap.appendChild(table);

        if (hidden > 0) {
            wrap.appendChild(el('p', 'import__more', t('dialog.import.andMore', { count: hidden })));
        }

        return wrap;
    }

    /* The button: upload the same file again and let the server write it. */
    function runImport() {
        if (importState === null || importState.ready !== true || importState.busy === true) {
            return;
        }

        importState.busy = true;
        setDialogError(null);
        elements.dialogSubmit.dataset.idleLabel = elements.dialogSubmit.textContent;
        setBusy(true);

        uploadImport('import').then(function (result) {
            importState.busy = false;
            setBusy(false);

            if (!result.ok) {
                showImportProblem(importMessage(result.code));
                return;
            }

            var count = Number(result.data.imported) || 0;

            /* Everything the page shows comes from the API again, so the new
               cards really are in the list. */
            closeDialog();
            bootstrapDropAll();
            render();
            showFeedback(count === 1 ? t('feedback.importedOne') : t('feedback.imported', { count: count }));
        });
    }

    /*
     * A code from the import endpoint as a sentence. The import has its own texts
     * (they say more than the shared ones), and a code it does not know falls
     * back to the sentence every other request uses.
     */
    function importMessage(code, params) {
        var key = 'import.error.' + String(code || '');
        var sentence = t(key, params || {});

        return sentence === key ? errorMessage(code) : sentence;
    }

    /* ----------------------------------------------------------------------
       The map of a card
       ---------------------------------------------------------------------- */

    /*
     * A card may carry a map region: "DE:Bayern", "EU:FR" or "WORLD:CN". The area
     * decides which of three static files is shown, the region is the id of the
     * element inside it that is highlighted.
     *
     * Three rules make this safe and quick:
     *   * the value is checked against the same pattern the API uses. A value that
     *     does not match is ignored, and the card stays a text card.
     *   * the file is an application asset: it is fetched, parsed with DOMParser,
     *     cleaned (no <style>, no <script>, no inline style) and then copied. No
     *     value from the database is ever interpreted as markup - it is only used
     *     to look up one element by its id.
     *   * a file is fetched once per session and reused from memory afterwards,
     *     because the world map alone is about 1.2 MB.
     */
    /* Spelled exactly like CARD_MAP_REGION_PATTERN and CARD_MAP_REGION_MAX_LENGTH
       in src/services/card_service.php. The length cap is there because the
       column holds 40 characters: a longer value is not a key this app stored. */
    var MAP_PATTERN = /^(DE|EU|WORLD):[A-Za-z0-9_äöüÄÖÜß-]{1,32}$/u;
    var MAP_MAX_LENGTH = 40;
    var mapDocuments = {};
    var mapRequests = {};
    var dialogMapField = null;

    /* "DE:Bayern" -> { area, region, value }, or null when it is not a valid key. */
    function parseMapRegion(value) {
        if (typeof value !== 'string' || value.length > MAP_MAX_LENGTH
            || MAP_PATTERN.test(value) !== true) {
            return null;
        }

        var parts = value.split(':');

        return { area: parts[0], region: parts[1], value: value };
    }

    /* A readable name for a country code, in the language of the interface. */
    function countryName(code) {
        try {
            if (typeof window.Intl === 'object' && typeof window.Intl.DisplayNames === 'function') {
                var names = new window.Intl.DisplayNames([locale === 'de' ? 'de' : 'en'], { type: 'region' });
                var name = names.of(code);

                if (typeof name === 'string' && name !== '' && name !== code) {
                    return name;
                }
            }
        } catch (error) {
            /* An unknown code keeps the code itself as its name. */
        }

        return code;
    }

    /* The readable name of a region, for a tooltip or a screen reader. */
    function regionLabel(value) {
        var parsed = parseMapRegion(value);

        if (parsed === null) {
            return '';
        }

        if (parsed.area === 'DE') {
            var found = '';

            config.germanStates.forEach(function (state) {
                if (state.id === parsed.region) {
                    found = t(state.label);
                }
            });

            return found === '' ? parsed.region : found;
        }

        return countryName(parsed.region);
    }

    /*
     * Fetches one map file once and hands back a cleaned, inert SVG element.
     * Nothing here touches the page until the caller puts it somewhere.
     */
    function loadMap(area) {
        /* An area without a file has nothing to load: nothing is fetched and
           nothing is shown. Which areas have a file is decided in config.maps. */
        if (config.maps[area] === undefined) {
            return Promise.resolve(null);
        }

        if (mapDocuments[area] !== undefined) {
            return Promise.resolve(mapDocuments[area]);
        }

        if (mapRequests[area] === undefined) {
            mapRequests[area] = window.fetch(config.maps[area], { headers: { Accept: 'image/svg+xml' } })
                .then(function (response) {
                    return response.ok ? response.text() : '';
                })
                .then(function (text) {
                    if (text.trim() === '') {
                        mapDocuments[area] = null;

                        return null;
                    }

                    var parsed = new window.DOMParser().parseFromString(text, 'image/svg+xml');
                    var root = parsed.documentElement;

                    if (root === null || String(root.nodeName).toLowerCase() !== 'svg'
                        || parsed.getElementsByTagName('parsererror').length > 0) {
                        mapDocuments[area] = null;

                        return null;
                    }

                    /*
                     * The file may carry its own colours and its own size. Both are
                     * removed: the colours come from the stylesheet of the app, and
                     * the size comes from the box the map is put into.
                     */
                    Array.prototype.forEach.call(root.querySelectorAll('style, script, title, desc'), function (node) {
                        if (node.parentNode !== null) {
                            node.parentNode.removeChild(node);
                        }
                    });

                    Array.prototype.forEach.call(root.querySelectorAll('[style]'), function (node) {
                        node.removeAttribute('style');
                    });

                    root.removeAttribute('style');
                    root.removeAttribute('width');
                    root.removeAttribute('height');
                    root.setAttribute('preserveAspectRatio', 'xMidYMid meet');
                    root.setAttribute('aria-hidden', 'true');
                    root.setAttribute('focusable', 'false');

                    mapDocuments[area] = root;

                    return root;
                })
                .catch(function () {
                    /* No file, no network, bad XML: the card simply stays a text card. */
                    mapDocuments[area] = null;

                    return null;
                });
        }

        return mapRequests[area];
    }

    /*
     * The regions of an area, ready for a select: the id that is stored and the
     * readable name.
     *
     * Germany: the sixteen states from the configuration, because their ids are
     * the ones germany.svg uses. Europe and the world: the two letter ids inside
     * the file, with the name the browser knows for that code.
     */
    function regionsOfArea(area) {
        if (area === 'DE') {
            return Promise.resolve(config.germanStates.map(function (state) {
                return { id: state.id, label: t(state.label) };
            }));
        }

        return loadMap(area).then(function (root) {
            if (root === null) {
                return [];
            }

            var found = [];
            var selector = area === 'WORLD' ? 'g[id]' : 'path[id], g[id]';

            Array.prototype.forEach.call(root.querySelectorAll(selector), function (node) {
                if (!/^[A-Z]{2}$/.test(node.id)) {
                    return;
                }

                /*
                 * The files carry a few extra groups whose id is not a country
                 * code at all (world.svg has "XD" and "XL"). countryName() hands
                 * back the code itself when the browser knows no name for it, and
                 * an entry that can only offer a code is no help in a picker, so
                 * it stays out of the list. Values that are already stored are not
                 * affected: they are still shown on the card.
                 */
                var name = countryName(node.id);

                /*
                 * europe.svg holds Portugal twice, once for the mainland and once
                 * for the islands. A picker must not offer the same value twice, so
                 * the first entry of a code wins.
                 */
                var known = found.some(function (item) {
                    return item.id === node.id;
                });

                if (name !== node.id && known === false) {
                    found.push({ id: node.id, label: name });
                }
            });

            found.sort(function (first, second) {
                return first.label.localeCompare(second.label, locale === 'de' ? 'de' : 'en');
            });

            return found;
        });
    }

    /*
     * One map for one card: a fresh copy of the file with exactly one region
     * marked - or, with mark = false, the very same map without a marking. The copy
     * is needed because an element can only be in one place, and a list may show the
     * same map several times.
     *
     * The returned element stays hidden while there is nothing to show, so a card
     * without a map (or with a region the file does not know) simply shows its
     * text.
     */
    function buildCardMap(mapRegion, className, mark) {
        var parsed = parseMapRegion(mapRegion);
        var wrap = el('div', className || 'card-map');
        var marked = mark !== false;
        wrap.hidden = true;

        if (parsed === null || config.maps[parsed.area] === undefined) {
            return Promise.resolve(wrap);
        }

        return loadMap(parsed.area).then(function (root) {
            if (root === null) {
                return wrap;
            }

            var svg = root.cloneNode(true);
            var active = null;

            /* Looking the id up by walking the tree: an id may contain characters
               a CSS selector would have to escape. */
            Array.prototype.forEach.call(svg.querySelectorAll('*'), function (node) {
                if (active === null && node.id === parsed.region) {
                    active = node;
                }
            });

            if (active === null) {
                return wrap;
            }

            if (marked) {
                active.classList.add('is-active');
            }

            wrap.appendChild(svg);
            wrap.hidden = false;
            wrap.dataset.region = parsed.value;

            /* Only the marked map names its region. The title is a tooltip, and on
               the question side it would give the answer away. */
            if (marked) {
                wrap.setAttribute('title', regionLabel(parsed.value));
            }

            return wrap;
        });
    }

    /* Puts one map into a container, or leaves the container empty. mark = false
       draws the map without a marking, which is what the question side of the study
       card uses. */
    function showMap(container, mapRegion, className, mark) {
        if (container === null) {
            return;
        }

        container.textContent = '';
        container.hidden = true;

        if (parseMapRegion(mapRegion) === null) {
            return;
        }

        buildCardMap(mapRegion, className, mark).then(function (map) {
            if (map.hidden || map.firstChild === null) {
                return;
            }

            /* The inner map moves into the container, so there is no box in a box. */
            container.appendChild(map.firstChild);
            container.hidden = false;
        });
    }

    /* ----------------------------------------------------------------------
       The map field of the card dialog
       ---------------------------------------------------------------------- */

    /*
     * The readable name of every area of config.maps. An area whose key is missing
     * here shows its code instead of a name, so adding a map file cannot break the
     * picker - it only needs a translation key to get a nice label as well.
     */
    var MAP_AREA_LABELS = {
        DE: 'dialog.card.mapAreaDe',
        EU: 'dialog.card.mapAreaEu',
        WORLD: 'dialog.card.mapAreaWorld'
    };

    /*
     * First the area, then the region - nobody has to know an id by heart. The
     * map below the two selects shows the choice straight away, and "no map" is
     * the default: a region is always optional.
     */
    /* The exercise part of the card dialog, or null while it is not built. */
    var dialogExerciseField = null;

    /* Which kind of card the dialog is showing right now. */
    function cardKindValue() {
        if (dialogFields.card_kind === undefined) {
            return 'fixed';
        }

        return dialogFields.card_kind.control.value === 'exercise' ? 'exercise' : 'fixed';
    }

    /* What config.exerciseTypes says about one kind of task, or null. */
    function exerciseTypeSettings(type) {
        var types = config.exerciseTypes || {};

        return types[type] === undefined ? null : types[type];
    }

    /*
     * The generated task of a card, in the language of the interface - or null
     * when the card is a fixed card.
     *
     * The numbers are NOT drawn here. They are drawn on the server, in
     * exercise_service.php, and travel with the card: question and answer belong
     * to the same draw, which is what makes them match. A second generator in the
     * browser would be a second truth to keep in step, so there is none.
     */
    function exerciseTask(card) {
        if (card === null || typeof card !== 'object') {
            return null;
        }

        if (card.exercise === null || typeof card.exercise !== 'object') {
            return null;
        }

        var task = card.exercise.task;

        if (task === null || typeof task !== 'object') {
            return null;
        }

        return {
            type: card.exercise.type,
            label: card.exercise.label,
            params: card.exercise.params,
            question: exerciseText(task.question),
            answer: exerciseText(task.answer)
        };
    }

    /* One side of a task - question or answer - in the language of the interface.
       Both languages travel with the task, so switching the language switches the
       sentence without another request. */
    function exerciseText(side) {
        if (side === null || typeof side !== 'object') {
            return '';
        }

        return typeof side[locale] === 'string' ? side[locale] : side.de;
    }
    /*
     * Shows the fields that belong to the chosen kind of card and hides the rest.
     *
     * A fixed card asks for a question and an answer. An exercise card asks for a
     * title and for the numbers its task may use - the answer is generated, so an
     * answer field would be a field nobody may fill in.
     */
    function setCardKind(kind) {
        var exercise = kind === 'exercise';
        var label = dialogFields.front.wrap.querySelector('.dialog__label');

        if (label !== null) {
            var labelKey = exercise ? 'dialog.card.titleLabel' : 'dialog.card.frontLabel';
            label.textContent = t(labelKey);
            label.setAttribute('data-i18n', labelKey);
        }

        var placeholderKey = exercise ? 'dialog.card.titlePlaceholder' : 'dialog.card.frontPlaceholder';
        dialogFields.front.control.setAttribute('placeholder', t(placeholderKey));
        dialogFields.front.control.setAttribute('data-i18n-placeholder', placeholderKey);

        /* The answer of an exercise is generated, so it is not asked for. */
        dialogFields.back.wrap.hidden = exercise;

        if (dialogExerciseField !== null) {
            dialogExerciseField.wrap.hidden = !exercise;
        }

        ['front', 'back'].forEach(function (name) {
            clearFieldError(name);
        });

        /* Every parameter of the kind of task that is open, whatever it is
           called: the list of names comes from the schema, not from here. */
        Object.keys(dialogFields).forEach(function (name) {
            if (name === 'exercise_type' || name.indexOf('exercise_param_') === 0) {
                clearFieldError(name);
            }
        });
    }

    /*
     * The kind of task and the numbers it may use, built once per dialog.
     *
     * Both the list of kinds and the fields of each kind come from
     * config.exerciseTypes, which index.php builds from exercise_catalog(): the
     * dialog can therefore never offer a kind of task or a parameter that the
     * generator does not know, and a kind whose schema gains a field on the server
     * appears here with that field, without a second list to keep in step.
     */
    function addExerciseFields(exercise) {
        var types = config.exerciseTypes || {};
        var keys = Object.keys(types);

        if (keys.length === 0) {
            dialogExerciseField = null;

            return;
        }

        var known = exercise !== null && exercise !== undefined && types[exercise.type] !== undefined;
        var chosen = known ? exercise.type : keys[0];

        var wrap = el('div', 'dialog__field dialog__field--exercise');

        var label = el('label', 'dialog__label', t('dialog.card.exerciseTypeLabel'));
        label.setAttribute('for', 'dialog-field-exercise_type');
        label.setAttribute('data-i18n', 'dialog.card.exerciseTypeLabel');

        var select = el('select', 'dialog__select');
        select.id = 'dialog-field-exercise_type';
        select.name = 'exercise_type';

        keys.forEach(function (key) {
            select.appendChild(new Option(t(types[key].label), key));
        });

        select.value = chosen;

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');

        /* The sentence that explains the chosen kind of task. */
        var hint = el('p', 'dialog__hint');

        /*
         * The fields of the chosen kind live in a container of their own, so
         * switching the kind replaces them completely. A field left over from
         * another kind would otherwise travel with the card, although the task it
         * belongs to is not the one being saved.
         */
        var params = el('div', 'dialog__params');

        var preview = buildExercisePreview();

        wrap.appendChild(label);
        wrap.appendChild(select);
        wrap.appendChild(error);
        wrap.appendChild(hint);
        wrap.appendChild(params);
        wrap.appendChild(preview.wrap);

        elements.dialogFields.appendChild(wrap);

        /* Registered like every other field, so the error helpers work on it. */
        dialogFields.exercise_type = { control: select, error: error, wrap: wrap };

        dialogExerciseField = {
            wrap: wrap,
            select: select,
            hint: hint,
            params: params,
            preview: preview,
            /* The waiting timer and the number of the newest request. */
            pending: null,
            answer: 0
        };

        showExerciseHint();
        buildExerciseParamFields(known ? exercise.params : null);
        scheduleExercisePreview();

        select.addEventListener('change', function () {
            clearFieldError('exercise_type');
            showExerciseHint();
            /* The fields of the kind that was open are replaced, not hidden: the
               numbers of a task that is not the chosen one must not be sent. */
            buildExerciseParamFields(null);
            scheduleExercisePreview();
        });
    }

    /* The sentence under the type selector. */
    function showExerciseHint() {
        var settings = exerciseTypeSettings(dialogExerciseField.select.value);
        var key = settings === null ? null : settings.hint;

        dialogExerciseField.hint.textContent = key === null ? '' : t(key);
        dialogExerciseField.hint.hidden = key === null;
    }

    /* The name a parameter of the open kind of task is registered under. */
    function exerciseParamFieldName(name) {
        return 'exercise_param_' + name;
    }

    /* Forgets the fields of the kind of task that was open before. */
    function clearExerciseParamFields() {
        var wrap = dialogExerciseField.params;

        Object.keys(dialogFields).forEach(function (name) {
            if (name.indexOf('exercise_param_') === 0) {
                delete dialogFields[name];
            }
        });

        while (wrap.firstChild !== null) {
            wrap.removeChild(wrap.firstChild);
        }
    }

    /*
     * One field per parameter of the chosen kind of task, built from its schema.
     *
     * "values" are the numbers of the card that is being edited, or null for a
     * card that does not exist yet. A value the schema does not allow is replaced
     * by the default of that field, so the form always shows numbers the generator
     * can work with.
     */
    function buildExerciseParamFields(values) {
        var settings = exerciseTypeSettings(dialogExerciseField.select.value);

        clearExerciseParamFields();

        if (settings === null) {
            return;
        }

        var schema = settings.params || {};
        var stored = values !== null && values !== undefined && typeof values === 'object' ? values : {};

        Object.keys(schema).forEach(function (name) {
            var field = schema[name];
            var value = stored[name] === undefined ? field.default : stored[name];

            if (field.kind === 'int') {
                addField(exerciseParamFieldName(name), 'number', {
                    labelKey: 'exercise.param.' + name,
                    value: String(wholeNumberInRange(value, field)),
                    min: field.lowest,
                    max: field.highest,
                    container: dialogExerciseField.params,
                    onInput: scheduleExercisePreview
                });

                return;
            }

            if (field.kind === 'select') {
                addField(exerciseParamFieldName(name), 'select', {
                    labelKey: 'exercise.param.' + name,
                    value: field.options.indexOf(value) === -1 ? field.default : value,
                    options: field.options.map(function (option) {
                        return { value: option, label: t('exercise.option.' + option) };
                    }),
                    container: dialogExerciseField.params,
                    onInput: scheduleExercisePreview
                });

                return;
            }

            if (field.kind === 'multi') {
                addExerciseChoiceField(name, field, value);

                return;
            }

            /* The remaining kind is a yes/no parameter. */
            addField(exerciseParamFieldName(name), 'checkbox', {
                labelKey: 'exercise.param.' + name,
                checked: value === true,
                container: dialogExerciseField.params,
                onInput: scheduleExercisePreview
            });
        });
    }

    /* A whole number inside the limits of its field. */
    function wholeNumberInRange(value, field) {
        var number = Number(value);

        if (!isFinite(number) || Math.floor(number) !== number) {
            return field.default;
        }

        return Math.min(Math.max(number, field.lowest), field.highest);
    }

    /*
     * A parameter that allows any number of options at once: one box per option.
     *
     * The boxes share one entry in dialogFields, so a message about the whole
     * choice has somewhere to appear and the group as a whole can be marked.
     */
    function addExerciseChoiceField(name, field, value) {
        var chosen = Array.isArray(value) ? value : [field.default];
        var fieldName = exerciseParamFieldName(name);

        var wrap = el('div', 'dialog__field dialog__field--checks');
        var label = el('p', 'dialog__label', t('exercise.param.' + name));
        label.id = 'dialog-field-' + fieldName + '-label';
        label.setAttribute('data-i18n', 'exercise.param.' + name);

        var list = el('div', 'dialog__checks');
        list.setAttribute('role', 'group');
        list.setAttribute('aria-labelledby', label.id);

        var boxes = [];

        field.options.forEach(function (option) {
            var row = el('label', 'dialog__check-row');

            var box = el('input', 'dialog__check');
            box.type = 'checkbox';
            box.value = option;
            box.setAttribute('value', option);
            box.checked = chosen.indexOf(option) !== -1;

            var text = el('span', 'dialog__check-text', t('exercise.option.' + option));
            text.setAttribute('data-i18n', 'exercise.option.' + option);

            row.appendChild(box);
            row.appendChild(text);
            list.appendChild(row);
            boxes.push(box);

            box.addEventListener('input', function () {
                dialogUsed = true;
                clearFieldError(fieldName);
                scheduleExercisePreview();
            });

            box.addEventListener('change', function () {
                dialogUsed = true;
            });
        });

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');

        wrap.appendChild(label);
        wrap.appendChild(list);
        wrap.appendChild(error);
        dialogExerciseField.params.appendChild(wrap);

        dialogFields[fieldName] = { control: list, error: error, wrap: wrap, boxes: boxes };
    }

    /*
     * The example under the fields: one task built on the server from the numbers
     * that are in the fields right now.
     *
     * It is never built here. The numbers the card will really show are drawn in
     * exercise_service.php, and the example has to come from that same place, or
     * it could promise something the card does not keep.
     */
    function buildExercisePreview() {
        var wrap = el('div', 'dialog__field dialog__field--example');

        var label = el('p', 'dialog__label', t('dialog.exercise.previewLabel'));
        label.setAttribute('data-i18n', 'dialog.exercise.previewLabel');

        var question = el('span', 'dialog__example-text', '');
        var arrow = el('span', 'dialog__example-arrow', '→');
        arrow.setAttribute('aria-hidden', 'true');
        var answer = el('span', 'dialog__example-text dialog__example-text--answer', '');

        var line = el('p', 'dialog__example-line');
        line.appendChild(question);
        line.appendChild(arrow);
        line.appendChild(answer);

        var note = el('p', 'dialog__hint', '');

        wrap.appendChild(label);
        wrap.appendChild(line);
        wrap.appendChild(note);

        return { wrap: wrap, line: line, question: question, answer: answer, note: note };
    }

    /*
     * Reads the numbers out of the fields of the open dialog.
     *
     * Returns {params: {...}} when every field holds something the schema allows,
     * and {error: {name, message}} when one of them does not. The server checks the
     * same thing again before it stores anything and is the one that counts; this
     * check only exists so the answer does not have to wait for a request.
     */
    function readExerciseParams(type) {
        var settings = exerciseTypeSettings(type);

        if (settings === null) {
            return { error: { kind: 'broken', name: 'exercise_type', message: t('dialog.errorExerciseType') } };
        }

        var schema = settings.params || {};
        var params = {};
        var wrong = null;

        Object.keys(schema).forEach(function (name) {
            if (wrong !== null) {
                return;
            }

            var field = schema[name];
            var fieldName = exerciseParamFieldName(name);
            var entry = dialogFields[fieldName];

            if (entry === undefined) {
                wrong = { kind: 'broken', name: fieldName, message: t('dialog.errorExerciseParams') };

                return;
            }

            if (field.kind === 'int') {
                var raw = String(entry.control.value).trim();
                var number = Number(raw);
                var whole = raw !== '' && isFinite(number) && Math.floor(number) === number;

                if (!whole || number < field.lowest || number > field.highest) {
                    /*
                     * Two different situations, and the difference matters to
                     * whoever is looking at the empty example: a field that was
                     * left empty has to be filled in, a number outside its limits
                     * has to be corrected. Both used to end in the same sentence.
                     */
                    wrong = {
                        kind: raw === '' ? 'empty' : 'range',
                        name: fieldName,
                        message: t('dialog.errorExerciseRange', { min: field.lowest, max: field.highest })
                    };

                    return;
                }

                params[name] = number;

                return;
            }

            if (field.kind === 'select') {
                params[name] = entry.control.value;

                return;
            }

            if (field.kind === 'multi') {
                var picked = entry.boxes.filter(function (box) {
                    return box.checked === true;
                }).map(function (box) {
                    return box.value;
                });

                if (picked.length === 0) {
                    wrong = { kind: 'choices', name: fieldName, message: t('dialog.errorExerciseChoices') };

                    return;
                }

                params[name] = picked;

                return;
            }

            params[name] = entry.control.checked === true;
        });

        if (wrong !== null) {
            return { error: wrong };
        }

        /* A range that runs backwards cannot build a task. The server would put it
           the right way round, but nobody writes it that way on purpose. */
        if (params.min !== undefined && params.max !== undefined && params.min > params.max) {
            return {
                error: {
                    kind: 'order',
                    name: exerciseParamFieldName('max'),
                    message: t('dialog.errorExerciseOrder')
                }
            };
        }

        return { params: params };
    }

    /*
     * Typing is not a reason to ask: the request waits until the typing stops. A
     * second change while the first request is still on its way only lets the
     * newest answer through (the counter in dialogExerciseField).
     */
    function scheduleExercisePreview() {
        if (dialogExerciseField === null) {
            return;
        }

        if (dialogExerciseField.pending !== null) {
            window.clearTimeout(dialogExerciseField.pending);
        }

        dialogExerciseField.pending = window.setTimeout(refreshExercisePreview, 300);
    }

    function refreshExercisePreview() {
        if (dialogExerciseField === null) {
            return;
        }

        dialogExerciseField.pending = null;

        var read = readExerciseParams(dialogExerciseField.select.value);

        if (read.params === undefined) {
            /*
             * Something in the fields cannot be used, so the example of the moment
             * before is dropped instead of staying on screen - it would not belong
             * to these numbers. What is said instead is the reason: an empty field
             * is not the same thing as a number outside its limits, and until now
             * both ended in "fill in the fields above", which made a working
             * example look as if it had stopped working altogether.
             */
            showExerciseExample(null, read.error.kind === 'empty'
                ? t('dialog.exercise.previewIncomplete')
                : read.error.message);

            return;
        }

        var field = dialogExerciseField;

        field.answer++;
        var wanted = field.answer;

        apiRequest(config.endpoints.exercisePreview, 'POST', {
            exercise_type: field.select.value,
            exercise_params: read.params
        }).then(function (result) {
            /* The answer belongs to the fields it was asked for. A dialogue
               that was closed or reopened in the meantime has other fields
               now, and this answer must not be written into them. */
            if (field !== dialogExerciseField || wanted !== field.answer) {
                return;
            }

            if (result.ok !== true || !result.data || !result.data.task) {
                showExerciseExample(null, t('dialog.exercise.previewFailed'));

                return;
            }

            showExerciseExample(result.data.task, null);
        });
    }

    /*
     * Writes one example into the block, or the sentence that says why there is
     * none. The sentence arrives ready to be read: the caller knows whether it is
     * about an empty field, a number outside its limits or a request that failed.
     */
    function showExerciseExample(task, note) {
        if (dialogExerciseField === null) {
            return;
        }

        var preview = dialogExerciseField.preview;
        var ready = task !== null && task !== undefined;

        preview.line.hidden = !ready;
        preview.note.hidden = ready;

        if (ready) {
            preview.question.textContent = exerciseText(task.question);
            preview.answer.textContent = exerciseText(task.answer);
            preview.note.textContent = '';

            return;
        }

        preview.question.textContent = '';
        preview.answer.textContent = '';
        preview.note.textContent = note === null || note === undefined ? '' : note;
    }
    function addMapField(value) {
        var parsed = parseMapRegion(value);
        var wrap = el('div', 'dialog__field dialog__field--map');
        var label = el('p', 'dialog__label', t('dialog.card.mapLabel'));
        label.setAttribute('data-i18n', 'dialog.card.mapLabel');

        var row = el('div', 'map-picker');
        var area = el('select', 'dialog__select');
        var region = el('select', 'dialog__select');

        area.id = 'dialog-field-map-area';
        region.id = 'dialog-field-map-region';
        area.appendChild(new Option(t('dialog.card.mapNone'), ''));

        /* One entry per map file in config.maps. Never a second list of areas:
           an area with a file is always offered and an area without a file is
           never offered, so the picker and the map files cannot drift apart. */
        Object.keys(config.maps).forEach(function (name) {
            var key = MAP_AREA_LABELS[name];

            area.appendChild(new Option(key === undefined ? name : t(key), name));
        });

        var note = el('p', 'dialog__hint');
        note.hidden = true;

        var preview = el('div', 'card-map card-map--dialog');
        preview.hidden = true;

        var error = el('p', 'dialog__field-error');
        error.hidden = true;
        error.setAttribute('role', 'alert');

        row.appendChild(area);
        row.appendChild(region);
        wrap.appendChild(label);
        wrap.appendChild(row);
        wrap.appendChild(note);
        wrap.appendChild(preview);
        wrap.appendChild(error);
        elements.dialogFields.appendChild(wrap);

        /* The field is registered like every other one, so clearDialogErrors()
           and setFieldError() work on it. */
        dialogFields.map_region = { control: region, error: error, wrap: wrap };
        /* `stored` is the value the card had when the dialog was opened, `touched`
           stays false until the user changes one of the two selects himself.
           Together they keep a region the picker cannot show - see
           dialogMapValue(). */
        dialogMapField = {
            area: area,
            region: region,
            note: note,
            preview: preview,
            stored: parsed === null ? null : parsed.value,
            touched: false
        };

        if (parsed !== null) {
            area.value = parsed.area;
        }

        fillRegionOptions(parsed === null ? null : parsed.region);

        area.addEventListener('change', function () {
            dialogUsed = true;
            dialogMapField.touched = true;
            fillRegionOptions(null);
        });

        region.addEventListener('change', function () {
            dialogUsed = true;
            dialogMapField.touched = true;
            updateMapPreview();
        });
    }

    /*
     * What the two selects mean together, or null for "no map".
     *
     * The two selects can only show the areas of config.maps. A stored region of
     * an area that is not offered (because its map file was taken out of that
     * list) has no option to appear in, so the picker looks as if the card had no
     * map at all. As long as the user has not touched either select, the value the
     * dialog was opened with is therefore handed back unchanged: opening a card
     * and saving it must never destroy a region that the dialog merely cannot
     * display. Only a change the user makes himself replaces it.
     */
    function dialogMapValue() {
        if (dialogMapField === null) {
            return null;
        }

        var area = dialogMapField.area.value;
        var region = dialogMapField.region.value;

        if (area === '' || dialogMapField.region.hidden || region === '') {
            return dialogMapField.touched ? null : dialogMapField.stored;
        }

        return area + ':' + region;
    }

    /* Fills the second select for the chosen area and keeps the wanted region. */
    function fillRegionOptions(wanted) {
        var field = dialogMapField;

        if (field === null) {
            return;
        }

        var area = field.area.value;
        field.region.textContent = '';
        field.region.hidden = true;
        field.note.hidden = false;

        if (area === '') {
            /* A stored region whose area is not offered: the picker cannot show it,
               so it says what happens to it instead of looking like a card without
               a map. The value itself is kept until the user chooses something. */
            field.note.textContent = field.stored !== null && field.touched === false
                ? t('dialog.card.mapKept', { region: regionLabel(field.stored) })
                : t('dialog.card.mapChooseArea');
            updateMapPreview();

            return;
        }

        if (area === 'DE') {
            regionsOfArea('DE').then(function (regions) {
                if (dialogMapField !== field) {
                    return;
                }

                regions.forEach(function (item) {
                    field.region.appendChild(new Option(item.label, item.id));
                });

                if (wanted !== null) {
                    field.region.value = wanted;
                }

                field.region.hidden = false;
                field.note.hidden = true;
                updateMapPreview();
            });

            return;
        }

        /* Europe and the world: the ids are inside the file, so it has to be
           loaded - that is the moment the browser shows what it is doing. */
        field.note.textContent = t('dialog.card.mapLoading');

        regionsOfArea(area).then(function (regions) {
            if (dialogMapField !== field) {
                return;
            }

            if (regions.length === 0) {
                field.note.textContent = t('dialog.card.mapUnavailable');
                updateMapPreview();

                return;
            }

            regions.forEach(function (item) {
                field.region.appendChild(new Option(item.label, item.id));
            });

            if (wanted !== null) {
                Array.prototype.forEach.call(field.region.options, function (option) {
                    if (option.value === wanted) {
                        field.region.value = wanted;
                    }
                });
            }

            field.region.hidden = false;
            field.note.hidden = true;
            updateMapPreview();
        });
    }

    /* The map under the two selects shows the region that is chosen right now. */
    function updateMapPreview() {
        if (dialogMapField === null) {
            return;
        }

        showMap(dialogMapField.preview, dialogMapValue(), 'card-map card-map--dialog');
        clearFieldError('map_region');
    }

    function showHeadActions(level, categoryId, cardCount, hasEntries) {
        var isArea = level === 'area';
        var canStudy = typeof cardCount === 'number' && cardCount > 0;

        /*
         * Studying belongs to the card level: a session always runs over the cards
         * of one subcategory. On a learning area there is nothing to study, so the
         * button is not there at all.
         *
         * With no card to study it keeps its place, greyed out, and a tooltip says
         * why.
         */
        elements.learnButton.hidden = isArea;
        elements.learnButton.disabled = !canStudy;
        elements.learnButton.title = canStudy ? '' : t('learn.noCards');
        elements.learnButton.setAttribute('data-i18n-title', canStudy ? '' : 'learn.noCards');
        elements.learnButton.dataset.level = level;
        elements.learnButton.dataset.categoryId = String(categoryId);

        elements.learnLabel.textContent = t('cards.learn');
        elements.learnLabel.setAttribute('data-i18n', 'cards.learn');
        elements.learnButton.setAttribute(
            'aria-label',
            t('cards.learnThis', { name: elements.detailHeading.textContent })
        );

        elements.addEntryButton.hidden = hasEntries !== true;
        elements.addEntryButton.textContent = t(isArea ? 'cards.addSubcategoryShort' : 'cards.addCardShort');
        elements.addEntryButton.setAttribute('data-i18n', isArea ? 'cards.addSubcategoryShort' : 'cards.addCardShort');
        elements.addEntryButton.dataset.level = level;
        elements.addEntryButton.dataset.categoryId = String(categoryId);

        /*
         * Importing is a card-list action: a file of cards belongs to a
         * subcategory, which is the level that owns cards. A learning area only
         * holds subcategories, so the button stays away there.
         */
        elements.importButton.hidden = isArea;
        elements.importButton.dataset.level = level;
        elements.importButton.textContent = t('cards.import');
        elements.importButton.setAttribute('data-i18n', 'cards.import');
        elements.importButton.dataset.categoryId = String(categoryId);
    }

    function wireHeadActions() {
        elements.learnButton.addEventListener('click', function () {
            /* The button only exists in the card view, so it always starts the
               session over the cards of that subcategory. */
            startLearning('all', Number(elements.learnButton.dataset.categoryId), null);
        });

        elements.importButton.addEventListener('click', function () {
            openImportDialog(Number(elements.importButton.dataset.categoryId));
        });

        elements.addEntryButton.addEventListener('click', function () {
            var level = elements.addEntryButton.dataset.level;
            var categoryId = Number(elements.addEntryButton.dataset.categoryId);

            if (level === 'area') {
                openCategoryForm('create', null, categoryId);
                return;
            }

            openCardForm(null, categoryId);
        });
    }

    wireHeadActions();

    /*
     * The chosen language first, then the first answer - and both before the
     * first view is built. The view then finds the data in the store instead of
     * loading the same thing twice, and the answer is in the language of the
     * person looking at it.
     */
    locale = storedLocale();
    loadBootstrap();

    init();
    wireNavigation();

    /*
     * Tell the loading screen in index.php that the first answer is in and the
     * first view has been built from it. Two frames later, so what it fades away
     * from is a drawn view and not a half built one. If this never happens - a
     * failed request, for example - the overlay keeps its own safety net.
     */
    loadBootstrap().then(function () {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                document.dispatchEvent(new CustomEvent('lernkartei:ready'));
            });
        });
    });

    /*
     * No render() of its own here: the first view is built by init(), and it waits
     * for this answer anyway (see fetchCategories), so it is drawn exactly once -
     * with the data from the store. Drawing it a second time would only fetch the
     * icons and the drawings again, which is what the store is there to avoid.
     */
})();
