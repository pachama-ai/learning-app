/*
    Die Nachschlageseite "Energie verstehen".

    Diese Datei enthält KEINEN sichtbaren Text: jeder Satz steht in
    src/helpers/translations.php, jeder Fachtext in src/data/energy_modules.php. Beide
    kommen als JSON mit der Seite und werden hier nur noch an die richtige Stelle gesetzt.

    Was sie tut:
      - sie baut aus den Daten je ein Wissensmodul (Überschrift, Erklärung, Zeichnung,
        Formeln, Verweise)
      - sie sucht und filtert über alle Module, ohne die Seite neu zu laden
      - sie schaltet Sprache und Erscheinungsbild um, mit denselben gespeicherten Werten
        wie die Lernkartei
      - sie lädt eine Zeichnung erst, wenn ihr Modul in Sicht kommt, und hält sie an,
        wenn es wieder verschwindet

    Was sie NICHT tut: sie spricht nicht mit der Datenbank. Das Einzige, was sie vom
    Server holt, ist der Anmeldezustand für die Kopfzeile (api/auth.php).
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

    /* ----------------------------------------------------------------------
       Der Zustand der Seite
       ---------------------------------------------------------------------- */

    var state = {
        locale: config.defaultLocale,
        group: 'all',
        topic: 'all',
        formulasOnly: false
    };

    var modules = Array.isArray(config.modules) ? config.modules : [];
    var records = [];

    /* Die eine Frage nach reduzierter Bewegung. Sie wird oft gestellt, deshalb einmal
       gemerkt - der Wert ändert sich während einer Sitzung nicht. */
    var reducedMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function prefersReducedMotion() {
        return reducedMotion === true;
    }

    /* ----------------------------------------------------------------------
       Texte

       Alles Sichtbare geht durch diese zwei Funktionen: t() für die Oberfläche und
       text() für einen zweisprachigen Wert aus den Daten.
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

    function text(value) {
        if (typeof value === 'string') {
            return value;
        }

        if (value && typeof value === 'object') {
            return typeof value[state.locale] === 'string' ? value[state.locale] : (value[config.defaultLocale] || '');
        }

        return '';
    }

    /*
        Eine zweisprachige LISTE, zum Beispiel die Absätze der Vertiefung.

        Sie braucht einen eigenen Helfer, weil die Zahl der Absätze je Sprache
        verschieden sein darf: beim Sprachwechsel wird die Vertiefung deshalb neu
        aufgebaut und nicht Stück für Stück überschrieben.
    */
    function localizedList(value) {
        var list = null;

        if (Array.isArray(value)) {
            return value;
        }

        if (value && typeof value === 'object') {
            list = Array.isArray(value[state.locale]) ? value[state.locale] : value[config.defaultLocale];
        }

        return Array.isArray(list) ? list : [];
    }

    /* ----------------------------------------------------------------------
       Ein Element bauen
       ---------------------------------------------------------------------- */

    function el(tag, className, content) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (typeof content === 'string') {
            element.textContent = content;
        }

        return element;
    }

    /* ----------------------------------------------------------------------
       Speicher, so wie die Lernkartei ihn benutzt: dieselben Schlüssel, dieselbe
       Vorsicht. Der private Modus kann localStorage sperren.
       ---------------------------------------------------------------------- */

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
            /* Ohne Speicher gilt die Wahl eben nur für diese Seite. */
        }
    }

    function readSession(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function writeSession(key, value) {
        try {
            window.sessionStorage.setItem(key, value);
        } catch (error) {
            /* siehe oben */
        }
    }

    /* ----------------------------------------------------------------------
       Erscheinungsbild

       Dasselbe Verhalten wie in der Lernkartei: setzen, merken, und beim Umschalten die
       Ansicht vom Knopf aus aufdecken - wenn Bewegung erwünscht und möglich ist.
       ---------------------------------------------------------------------- */

    var themeToggle = document.getElementById('theme-toggle');

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
        /* Das Lesen eines Layoutwerts startet die Animation bei einem erneuten Klick neu. */
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
                writeStorage(config.storageKeys.theme, theme);
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

    /* ----------------------------------------------------------------------
       Sprache
       ---------------------------------------------------------------------- */

    var localeButtons = document.querySelectorAll('[data-locale]');
    var langUnderline = document.getElementById('lang-underline');

    function supportedLocale(candidate) {
        return config.supportedLocales.indexOf(candidate) !== -1 ? candidate : config.defaultLocale;
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

    /* Jedes Element, das einen Text aus den Übersetzungen trägt: data-i18n setzt den
       Inhalt, data-i18n-label und data-i18n-placeholder die Attribute. Dieselbe Regel
       benutzt die Lernkartei. */
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

    function applyLocale(next, persist) {
        state.locale = supportedLocale(next);
        document.documentElement.setAttribute('lang', state.locale);

        if (persist) {
            writeStorage(config.storageKeys.language, state.locale);
        }

        translateStaticText();
        updateLanguageButtons();
        updateThemeControl();
        updateFilterLabels();
        applyFilter();
        relabelModules();
    }

    /* ----------------------------------------------------------------------
       Die Filterleiste

       Zwei Auswahlfelder, die voneinander abhängen: erst der Themenbereich, dann das
       konkrete Thema daraus. Dazu "Nur Formeln" und "Zurücksetzen".

       Alles kommt aus den Daten: die Themenbereiche aus config.groups, die Themen aus
       den Modulen selbst. Ein neues Modul erscheint damit von allein in der Liste.
       ---------------------------------------------------------------------- */

    var countNode = document.getElementById('energy-count');
    var emptyNode = document.getElementById('energy-empty');
    var listNode = document.getElementById('energy-modules');
    var groupSelect = document.getElementById('energy-group');
    var topicSelect = document.getElementById('energy-topic');
    var formulasButton = document.getElementById('energy-formulas');
    var resetButton = document.getElementById('energy-reset');

    /*
        Die Module eines Themenbereichs, in der Reihenfolge der Daten.

        "all" heißt alle - so gibt es nur EINEN Weg durch die Module, und die beiden
        Auswahlfelder können nichts anderes tun als das, was hier steht.
    */
    function modulesOfGroup(group) {
        return records.filter(function (record) {
            return group === 'all' || record.module.group === group;
        });
    }

    /* Eine Option für ein Auswahlfeld. */
    function selectOption(value, label) {
        var option = document.createElement('option');

        option.value = value;
        option.textContent = label;

        return option;
    }

    /*
        Das erste Auswahlfeld: "Alle Themen" und dann jeder Themenbereich, zu dem es
        wirklich ein Modul gibt. Die Liste kommt aus den Daten und nicht aus dem Markup.
    */
    function buildGroupOptions() {
        if (groupSelect === null) {
            return;
        }

        groupSelect.textContent = '';
        groupSelect.appendChild(selectOption('all', t('energy.filter.allGroups')));

        (config.groups || []).forEach(function (group) {
            groupSelect.appendChild(selectOption(group, t('energy.group.' + group)));
        });

        groupSelect.value = state.group;
    }

    /*
        Das zweite Auswahlfeld: erst "Alle anzeigen", dann die Themen.

        Ist "Alle Themen" gewählt, stehen ALLE Module darin - nach Themenbereich
        gruppiert (optgroup), damit die Liste auch bei vierzig Modulen übersichtlich
        bleibt. Sonst nur die Themen des gewählten Bereichs, in der Reihenfolge der
        Daten.

        Die Beschriftung ist die kurze Themenbezeichnung aus dem Modul (Feld "topic")
        und nicht seine lange Überschrift: in einem Auswahlfeld zählt die Kürze.
    */
    function buildTopicOptions() {
        if (topicSelect === null) {
            return;
        }

        topicSelect.textContent = '';
        topicSelect.appendChild(selectOption('all', t('energy.filter.allTopics')));

        if (state.group === 'all') {
            (config.groups || []).forEach(function (group) {
                var list = modulesOfGroup(group);

                if (list.length === 0) {
                    return;
                }

                var box = document.createElement('optgroup');

                box.label = t('energy.group.' + group);

                list.forEach(function (record) {
                    box.appendChild(selectOption(record.module.id, text(record.module.topic)));
                });

                topicSelect.appendChild(box);
            });
        } else {
            modulesOfGroup(state.group).forEach(function (record) {
                topicSelect.appendChild(selectOption(record.module.id, text(record.module.topic)));
            });
        }

        /* Die bisherige Wahl bleibt stehen, wenn es sie in diesem Bereich noch gibt. */
        topicSelect.value = topicStillExists() ? state.topic : 'all';
        state.topic = topicSelect.value;
    }

    /* Gibt es das gewählte Thema in dem Bereich, der gerade offen ist? */
    function topicStillExists() {
        if (state.topic === 'all') {
            return true;
        }

        return modulesOfGroup(state.group).some(function (record) {
            return record.module.id === state.topic;
        });
    }

    /*
        Setzt Sichtbarkeit, Zähler und den leeren Zustand.

        Gefiltert wird über die Module, die schon im Browser liegen: kein Aufruf an den
        Server, kein Neuladen.
    */
    function applyFilter() {
        var visible = 0;

        records.forEach(function (record) {
            var pass = state.group === 'all' || record.module.group === state.group;

            if (pass && state.topic !== 'all') {
                pass = record.module.id === state.topic;
            }

            if (pass && state.formulasOnly && (record.module.formulas || []).length === 0) {
                pass = false;
            }

            record.article.hidden = !pass;

            if (pass) {
                visible += 1;
            }
        });

        if (countNode !== null) {
            countNode.textContent = visible === 1
                ? t('energy.results.one')
                : t('energy.results.other', { count: visible });
        }

        if (emptyNode !== null) {
            emptyNode.hidden = visible > 0;
        }

        if (listNode !== null) {
            listNode.hidden = visible === 0;
        }

        if (formulasButton !== null) {
            formulasButton.setAttribute('aria-pressed', state.formulasOnly ? 'true' : 'false');
        }

        saveFilters();
    }

    /*
        Beim Sprachwechsel werden beide Listen neu gebaut - die Beschriftungen kommen aus
        den Übersetzungen. Die gewählten Werte bleiben dabei stehen: state führt sie, und
        buildTopicOptions() setzt sie wieder.
    */
    function updateFilterLabels() {
        buildGroupOptions();
        buildTopicOptions();
    }

    function saveFilters() {
        writeSession(config.storageKeys.filters, JSON.stringify({
            group: state.group,
            topic: state.topic,
            formulasOnly: state.formulasOnly
        }));
    }

    /*
        Die Filter überleben das Neuladen der Seite. Der Sinn: der Reiter wird neben den
        Karteikarten offen gelassen, und ein Neuladen soll nicht bedeuten, alles neu
        einstellen zu müssen.
    */
    function restoreFilters() {
        var saved = readSession(config.storageKeys.filters);

        if (typeof saved !== 'string') {
            return;
        }

        try {
            var parsed = JSON.parse(saved);

            state.group = typeof parsed.group === 'string' ? parsed.group : 'all';
            state.topic = typeof parsed.topic === 'string' ? parsed.topic : 'all';
            state.formulasOnly = parsed.formulasOnly === true;
        } catch (error) {
            /* Ein kaputter Eintrag ist kein Grund, nicht zu starten. */
        }

        /* Ein gespeicherter Bereich, den es nicht mehr gibt, wird fallen gelassen. */
        if (state.group !== 'all' && (config.groups || []).indexOf(state.group) === -1) {
            state.group = 'all';
            state.topic = 'all';
        }
    }

    function resetFilters() {
        state.group = 'all';
        state.topic = 'all';
        state.formulasOnly = false;

        updateFilterLabels();
        applyFilter();
    }

    /* ----------------------------------------------------------------------
       Ein Wissensmodul
       ---------------------------------------------------------------------- */

    /* Ein zweisprachiger Wert an genau einem Platz. Die Funktion setzt nichts selbst;
       sie wird von track() sofort ausgeführt und für den Sprachwechsel gemerkt. */
    function valueApplier(node, value, property) {
        return function () {
            node[property || 'textContent'] = text(value);
        };
    }

    /*
        Eine Formel: der Satz, die Formelzeichen, die Bedingung und ein Zahlenbeispiel.

        Der Satz selbst (latex) ist sprachunabhängig und wird EINMAL gesetzt - beim
        Sprachwechsel bleibt er stehen, es wird also nicht neu gesetzt. Nur die Wörter
        ringsum wechseln.
    */
    function renderFormula(formula, track) {
        var root = el('div', 'energy-formula');
        var math = el('div', 'energy-formula__math');

        if (formula.latex) {
            if (window.katex && typeof window.katex.render === 'function') {
                try {
                    window.katex.render(formula.latex, math, config.katex);
                } catch (error) {
                    /* Der Satz der Formel ist lesbar, wenn der Formelsatz scheitert. */
                    math.textContent = formula.latex;
                }
            } else {
                math.textContent = formula.latex;
            }
        }

        root.appendChild(math);

        var variables = el('div', 'energy-formula__vars');

        (formula.variables || []).forEach(function (variable) {
            var meaning = el('span', 'energy-formula__meaning');

            track(valueApplier(meaning, variable.meaning));

            variables.appendChild(el('span', 'energy-formula__symbol', variable.symbol));
            variables.appendChild(meaning);
            variables.appendChild(el('span', 'energy-formula__unit', variable.unit));
        });

        root.appendChild(variables);

        /* Eine Zeile der Form einer Beschriftung, gefolgt vom Text. Die Beschriftung
           kommt aus den Übersetzungen, der Text aus dem Modul. */
        function note(labelKey, value) {
            var paragraph = el('p', 'energy-formula__note');
            var label = el('span', 'energy-formula__note-label');
            var body = el('span');

            track(function () {
                label.textContent = t(labelKey);
                body.textContent = text(value);
            });

            paragraph.appendChild(label);
            /* Das Leerzeichen ist ein eigener Knoten und keine Zierde: ohne es liest ein
               Vorleseprogramm "Gilt, wenndieselbe Formel" als ein Wort. */
            paragraph.appendChild(document.createTextNode(' '));
            paragraph.appendChild(body);

            return paragraph;
        }

        /* Die Bedingung steht bei jeder Formel: ohne sie wird eine Formel falsch
           verstanden, und genau das passiert beim Nachschlagen am häufigsten. */
        if (formula.valid) {
            root.appendChild(note('energy.formula.valid', formula.valid));
        }

        if (formula.example) {
            root.appendChild(note('energy.formula.example', formula.example));
        }

        return root;
    }

    function renderModule(module) {
        var applyLocales = [];
        var article = el('article', 'mod');

        article.id = module.id;

        /* Jede Zuweisung an ein sichtbares Wort läuft hier durch: sie wird sofort
           ausgeführt und für den Sprachwechsel gemerkt. */
        function track(apply) {
            applyLocales.push(apply);
            apply();

            return apply;
        }

        var groupLabel = el('p', 'mod__group');
        var title = el('h2', 'mod__title');
        var summary = el('p', 'mod__summary');

        /* Die Themenbeschriftung kommt aus den Übersetzungen, nicht aus den Daten. */
        track(function () {
            groupLabel.textContent = t('energy.group.' + module.group);
        });

        track(valueApplier(title, module.title));
        track(valueApplier(summary, module.summary));

        article.appendChild(groupLabel);
        article.appendChild(title);
        article.appendChild(summary);

        var body = el('div', 'mod__body');
        var visualBox = el('div', 'energy-visual');
        var side = el('div', 'mod__side');

        body.appendChild(visualBox);
        body.appendChild(side);
        article.appendChild(body);

        if ((module.formulas || []).length > 0) {
            var formulas = el('div', 'energy-formulas');
            var formulasTitle = el('h3', 'mod__side-title');

            track(function () {
                formulasTitle.textContent = t('energy.module.formulas');
            });

            formulas.appendChild(formulasTitle);

            module.formulas.forEach(function (formula) {
                formulas.appendChild(renderFormula(formula, track));
            });

            side.appendChild(formulas);
        }

        /* Die Vertiefung: zugeklappt, damit die Übersicht ruhig bleibt, und aufklappbar,
           ohne dass etwas nachgeladen wird. */
        var more = el('button', 'mod__more');
        var detail = el('div', 'mod__detail');

        more.type = 'button';
        more.setAttribute('aria-expanded', 'false');
        more.setAttribute('aria-controls', module.id + '-detail');
        detail.id = module.id + '-detail';
        detail.hidden = true;

        function paintMore() {
            var open = detail.hidden === false;

            more.textContent = t(open ? 'energy.module.less' : 'energy.module.more');
            /* Die gesprochene Beschriftung nennt das Modul - "Mehr" allein wäre unter
               vielen Modulen nicht zu unterscheiden - und wechselt mit dem Zustand. */
            more.setAttribute('aria-label', t(open ? 'energy.module.lessFor' : 'energy.module.moreFor', { name: text(module.title) }));
        }

        /* Die Vertiefung entsteht bei jedem Sprachwechsel neu. Sie ist zugeklappt, damit
           die Übersicht ruhig bleibt, und aufklappbar, ohne dass etwas nachgeladen wird. */
        function paintDetail() {
            detail.textContent = '';

            localizedList(module.detail).forEach(function (paragraph) {
                detail.appendChild(el('p', null, text(paragraph)));
            });
        }

        track(paintDetail);

        more.addEventListener('click', function () {
            detail.hidden = detail.hidden === false;
            more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true');
            paintMore();
        });

        paintMore();
        side.appendChild(more);
        side.appendChild(detail);

        if ((module.related || []).length > 0) {
            var related = el('div', 'mod__related');
            var relatedTitle = el('span', 'mod__side-title');

            track(function () {
                relatedTitle.textContent = t('energy.module.related');
            });

            related.appendChild(relatedTitle);

            module.related.forEach(function (id) {
                var link = el('button', 'mod__related-link');
                var target = null;

                modules.forEach(function (candidate) {
                    if (candidate.id === id) {
                        target = candidate;
                    }
                });

                if (target === null) {
                    return;
                }

                link.type = 'button';
                track(valueApplier(link, target.title));

                link.addEventListener('click', function () {
                    goToModule(id);
                });

                related.appendChild(link);
            });

            side.appendChild(related);
        }

        var record = {
            article: article,
            visualBox: visualBox,
            module: module,
            visible: false,
            visual: null,
            paint: function () {
                applyLocales.forEach(function (apply) {
                    apply();
                });

                paintMore();

                if (record.visual) {
                    record.visual.relabel(state.locale);
                }
            }
        };

        return record;
    }

    /* ----------------------------------------------------------------------
       Die Zeichnungen: erst bauen, wenn sie gebraucht werden
       ---------------------------------------------------------------------- */

    var visuals = window.LernkarteiVisuals;

    function initVisual(record) {
        if (record.visual !== null || !visuals || !visuals.has(record.module.visual.kind)) {
            return;
        }

        var context = {
            locale: state.locale,
            t: t,
            text: text,
            prefersReducedMotion: prefersReducedMotion
        };

        record.visual = visuals.create(record.module.visual.kind, record.visualBox, record.module.visual, context);

        if (record.visual) {
            record.visual.setInView(record.visible);
        }
    }

    /*
        Eine Zeichnung entsteht, wenn ihr Modul in Sicht kommt, und wird angehalten, wenn
        es wieder verschwindet. Der Rand von 120 Pixeln sorgt dafür, dass sie schon fertig
        ist, wenn man sie wirklich sieht.
    */
    function watchVisibility() {
        if (typeof window.IntersectionObserver !== 'function') {
            records.forEach(function (record) {
                record.visible = true;
                initVisual(record);
            });

            return;
        }

        var observer = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var record = null;

                records.forEach(function (candidate) {
                    if (candidate.article === entry.target) {
                        record = candidate;
                    }
                });

                if (record === null) {
                    return;
                }

                record.visible = entry.isIntersecting;

                if (entry.isIntersecting) {
                    initVisual(record);
                }

                if (record.visual) {
                    record.visual.setInView(entry.isIntersecting);
                }
            });
        }, { rootMargin: '120px 0px' });

        records.forEach(function (record) {
            observer.observe(record.article);
            record.observer = observer;
        });
    }

    /* ----------------------------------------------------------------------
       Zu einem Modul springen

       Die Adresse trägt die Id des Moduls (#ohms_law). Damit lässt sich ein einzelnes
       Modul verlinken - und später kann eine Karteikarte genau darauf zeigen.
       ---------------------------------------------------------------------- */

    function moduleById(id) {
        var found = null;

        records.forEach(function (record) {
            if (record.module.id === id) {
                found = record;
            }
        });

        return found;
    }

    function goToModule(id) {
        var record = moduleById(id);

        if (record === null) {
            return;
        }

        /* Ein verlinktes Modul muss sichtbar sein: Filter, die es ausschließen, werden
           aufgehoben. Sonst führte ein Verweis auf eine leere Stelle. */
        if (record.article.hidden) {
            resetFilters();
        }

        if (window.location.hash !== '#' + id) {
            window.location.hash = id;
        }

        record.article.scrollIntoView({
            block: 'start',
            behavior: prefersReducedMotion() ? 'auto' : 'smooth'
        });
    }

    function followHash() {
        var id = window.location.hash.replace('#', '');

        if (id !== '') {
            goToModule(id);
        }
    }

    /* ----------------------------------------------------------------------
       Das Konto in der Kopfzeile

       Die Lernkartei hat dafür einen ganzen Dialog. Hier steht nur, wer angemeldet ist,
       und der Weg hinaus. Wer sich anmelden will, geht über die Lernkartei - dort liegt
       der Anmeldedialog, und ein zweiter wäre ein zweiter Ort, an dem dasselbe passiert.
       ---------------------------------------------------------------------- */

    var accountSlot = document.getElementById('account-slot');
    var csrfToken = '';
    var accountUser = null;

    function renderAccountSlot() {
        if (accountSlot === null) {
            return;
        }

        accountSlot.textContent = '';

        if (accountUser === null) {
            var link = el('a', 'account__link', t('auth.signIn'));

            link.href = config.pages.cards;
            accountSlot.appendChild(link);

            return;
        }

        var person = el('div', 'account__person');
        var name = el('span', 'account__name', accountUser.name);
        var dot = el('span', 'account__dot', '\u00b7');
        var out = el('button', 'account__link', t('auth.signOut'));

        dot.setAttribute('aria-hidden', 'true');
        out.type = 'button';
        out.addEventListener('click', signOut);

        person.appendChild(name);
        person.appendChild(dot);
        person.appendChild(out);
        accountSlot.appendChild(person);
    }

    function loadAccount() {
        if (accountSlot === null) {
            return;
        }

        window.fetch(config.endpoints.auth, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json();
            })
            .then(function (body) {
                if (body && body.success === true && body.data) {
                    accountUser = body.data.user ? body.data.user : null;
                    csrfToken = String(body.data.csrf_token || '');
                }

                renderAccountSlot();
            })
            .catch(function () {
                /* Ohne Antwort bleibt es bei der Einladung, sich anzumelden. */
                renderAccountSlot();
            });
    }

    function signOut() {
        window.fetch(config.endpoints.auth, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action: 'sign_out', csrf_token: csrfToken })
        }).then(function () {
            accountUser = null;
            renderAccountSlot();
        }).catch(function () {
            renderAccountSlot();
        });
    }

    /* ----------------------------------------------------------------------
       Bedienung anschließen
       ---------------------------------------------------------------------- */

    function wireFilters() {
        /*
            Ein Wechsel des Themenbereichs stellt das zweite Feld neu auf. Ein Thema aus
            einem anderen Bereich gibt es nicht mehr, deshalb fällt die bisherige Wahl
            dabei auf "Alle anzeigen" zurück.
        */
        if (groupSelect !== null) {
            groupSelect.addEventListener('change', function () {
                state.group = groupSelect.value;
                state.topic = 'all';

                buildTopicOptions();
                applyFilter();
            });
        }

        if (topicSelect !== null) {
            topicSelect.addEventListener('change', function () {
                state.topic = topicSelect.value;
                applyFilter();
            });
        }

        if (formulasButton !== null) {
            formulasButton.addEventListener('click', function () {
                state.formulasOnly = !state.formulasOnly;
                applyFilter();
            });
        }

        if (resetButton !== null) {
            resetButton.addEventListener('click', function () {
                resetFilters();
            });
        }

        window.addEventListener('hashchange', followHash);
    }

    function wireHeader() {
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

        /*
            Die Seite steht oft in einem zweiten Fenster neben der Lernkartei. Wer dort
            an- oder abmeldet, soll es hier sehen, ohne neu zu laden - deshalb wird der
            Anmeldezustand beim Zurückkommen in dieses Fenster neu erfragt.
        */
        window.addEventListener('focus', loadAccount);
    }

    function relabelModules() {
        records.forEach(function (record) {
            record.paint();
        });
    }

    /* ----------------------------------------------------------------------
       Start
       ---------------------------------------------------------------------- */

    function init() {
        if (listNode === null) {
            return;
        }

        modules.forEach(function (module) {
            var record = renderModule(module);

            records.push(record);
            listNode.appendChild(record.article);
        });

        restoreFilters();

        wireFilters();
        wireHeader();

        /* Die beiden Listen entstehen aus den Daten, bevor irgendetwas gefiltert wird. */
        updateFilterLabels();

        /* Die gespeicherte Sprache steht vor der ersten Anzeige fest: sonst stünde für
           einen Augenblick Englisch da und würde dann umspringen. */
        applyLocale(readStorage(config.storageKeys.language) || config.defaultLocale, false);
        watchVisibility();
        updateLanguageButtons();
        followHash();
        loadAccount();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
