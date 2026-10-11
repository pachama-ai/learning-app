/*
    „Story Together" - die gemeinsame Geschichte.

    Diese Datei enthält KEINEN sichtbaren Text: jeder Satz steht in
    src/helpers/translations.php (Oberfläche) oder kommt aus api/story.php (Titel,
    Beiträge, Namen der Autoren als Schlüssel). Sie zeichnet die Seite aus einer einzigen
    Antwort und schickt einen Beitrag zurück.

    Was sie NICHT tut: sie entscheidet nicht, wer schreiben darf. Sie zeigt nur, was der
    Server gesagt hat. Ob ein Beitrag wirklich gespeichert wird, entscheidet api/story.php
    noch einmal für sich - ein POST lässt sich jederzeit von Hand abschicken.

    Alles, was jemand geschrieben hat, geht über textContent in die Seite und niemals über
    innerHTML. Ein Beitrag ist Text und kein Markup, und daran ändert auch ein spitze
    Klammer-Zeichen nichts.
*/

(function () {
    'use strict';

    var configNode = document.getElementById('app-config');

    if (configNode === null) {
        return;
    }

    var config;

    try {
        config = JSON.parse(configNode.textContent);
    } catch (error) {
        return;
    }

    var state = {
        locale: config.defaultLocale,
        data: null,
        csrfToken: '',
        publishing: false,
        /* Die Id des Beitrags, der gerade geändert wird. null heißt: ein neuer Beitrag. */
        editingId: null
    };

    var reducedMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function prefersReducedMotion() {
        return reducedMotion === true;
    }

    var nodes = {
        title: document.getElementById('story-title'),
        story: document.getElementById('story'),
        scroll: document.getElementById('story-scroll'),
        body: document.getElementById('story-body'),
        empty: document.getElementById('story-empty'),
        write: document.getElementById('story-write'),
        writeTitle: document.getElementById('story-write-title'),
        box: document.getElementById('story-box'),
        status: document.getElementById('story-status'),
        field: document.getElementById('story-field'),
        count: document.getElementById('story-count'),
        limit: document.getElementById('story-limit'),
        publish: document.getElementById('story-publish'),
        cancel: document.getElementById('story-cancel'),
        stats: document.getElementById('story-stats'),
        figures: document.getElementById('story-figures'),
        share: document.getElementById('story-share'),
        since: document.getElementById('story-since'),
        loading: document.getElementById('story-loading'),
        error: document.getElementById('story-error'),
        note: document.getElementById('story-note'),
        challenge: document.getElementById('story-challenge'),
        challengeTopic: document.getElementById('story-challenge-topic'),
        challengeLevel: document.getElementById('story-challenge-level')
    };

    /* ----------------------------------------------------------------------
       Texte
       ---------------------------------------------------------------------- */

    function t(key, values) {
        var table = config.translations[state.locale] || config.translations[config.defaultLocale] || {};
        var fallback = config.translations[config.defaultLocale] || {};
        var value = typeof table[key] === 'string' ? table[key] : fallback[key];

        if (typeof value !== 'string') {
            return key;
        }

        Object.keys(values || {}).forEach(function (name) {
            value = value.replace('{' + name + '}', values[name]);
        });

        return value;
    }

    /* Einzahl oder Mehrzahl - dieselbe Regel wie überall in der Anwendung. */
    function plural(base, count) {
        return count === 1 ? t(base + '.one') : t(base + '.other', { count: count });
    }

    /* Der Name eines Autors. Was im Text steht, steht in den Übersetzungen. */
    function authorName(key) {
        return typeof key === 'string' && key !== '' ? t('story.author.' + key) : t('story.author.unknown');
    }

    function el(tag, className, text) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (typeof text === 'string') {
            element.textContent = text;
        }

        return element;
    }

    /* ----------------------------------------------------------------------
       Sprache und Erscheinungsbild

       Dieselben gespeicherten Werte wie in der Lernkartei und auf der
       Nachschlageseite: die Wahl gilt in der ganzen Anwendung.
       ---------------------------------------------------------------------- */

    var localeButtons = document.querySelectorAll('[data-locale]');
    var langUnderline = document.getElementById('lang-underline');
    var themeToggle = document.getElementById('theme-toggle');

    function translateStaticText() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n]'), function (element) {
            element.textContent = t(element.getAttribute('data-i18n'));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-label]'), function (element) {
            element.setAttribute('aria-label', t(element.getAttribute('data-i18n-label')));
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-i18n-placeholder]'), function (element) {
            element.setAttribute('placeholder', t(element.getAttribute('data-i18n-placeholder')));
        });
    }

    function updateLanguageButtons() {
        var active = null;

        Array.prototype.forEach.call(localeButtons, function (button) {
            var isActive = button.getAttribute('data-locale') === state.locale;

            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');

            if (isActive) {
                active = button;
            }
        });

        if (langUnderline !== null && active !== null) {
            langUnderline.style.width = active.offsetWidth + 'px';
            langUnderline.style.transform = 'translateX(' + active.offsetLeft + 'px)';
        }
    }

    function updateThemeControl() {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';

        if (themeToggle === null) {
            return;
        }

        themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        themeToggle.setAttribute('aria-label', t(isDark ? 'theme.switch.toLight' : 'theme.switch.toDark'));
    }

    function rotateThemeIcon() {
        if (themeToggle === null) {
            return;
        }

        themeToggle.classList.remove('is-rotating');
        void themeToggle.offsetWidth;
        themeToggle.classList.add('is-rotating');

        window.setTimeout(function () {
            themeToggle.classList.remove('is-rotating');
        }, 500);
    }

    function applyTheme(next, persist) {
        var theme = next === 'dark' ? 'dark' : 'light';
        var root = document.documentElement;

        function commit() {
            root.setAttribute('data-theme', theme);
            updateThemeControl();

            if (persist) {
                try {
                    window.localStorage.setItem(config.storageKeys.theme, theme);
                } catch (error) {
                    /* Der private Modus kann localStorage sperren. */
                }
            }
        }

        if (themeToggle === null
            || prefersReducedMotion()
            || typeof document.startViewTransition !== 'function') {
            commit();
            rotateThemeIcon();

            return;
        }

        var rect = themeToggle.getBoundingClientRect();

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

    function applyLocale(next, persist) {
        var supported = config.supportedLocales.indexOf(next) !== -1;

        state.locale = supported ? next : config.defaultLocale;
        document.documentElement.setAttribute('lang', state.locale);

        if (persist) {
            try {
                window.localStorage.setItem(config.storageKeys.language, state.locale);
            } catch (error) {
                /* siehe oben */
            }
        }

        translateStaticText();
        updateLanguageButtons();
        updateThemeControl();

        /* Die ganze Seite neu zeichnen: die Zahlen, die Beiträge und der Zustand tragen
           alle Wörter, die sich mit der Sprache ändern. Der eingetippte Text im Feld
           bleibt dabei stehen - er ist ein Wert und keine Übersetzung. */
        render();
        updateCounter();

        /* Brotkrumen und Seitenleiste tragen ebenfalls Wörter: die Titel und die Namen der
           Lernbereiche. Sie werden deshalb mit neu gebaut - die Liste der Bereiche gleich
           in der neuen Sprache. */
        renderCrumb();
        renderSidebar();
    }

    /* ----------------------------------------------------------------------
       Kleine Helfer für den Text
       ---------------------------------------------------------------------- */

    /*
        Absätze. Getrennt wird an Leerzeilen; ein einzelner Zeilenumbruch innerhalb eines
        Absatzes bleibt stehen und wird von der Gestaltung erhalten (white-space:
        pre-line). Genau so, wie es eingetippt wurde.
    */
    function paragraphs(content) {
        return String(content)
            .replace(/\r\n?/g, '\n')
            .split(/\n\s*\n/)
            .map(function (block) {
                return block.trim();
            })
            .filter(function (block) {
                return block !== '';
            });
    }

    /* Dieselbe Aufräum-Regel wie story_normalise_content() in story_service.php. */
    function normalise(content) {
        return String(content).replace(/\r\n?/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
    }

    /*
        Wörter zählen - dieselbe Regel wie story_count_words() im Service.

        Gezählt werden die Stücke zwischen den Leerzeichen, die mindestens einen
        Buchstaben oder eine Ziffer enthalten. Die Anzeige beim Tippen und die
        gespeicherte Zahl müssen übereinstimmen, sonst stimmt eine von beiden nicht.
    */
    function countWords(content) {
        var tokens = String(content).trim().split(/\s+/).filter(function (token) {
            return token.length > 0;
        });
        var count = 0;

        tokens.forEach(function (token) {
            if (/[\p{L}\p{N}]/u.test(token)) {
                count++;
            }
        });

        return count;
    }

    /* Ein Datum in der Sprache der Seite. Der Tag kommt als 'YYYY-MM-DD' aus der
       Datenbank - das ist der Kalendertag in Europe/Berlin und nicht die Uhrzeit des
       Servers. */
    function formatDay(day) {
        var moment = new Date(String(day) + 'T00:00:00Z');

        if (isNaN(moment.getTime())) {
            return String(day);
        }

        return moment.toLocaleDateString(state.locale === 'de' ? 'de-DE' : 'en-US', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC'
        });
    }

    function formatShortDay(day) {
        var moment = new Date(String(day) + 'T00:00:00Z');

        if (isNaN(moment.getTime())) {
            return String(day);
        }

        return moment.toLocaleDateString(state.locale === 'de' ? 'de-DE' : 'en-US', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            timeZone: 'UTC'
        });
    }

    /*
       Eine Uhrzeit in der Sprache der Seite - für die Bearbeitungsfrist.

       Der Zeitpunkt kommt als ISO-Zeichenkette MIT Versatz aus der API ('...+02:00'). So
       liest ihn new Date(...) eindeutig, und der Browser formatiert ihn in der Zeitzone
       des Lesers; hier wird also nichts verschoben. Ist der Wert leer oder unlesbar,
       kommt eine leere Zeichenkette zurück und der Satz bleibt weg.
    */
    function formatTime(moment) {
        var at = new Date(String(moment));

        if (isNaN(at.getTime())) {
            return '';
        }

        return at.toLocaleTimeString(state.locale === 'de' ? 'de-DE' : 'en-US', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /* ----------------------------------------------------------------------
       Die Seite zeichnen
       ---------------------------------------------------------------------- */

    function showNote(message) {
        if (nodes.note === null) {
            return;
        }

        nodes.note.textContent = message;
        nodes.note.hidden = message === '';
    }

    function render() {
        if (state.data === null) {
            return;
        }

        if (nodes.loading !== null) {
            nodes.loading.hidden = true;
        }

        if (nodes.error !== null) {
            nodes.error.hidden = true;
        }

        if (nodes.title !== null) {
            nodes.title.textContent = state.data.story.title;
        }

        document.title = state.data.story.title + ' \u00b7 ' + t('app.title');

        renderStory();
        renderWrite();
        renderChallenge();
        renderStatistics();
    }

    /*
        Die drei Zahlen unter dem Titel.

        Sie standen hier einmal - Beiträge, Wörter und der letzte Beitrag. Sie sind weg:
        dieselben Zahlen stehen unten in der Statistik, und oben soll die Geschichte
        anfangen. `plural()` und `formatDay()` bleiben, sie werden anderswo gebraucht.
    */

    /* Die Geschichte, Beitrag für Beitrag. */
    function renderStory() {
        if (nodes.body === null) {
            return;
        }

        var contributions = state.data.contributions;
        var lastIndex = contributions.length - 1;

        /* Wo der Lesebereich steht, BEVOR er neu gebaut wird - für den Fall, dass gelesen
           und nicht geschrieben wird. */
        var previousTop = nodes.scroll === null ? 0 : nodes.scroll.scrollTop;

        nodes.body.textContent = '';

        contributions.forEach(function (item, index) {
            var article = el('article', 'entry');

            article.setAttribute('data-writer', typeof item.writer === 'string' ? item.writer : 'unknown');
            article.id = 'contribution-' + item.id;

            var meta = el('p', 'entry__meta');
            var time = el('time', null, formatDay(item.date));

            time.setAttribute('datetime', item.date);

            meta.appendChild(el('span', 'entry__who', authorName(item.writer)));
            meta.appendChild(el('span', 'entry__dot', '\u00b7'));
            meta.appendChild(time);

            if (index === lastIndex) {
                meta.appendChild(el('span', 'entry__dot', '\u00b7'));
                meta.appendChild(el('span', 'entry__latest', t('story.entry.latest')));
            }

            var text = el('div', 'entry__text');

            paragraphs(item.content).forEach(function (block) {
                text.appendChild(el('p', null, block));
            });

            /*
             * Der eigene, zuletzt veröffentlichte Beitrag darf noch geändert werden -
             * solange die andere Person nicht geschrieben hat. Welcher das ist, sagt der
             * Server (editable_contribution_id); hier wird nur der Knopf gezeichnet.
             */
            if (state.data.editable_contribution_id === item.id) {
                var edit = el('button', 'entry__edit', t('story.edit.button'));

                edit.type = 'button';
                edit.setAttribute('data-i18n', 'story.edit.button');
                edit.setAttribute('aria-label', t('story.edit.open'));
                edit.addEventListener('click', function () {
                    startEditing(item);
                });

                meta.appendChild(el('span', 'entry__dot', '\u00b7'));
                meta.appendChild(edit);
            }

            article.appendChild(meta);
            article.appendChild(text);

            nodes.body.appendChild(article);
        });

        if (nodes.empty !== null) {
            nodes.empty.hidden = contributions.length > 0;
        }

        /* Zurück an die Stelle, an der gelesen wurde - oder ans Ende, wenn es dort war. */
        if (nodes.scroll !== null) {
            nodes.scroll.scrollTop = stickToLatest ? nodes.scroll.scrollHeight : previousTop;
        }

        updateScrollFade();
    }

    /* ----------------------------------------------------------------------
       Der Lesebereich

       Die Geschichte steht in einem Bereich mit eigener Höhe und eigenem Scroll. Zwei
       kleine Helfer halten ihn in Ordnung: einer sagt, ob er unten steht, der andere
       blendet den Fade an den Rändern ein und aus.
       ---------------------------------------------------------------------- */

    /*
     * Steht der Leser beim neuesten Beitrag? Dann bleibt er dort - auch wenn nachträglich
     * noch etwas wächst (die Webschrift kommt später als das Skript) oder die Seite neu
     * gebaut wird. Beim Öffnen ist die Antwort ja: die Seite beginnt unten, beim Neuesten.
     */
    var stickToLatest = true;

    /* Steht der Lesebereich unten - oder passt der Text ohnehin ganz hinein? */
    function scrollIsAtBottom() {
        if (nodes.scroll === null) {
            return true;
        }

        return nodes.scroll.scrollTop + nodes.scroll.clientHeight >= nodes.scroll.scrollHeight - 4;
    }

    /* Ans Ende springen, wenn der Leser dort steht. */
    function scrollToLatest() {
        if (nodes.scroll !== null && stickToLatest) {
            nodes.scroll.scrollTop = nodes.scroll.scrollHeight;
        }
    }

    /*
     * Der Fade oben und unten. Er sitzt nur dort, wo wirklich noch Text ist: ganz oben
     * wäre ein ausgeblendeter Anfang ein Fehler, und ganz unten dasselbe. Deshalb zwei
     * Zustandsklassen statt eines festen Verlaufs.
     *
     * Nebenbei merkt sich diese Funktion, ob der Leser unten steht - sie läuft bei jedem
     * Scrollen der Seite.
     */
    function updateScrollFade() {
        if (nodes.scroll === null) {
            return;
        }

        var box = nodes.scroll;

        stickToLatest = scrollIsAtBottom();

        box.classList.toggle('is-fade-top', box.scrollTop > 4);
        box.classList.toggle(
            'is-fade-bottom',
            box.scrollTop + box.clientHeight < box.scrollHeight - 4
        );
    }

    /* Einen Beitrag aus der Antwort holen - er wird beim Ändern gebraucht. */
    function findContribution(id) {
        var list = state.data.contributions;

        for (var index = 0; index < list.length; index++) {
            if (list[index].id === id) {
                return list[index];
            }
        }

        return null;
    }

    /*
     * Einen geschriebenen Beitrag ändern.
     *
     * Der Text kommt in DENSELBEN Editor wie ein neuer Beitrag - ein zweites Eingabefeld
     * wäre ein zweiter Ort, an dem etwas schiefgehen kann. Was sich ändert, sind der
     * Titel, die Beschriftung des Knopfes und die Zeile darunter; der Beitrag selbst
     * verschwindet für die Dauer des Änderns aus der Geschichte (siehe renderWrite).
     *
     * Gefüllt wird das Feld mit dem vollständigen Text aus der Antwort der API - also
     * genau dem, was in der Datenbank steht, mit allen Absätzen und Zeilenumbrüchen. Ein
     * `value` ist reiner Text: er wird nie als HTML gelesen, deshalb kann hier auch nichts
     * eingeschleust werden, was aussehen würde wie Markup.
     */
    function startEditing(item) {
        if (nodes.field === null) {
            return;
        }

        state.editingId = item.id;
        nodes.field.value = String(item.content || '');

        renderWrite();
        updateCounter();
        nodes.field.focus();
    }

    function cancelEditing() {
        state.editingId = null;

        if (nodes.field !== null) {
            nodes.field.value = '';
        }

        renderWrite();
        updateCounter();
    }

    /* Der Schreibbereich: entweder der Editor oder ein Satz, wer an der Reihe ist. */
    function renderWrite() {
        if (nodes.write === null) {
            return;
        }

        var current = state.data.state;
        var editing = state.editingId !== null;

        nodes.write.hidden = false;

        /*
         * Der Beitrag, der gerade im Editor steht, verschwindet oben aus der Geschichte -
         * sonst stünde derselbe Text zweimal auf der Seite, einmal mit Initiale und einmal
         * im Feld. Immer erst alle wieder zeigen und dann den einen verstecken: so bleibt
         * nichts hängen, wenn der Modus wechselt oder die Sprache gewechselt wird (dann
         * entstehen die Beiträge neu und tragen die Klasse nicht mehr).
         */
        document.querySelectorAll('.entry.is-editing').forEach(function (entry) {
            entry.classList.remove('is-editing');
        });

        if (editing) {
            var edited = document.getElementById('contribution-' + state.editingId);

            if (edited !== null) {
                edited.classList.add('is-editing');
            }
        }

        if (nodes.status !== null) {
            /* Ist heute schon geschrieben, sagt die Zeile nichts. Dann verschwindet sie
               ganz, statt als leere Zeile mit ihrem Abstand stehen zu bleiben. */
            nodes.status.textContent = editing ? editingMessage() : turnMessage(current);
            nodes.status.hidden = nodes.status.textContent === '';
        }

        if (nodes.box === null) {
            return;
        }

        /*
         * Wer heute nicht dran ist, sieht den Editor ausgegraut und außer Betrieb - nicht
         * versteckt: dass dort geschrieben wird, soll man sehen. Ob wirklich gespeichert
         * werden darf, prüft weiterhin der Server (api/story.php).
         */
        var locked = current.can_write !== true && !editing;

        nodes.box.classList.toggle('is-disabled', locked);

        if (nodes.field !== null) {
            nodes.field.disabled = locked;
        }

        /*
         * Im Ruhezustand sieht die Fläche aus wie Text auf der Seite; Fläche, Rahmen,
         * Zähler und Knöpfe erscheinen erst, wenn jemand darin schreibt (updateEditorState).
         */
        updateEditorState();

        /* Titel und Knöpfe wechseln mit dem Modus. data-i18n wird dabei mitgesetzt, weil
           der Sprachumschalter alle [data-i18n]-Elemente neu beschreibt. */
        var titleKey = editing ? 'story.edit.title' : 'story.write.title';

        if (nodes.writeTitle !== null) {
            nodes.writeTitle.textContent = t(titleKey);
            nodes.writeTitle.setAttribute('data-i18n', titleKey);
        }

        if (nodes.publish !== null) {
            var publishKey = editing ? 'story.edit.save' : 'story.write.publish';

            nodes.publish.disabled = state.publishing === true;
            nodes.publish.textContent = t(publishKey);
            nodes.publish.setAttribute('data-i18n', publishKey);
        }

        if (nodes.cancel !== null) {
            nodes.cancel.hidden = !editing;
        }
    }

    /*
     * Ist der Editor gerade "offen"? Er ist es beim Ändern eines Beitrags, sonst sobald
     * das Feld den Fokus hat oder etwas darin steht. Nur die beiden letzten Fälle können
     * sich ändern, während die Seite steht - deshalb wird der Zustand an genau einer
     * Stelle ausgerechnet und nicht irgendwo umgeschaltet.
     */
    function editorIsActive() {
        if (state.editingId !== null) {
            return true;
        }

        if (nodes.field === null) {
            return false;
        }

        return document.activeElement === nodes.field || nodes.field.value.trim() !== '';
    }

    /* Den Zustand des Editors ans Blatt geben: alles Weitere macht story.css. */
    function updateEditorState() {
        if (nodes.box === null) {
            return;
        }

        nodes.box.classList.toggle('is-active', editorIsActive());
    }

    /* Was über dem Editor steht, während ein Beitrag geändert wird. */
    function editingMessage() {
        var contribution = findContribution(state.editingId);

        return contribution === null
            ? t('story.edit.status')
            : t('story.edit.status', { day: formatDay(contribution.date) });
    }

    /*
        Was in der Zeile über dem Editor steht.

        Zwei Fälle, und jeder sagt genau eine Sache:
          - du bist dran
          - heute ist die andere Person dran

        Heute schon geschrieben? Dann bleibt die Zeile leer und wird ausgeblendet: der
        Beitrag von heute steht direkt darüber, und ein Satz darüber wäre nur
        Wiederholung.

        `turn_writer` ist die Person von HEUTE - derselbe Wert, der auch auf der Kachel
        der Startseite steht.
    */
    function turnMessage(current) {
        if (current.can_write === true) {
            return t('story.turn.yours');
        }

        /*
         * Gerade geschrieben und die Bearbeitungszeit läuft: der Editor ist deshalb noch
         * gesperrt, und ohne diesen Satz wäre nicht zu sehen, warum. Fehlt die Uhrzeit
         * (oder ist sie unlesbar), bleibt die Zeile lieber leer als halb fertig.
         */
        if (current.blocked_reason === 'edit_window_open') {
            var deadline = formatTime(current.edit_deadline);

            return deadline === '' ? '' : t('story.turn.editWindow', { time: deadline });
        }

        return t('story.turn.other', { name: authorName(current.turn_writer) });
    }

    /* ----------------------------------------------------------------------
       Die Grammar Challenge

       Eine Zeile, keine Karte: links das Markenzeichen und die Beschriftung, rechts die
       Zeitform. Sie kommt mit der Antwort der API (Feld `challenge`) und steht über dem
       Editor - auch bei dem, der heute nicht mehr schreiben darf. Was in der Aufgabe
       steht, prüft niemand, und geprüft werden soll es auch nicht.

       Warum nur die Zeitform zu sehen ist: die Anweisung und der Beispielsatz sind eine
       Anregung, kein zweiter Text neben der Geschichte. Wer schreibt, weiß durch die
       Zeitform, worauf er achten soll - mehr braucht die Leiste nicht.
       ---------------------------------------------------------------------- */

    function renderChallenge() {
        if (nodes.challenge === null) {
            return;
        }

        var task = state.data.challenge;

        if (!task) {
            /* Gibt es keine Aufgaben, bleibt der Block versteckt statt als leerer Balken. */
            nodes.challenge.hidden = true;

            return;
        }

        nodes.challenge.hidden = false;

        /* Der Themenname ist ein Fachbegriff und heißt in beiden Sprachen gleich. */
        nodes.challengeTopic.textContent = String(task.topic || '');

        /*
         * "B1" - eine Angabe des Niveaus, kein übersetzbarer Satz. Sie steht in derselben
         * Schrift wie die Zeitform und verschwindet, wenn die Aufgabe keins mitbringt. Der
         * Punkt davor wird hier mitgebaut statt aus dem Blatt zu kommen: so kann er
         * Vorleseprogrammen verschwiegen werden - vorgelesen "Mittelpunkt B1" wäre Unfug.
         */
        var level = String(task.level || '');

        nodes.challengeLevel.textContent = '';

        if (level !== '') {
            var dot = el('span', 'challenge__dot', '\u00b7');

            dot.setAttribute('aria-hidden', 'true');
            nodes.challengeLevel.appendChild(dot);
            nodes.challengeLevel.appendChild(document.createTextNode(level));
        }
    }

    /* Die Zahlen. */
    function renderStatistics() {
        if (nodes.stats === null || nodes.figures === null) {
            return;
        }

        var contributions = state.data.contributions;

        if (contributions.length === 0) {
            nodes.stats.hidden = true;

            return;
        }

        nodes.stats.hidden = false;

        var words = 0;
        var perAuthor = { xenia: 0, selina: 0, unknown: 0 };
        var days = {};

        contributions.forEach(function (item) {
            var key = typeof item.writer === 'string' ? item.writer : 'unknown';

            words += item.words;
            perAuthor[key] = (perAuthor[key] || 0) + item.words;
            days[item.date] = item;
        });

        var writtenDays = Object.keys(days).length;

        nodes.figures.textContent = '';

        /*
         * Die drei Zahlen als Text, mit einem Punkt dazwischen - so, wie man sie
         * ausspricht: "408 Wörter · 1 Beitrag · 1 Tag". Die Punktzeichen baut das Skript
         * als eigene Elemente und blendet sie für Vorleseprogramme aus: sie sind
         * Trennzeichen, keine Wörter, und haben in keiner Sprache etwas zu suchen.
         */
        [
            plural('story.meta.words', words),
            plural('story.stats.contributions', contributions.length),
            plural('story.stats.days', writtenDays)
        ].forEach(function (label, index) {
            if (index > 0) {
                var dot = el('span', 'story-line__dot', '\u00b7');

                dot.setAttribute('aria-hidden', 'true');
                nodes.figures.appendChild(dot);
            }

            nodes.figures.appendChild(el('span', 'story-line__figure', label));
        });

        renderShare(perAuthor, words);

        /* "seit 11.10.2026" - der Tag, an dem die Geschichte begonnen hat. Vorne im Text
           steht er nicht; er ist die einzige Angabe, die die Zeitleiste noch hat. */
        if (nodes.since !== null) {
            var firstDay = Object.keys(days).sort()[0];

            nodes.since.textContent = typeof firstDay === 'string'
                ? t('story.stats.since', { day: formatShortDay(firstDay) })
                : '';
        }
    }

    /*
     * Wie viel jede Person geschrieben hat: ein schmaler Balken mit zwei Anteilen und
     * daneben die Legende.
     *
     * Der Balken allein sagt Vorleseprogrammen nichts - die Legende daneben sagt dasselbe
     * in Worten, deshalb ist der Balken selbst `aria-hidden`. Der zweite Anteil bekommt
     * den Rest bis 100: zwei gerundete Zahlen ergäben sonst 99 oder 101 Prozent.
     */
    function renderShare(perAuthor, words) {
        if (nodes.share === null) {
            return;
        }

        nodes.share.textContent = '';

        var bar = el('span', 'story-line__bar');
        var legend = el('span', 'story-line__legend');
        var shares = [];

        bar.setAttribute('aria-hidden', 'true');

        ['xenia', 'selina'].forEach(function (key) {
            shares.push(words > 0 ? Math.round((perAuthor[key] / words) * 100) : 0);
        });

        shares[1] = Math.max(0, 100 - shares[0]);

        ['xenia', 'selina'].forEach(function (key, index) {
            var part = el('span', 'story-line__part');

            part.setAttribute('data-writer', key);
            part.style.width = shares[index] + '%';
            bar.appendChild(part);
        });

        nodes.share.appendChild(bar);

        ['xenia', 'selina'].forEach(function (key, index) {
            if (index > 0) {
                var dot = el('span', 'story-line__dot', '\u00b7');

                dot.setAttribute('aria-hidden', 'true');
                legend.appendChild(dot);
            }

            var item = el('span', 'story-line__item');

            item.setAttribute('data-writer', key);
            item.appendChild(el('span', 'story-line__swatch'));
            item.appendChild(el('span', 'story-line__name', authorName(key)));
            item.appendChild(el('span', 'story-line__value', shares[index] + ' %'));
            legend.appendChild(item);
        });

        nodes.share.appendChild(legend);
    }

    /* ----------------------------------------------------------------------
       Der Wortzähler beim Tippen
       ---------------------------------------------------------------------- */

    function updateCounter() {
        if (nodes.field === null || nodes.count === null) {
            return;
        }

        var words = countWords(nodes.field.value);

        nodes.count.textContent = plural('story.meta.words', words);

        if (nodes.limit === null) {
            return;
        }

        var maximum = state.data !== null && state.data.limits
            ? Number(state.data.limits.maxContentLength)
            : 0;

        if (maximum > 0 && nodes.field.value.length > maximum) {
            nodes.limit.textContent = t('story.write.tooLong', { count: nodes.field.value.length - maximum });
            nodes.limit.hidden = false;
        } else {
            nodes.limit.hidden = true;
        }

        if (nodes.publish !== null) {
            nodes.publish.disabled = state.publishing === true || normalise(nodes.field.value) === '';
        }
    }

    /* ----------------------------------------------------------------------
       Veröffentlichen

       Ein Klick auf "Veröffentlichen" oder "Änderung speichern" speichert direkt. Den
       Zwischenschritt mit der Vorschau gab es einmal; er war eine zweite Seite vor der
       eigentlichen Handlung. Der Server prüft ohnehin alles noch einmal: Zug, ein Beitrag
       je Tag, Länge, CSRF-Token.
       ---------------------------------------------------------------------- */

    function publish() {
        if (state.publishing) {
            /* Ein zweiter Klick, während die erste Anfrage läuft, würde nur eine zweite
               Antwort erzeugen. Die Anfrage ist schon unterwegs. */
            return;
        }

        var text = normalise(nodes.field.value);

        if (text === '') {
            showNote(t('story.error.empty'));

            return;
        }

        state.publishing = true;
        renderWrite();
        updateCounter();

        /* Zwei Aktionen, ein Weg: beim Ändern fährt die Id des Beitrags mit, sonst wird
           ein neuer Beitrag veröffentlicht. */
        var payload = { content: text, csrf_token: state.csrfToken };

        if (state.editingId !== null) {
            payload.action = 'edit';
            payload.contribution_id = state.editingId;
        }

        window.fetch(config.endpoints.story, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                return { status: response.status, body: body };
            });
        }).then(function (result) {
            state.publishing = false;

            if (result.body && result.body.success === true && result.body.data) {
                /* Die Antwort ist der frische Stand: die Seite baut sich daraus neu auf
                   und braucht keinen zweiten Aufruf. */
                state.data = result.body.data;
                state.csrfToken = String(result.body.data.csrf_token || state.csrfToken);

                /* Nach dem Ändern ist der Editor wieder der für einen neuen Beitrag. */
                state.editingId = null;
                nodes.field.value = '';
                showNote('');
                render();
                updateCounter();

                return;
            }

            showNote(publishError(result.body));
            renderWrite();
            updateCounter();
        }).catch(function () {
            state.publishing = false;
            showNote(t('story.error.server'));
            renderWrite();
            updateCounter();
        });
    }

    /* Aus dem Code der API einen Satz machen. Das Wort steht in den Übersetzungen. */
    function publishError(body) {
        var code = body && body.error && typeof body.error.code === 'string' ? body.error.code : '';

        if (code === 'not_your_turn' || code === 'edit_window_open') {
            return t('story.error.turn');
        }

        if (code === 'empty_contribution') {
            return t('story.error.empty');
        }

        if (code === 'contribution_too_long' || code === 'invalid_content') {
            return t('story.error.tooLong');
        }

        if (code === 'invalid_token') {
            return t('story.error.token');
        }

        if (code === 'not_editable') {
            return t('story.error.notEditable');
        }

        if (code === 'story_forbidden') {
            return t('story.forbidden.hint');
        }

        return t('story.error.server');
    }

    /* ----------------------------------------------------------------------
       Laden
       ---------------------------------------------------------------------- */

    function load() {
        return window.fetch(config.endpoints.story, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json().then(function (body) {
                    return { status: response.status, body: body };
                });
            })
            .then(function (result) {
                if (result.body && result.body.success === true && result.body.data) {
                    state.data = result.body.data;
                    state.csrfToken = String(result.body.data.csrf_token || '');
                    render();
                    updateCounter();

                    return;
                }

                if (nodes.loading !== null) {
                    nodes.loading.hidden = true;
                }

                if (nodes.error !== null) {
                    nodes.error.hidden = false;
                }

                showNote(publishError(result.body));
            })
            .catch(function () {
                if (nodes.loading !== null) {
                    nodes.loading.hidden = true;
                }

                if (nodes.error !== null) {
                    nodes.error.hidden = false;
                }
            });
    }

    /* ----------------------------------------------------------------------
       Das Konto in der Kopfzeile
       ---------------------------------------------------------------------- */

    /*
     * Die Kopfzeile sieht auf jeder Seite gleich aus, also trägt auch diese Seite den
     * Kontobereich - und damit "Abmelden". Wer hier steht, soll nicht erst zur Lernkartei
     * zurückgehen müssen, um sich abzumelden.
     *
     * Das Kontofenster selbst (Name ändern, Passwort, Konto löschen) gibt es nur dort, wo
     * auch sein Markup steht: auf der Lernkartei. Ein Klick auf den Namen führt deshalb
     * dorthin statt in ein Fenster, das es hier nicht gibt.
     *
     * Warum die Frage nach dem Konto überhaupt gestellt wird, obwohl der Server weiß, wer
     * diese Seite sehen darf: das hier ist die Anzeige der Kopfzeile, kein Zugangsschutz.
     * Den prüft diese Seite nicht - das tut allein der Server.
     */
    var accountState = { user: null, csrfToken: '' };

    function renderAccount() {
        var slot = document.getElementById('account-slot');

        if (slot === null) {
            return;
        }

        slot.textContent = '';

        if (accountState.user === null) {
            var open = el('button', 'account__link');
            open.type = 'button';
            open.textContent = t('auth.signIn');
            open.setAttribute('data-i18n', 'auth.signIn');
            open.addEventListener('click', function () {
                window.location.href = 'index.php';
            });
            slot.appendChild(open);

            return;
        }

        var person = el('div', 'account__person');

        /* Der Name ist eine Angabe und kein Satz, deshalb ohne data-i18n: der
           Sprachumschalter muss ihn genau so lassen, wie er dasteht. */
        var name = el('button', 'account__name', String(accountState.user.name || ''));
        name.type = 'button';
        name.addEventListener('click', function () {
            window.location.href = 'index.php';
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
     * Abmelden: die Sitzung endet auf dem Server, dann geht es zurück zur Lernkartei.
     *
     * Der Schlüssel im Sitzungsspeicher ist derselbe wie in app.js ('lernkartei.note'):
     * die Startseite holt die Zeile dort ab und schreibt "Abgemeldet" hin. Zwei
     * verschiedene Schlüssel wären zwei Zeilen, von denen eine nie erscheint.
     */
    function signOut() {
        window.fetch(config.endpoints.auth, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action: 'sign_out', csrf_token: accountState.csrfToken })
        }).then(function (response) {
            return response.json();
        }).then(function (body) {
            if (body && body.success === true) {
                leaveToStartPage();

                return;
            }

            showNote(t('story.error.server'));
        }).catch(function () {
            showNote(t('story.error.server'));
        });
    }

    function leaveToStartPage() {
        try {
            window.sessionStorage.setItem('lernkartei.note', 'signedOut');
        } catch (error) {
            /* Ohne Speicher ist die Zeile einfach weg; daran scheitert nichts. */
        }

        window.location.href = 'index.php';
    }

    function loadAccount() {
        return window.fetch(config.endpoints.auth, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json();
            })
            .then(function (body) {
                if (body && body.success === true && body.data) {
                    accountState.user = body.data.user || null;
                    accountState.csrfToken = String(body.data.csrf_token || '');
                }

                renderAccount();
            })
            .catch(function () {
                /* Ohne Antwort bleibt der Bereich leer; die Geschichte selbst ist davon
                   nicht betroffen. */
            });
    }

    /* ----------------------------------------------------------------------
       Das Gerüst: Brotkrumen und Seitenleiste

       Beides gehört zur Seite und nicht zur Geschichte: der Brotkrumen steht in der
       Kopfzeile, die Seitenleiste links. Beide werden hier gebaut, weil story.php keine
       Kategorien kennt - die Lernbereiche kommen aus api/categories.php, derselben
       Abfrage, aus der die Startseite ihre Kacheln baut.
       ---------------------------------------------------------------------- */

    var sidebarNav = document.getElementById('sidebar-nav');
    var crumb = document.getElementById('crumb');

    /* Der Weg oben links: "Start — Story Together", in derselben Form wie überall. */
    function renderCrumb() {
        if (crumb === null) {
            return;
        }

        crumb.textContent = '';

        var home = el('a', null, t('crumb.start'));

        home.href = 'index.php';
        home.setAttribute('data-i18n', 'crumb.start');
        crumb.appendChild(home);

        var separator = el('span', null, ' \u2014 ');

        separator.setAttribute('aria-hidden', 'true');
        crumb.appendChild(separator);
        crumb.appendChild(el('span', null, t('story.title')));
    }

    /* Der Name eines Lernbereichs in der Sprache der Seite. */
    function areaName(area) {
        var translated = area['name_' + state.locale];

        if (typeof translated === 'string' && translated !== '') {
            return translated;
        }

        return typeof area.name === 'string' ? area.name : '';
    }

    /* Ein Eintrag der Seitenleiste - dieselben Klassen und dasselbe Ziel wie auf jeder
       anderen Seite. */
    function buildSidebarLink(name, href, colour, isCurrent) {
        var link = el('a', 'sidebar__link', name);

        link.href = href;
        link.style.setProperty('--link-color', colour);

        if (isCurrent) {
            link.setAttribute('aria-current', 'page');
        }

        return link;
    }

    /*
     * Die Seitenleiste "Themengebiete": die Geschichte oben und als der offene Eintrag,
     * darunter die Lernbereiche. Sie bekommt damit denselben ersten Platz, den ihre Kachel
     * auf der Startseite hat; die Lernbereiche rücken in der Farbreihe um eine Stelle nach
     * hinten und tragen so dieselben Farben wie dort.
     *
     * Die Nummer der laufenden Abfrage ist der Grund, warum sie beim Sprachwechsel nicht
     * doppelt wird: der Wechsel ruft diese Funktion erneut auf, während die erste Antwort
     * unterwegs ist. Nur die jüngste darf ihre Liste anhängen - die ältere Antwort ist
     * dann in der falschen Sprache und gehört weg.
     */
    var sidebarRun = 0;

    function renderSidebar() {
        if (sidebarNav === null) {
            return;
        }

        sidebarRun += 1;

        var run = sidebarRun;

        sidebarNav.textContent = '';
        sidebarNav.appendChild(buildSidebarLink(t('story.title'), config.story.href, 'var(--palette-1)', true));

        window.fetch(config.endpoints.categories + '?language=' + encodeURIComponent(state.locale), {
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json();
        }).then(function (body) {
            if (run !== sidebarRun) {
                return;
            }

            var areas = body && body.success === true && Array.isArray(body.data) ? body.data : [];

            areas.forEach(function (area, index) {
                sidebarNav.appendChild(buildSidebarLink(
                    areaName(area),
                    'index.php?category=' + encodeURIComponent(area.id),
                    'var(--palette-' + (((index + 1) % 8) + 1) + ')',
                    false
                ));
            });
        }).catch(function () {
            /* Ohne Antwort bleibt die Geschichte als einziger Eintrag stehen. */
        });
    }

    /* ----------------------------------------------------------------------
       Bedienung anschließen
       ---------------------------------------------------------------------- */

    function wire() {
        if (themeToggle !== null) {
            themeToggle.addEventListener('click', function () {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';

                applyTheme(isDark ? 'light' : 'dark', true);
            });
        }

        Array.prototype.forEach.call(localeButtons, function (button) {
            button.addEventListener('click', function () {
                applyLocale(button.getAttribute('data-locale'), true);
            });
        });

        window.addEventListener('resize', updateLanguageButtons);

        if (nodes.field !== null) {
            /* Der Kasten ist erst "aktiv", wenn jemand darin schreibt: dann wird aus der
               stillen Fläche ein Feld mit Rahmen, Zähler und Knöpfen. */
            nodes.field.addEventListener('input', function () {
                updateCounter();
                updateEditorState();
            });
            nodes.field.addEventListener('focus', updateEditorState);
            nodes.field.addEventListener('blur', updateEditorState);

            /* Steuerung und Eingabetaste: die Steuerung ist ein Absatz, wie im Text.
               Die Eingabetaste allein veröffentlicht nichts - eine Geschichte schreibt
               man nicht mit einem Tastendruck; mit der Steuerung ist es Absicht. */
            nodes.field.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();
                    publish();
                }
            });
        }

        if (nodes.scroll !== null) {
            nodes.scroll.addEventListener('scroll', updateScrollFade);
        }

        /* Beim Größenwechsel ändert sich, wie viel in den Lesebereich passt - der Fade
           muss nachziehen, und wer unten steht, bleibt unten. */
        window.addEventListener('resize', function () {
            scrollToLatest();
            updateScrollFade();
        });

        /* Die Webschrift kommt später als dieses Skript. Danach ist der Text ein paar
           Pixel höher - wer unten steht, rutscht mit. */
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                scrollToLatest();
                updateScrollFade();
            });
        }

        if (nodes.publish !== null) {
            nodes.publish.addEventListener('click', publish);
        }

        if (nodes.cancel !== null) {
            nodes.cancel.addEventListener('click', cancelEditing);
        }
    }

    /* ----------------------------------------------------------------------
       Start

       Ist dieses Konto kein Schreiber, hat PHP die Geschichte gar nicht erst gebaut -
       dann gibt es hier nichts zu tun. Die Kopfzeile (Sprache, Erscheinungsbild) bedient
       dieses Skript trotzdem.
       ---------------------------------------------------------------------- */

    function init() {
        wire();
        loadAccount();
        renderCrumb();

        if (config.viewer === null || config.viewer.key === null || nodes.body === null) {
            applyLocale(storedLocale(), false);
            updateLanguageButtons();

            return;
        }

        /* Die Seitenleiste gibt es nur auf der Seite selbst - wem sie nicht offensteht,
           bekommt sie auch nicht. */
        renderSidebar();
        applyLocale(storedLocale(), false);
        updateLanguageButtons();
        load();
    }

    function storedLocale() {
        var saved = null;

        try {
            saved = window.localStorage.getItem(config.storageKeys.language);
        } catch (error) {
            /* Der private Modus kann localStorage sperren. */
        }

        return typeof saved === 'string' ? saved : config.defaultLocale;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
