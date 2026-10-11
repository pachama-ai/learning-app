/*
    Die Zeichnungen der Nachschlageseite "Energie verstehen".

    Diese Datei ENTHÄLT KEINEN sichtbaren Text. Jedes Wort, das in einer Zeichnung steht,
    kommt aus dem Modul (src/data/energy_modules.php, Feld "labels") oder aus den
    Übersetzungen der Oberfläche (Feld "ctx.t"). Deshalb kann ein neues Modul allein mit
    Daten entstehen.

    Jede Zeichnung ist eine Funktion, die

        root    den Platz füllt, den die Seite ihr gibt
        spec    den Abschnitt "visual" des Moduls liest (labels, controls, fixed, animated)
        ctx     Sprache, Übersetzen, Zahlen und die Frage nach reduzierter Bewegung stellt

    und ein kleines Objekt zurückgibt:

        relabel(locale)   die Wörter in der Zeichnung auf die neue Sprache setzen
        setInView(bool)   sagen, ob die Zeichnung gerade sichtbar ist
        reset()           alles auf den Anfangswert zurückstellen

    Wer seinen Text selbst baut - der Modell-Hinweis und die Knöpfe - meldet sich mit
    ctx.onRelabel() an. Diese Meldungen laufen beim Sprachwechsel hinter relabel() her,
    deshalb muss keine Zeichnung daran denken.

    Bewegung
    --------
    Alle Zeichnungen teilen sich EINEN Taktgeber. Einer je Zeichnung wäre verschwenderisch,
    und sechs gleichzeitig laufende Schleifen halten den Rechner unnötig wach. Der
    Taktgeber läuft nur, solange wirklich etwas läuft: eine Zeichnung, die nicht sichtbar
    ist (der Reiter ist zu oder das Modul ist aus dem Bild gescrollt), wird nicht gerechnet.

    Jede Zeichnung, die Elektronen zeigt, ist ein MODELL. Das steht als Hinweis unter der
    Zeichnung und kommt aus dem Modul - die Punkte wandern absichtlich langsam, damit
    sichtbar bleibt, dass die Energie nicht mit den Elektronen wandert.
*/

(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    /* ----------------------------------------------------------------------
       Kleine Werkzeuge
       ---------------------------------------------------------------------- */

    /* Ein Element im HTML-Baum. */
    function node(tag, className, text) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (typeof text === 'string') {
            element.textContent = text;
        }

        return element;
    }

    /* Ein Element in einer Zeichnung. Attribute kommen als Objekt, weil eine Zeichnung
       fast nur aus Attributen besteht und setAttribute-Zeilen sonst alles verdecken. */
    function shape(tag, attributes, parent) {
        var element = document.createElementNS(SVG_NS, tag);

        Object.keys(attributes || {}).forEach(function (name) {
            element.setAttribute(name, String(attributes[name]));
        });

        if (parent) {
            parent.appendChild(element);
        }

        return element;
    }

    /* Runden: vermeidet 0.30000000000000004 in der Zeichnung, wo es niemand sieht. */
    function round(value, digits) {
        var factor = Math.pow(10, digits || 0);

        return Math.round(value * factor) / factor;
    }

    /*
        Eine Zahl in der Sprache der Seite: Deutsch schreibt 1,5 und Englisch 1.5.
        Ohne das stünde in der deutschen Ansicht ein englisches Komma in jedem Wert.
    */
    function number(value, digits, locale) {
        return Number(value).toLocaleString(locale === 'de' ? 'de-DE' : 'en-US', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    }

    /*
        Eine sehr kleine Zahl in der Schreibweise mit Zehnerpotenz, zum Beispiel
        4,81 · 10⁻¹⁹.

        Die Hochzahlen sind Zeichen und keine Formatierung: "10⁻¹⁹" ist in jeder Sprache
        dasselbe. Ohne das stünde für die Ladung eines Elektrons
        0,00000000000000000016 da.
    */
    var SUPERSCRIPT = {
        '-': '\u207b', '0': '\u2070', '1': '\u00b9', '2': '\u00b2', '3': '\u00b3',
        '4': '\u2074', '5': '\u2075', '6': '\u2076', '7': '\u2077', '8': '\u2078', '9': '\u2079'
    };

    function scientific(value, digits, locale) {
        if (value === 0) {
            return '0';
        }

        var exponent = Math.floor(Math.log10(Math.abs(value)));
        var mantissa = value / Math.pow(10, exponent);
        var power = String(exponent).split('').map(function (character) {
            return SUPERSCRIPT[character] || character;
        }).join('');

        return number(mantissa, digits, locale) + ' \u00b7 10' + power;
    }

    /* Wie viele Nachkommastellen ein Regler sinnvoll zeigt: er richtet sich nach seiner
       Schrittweite, damit der Wert nie genauer aussieht, als er sein kann. */
    function digitsForStep(step) {
        if (step >= 1) {
            return 0;
        }

        return step >= 0.1 ? 1 : 2;
    }

    /* ----------------------------------------------------------------------
       Der eine Taktgeber für alle Zeichnungen
       ---------------------------------------------------------------------- */

    var ticker = (function () {
        var items = [];
        var frameId = null;
        var last = 0;

        function canRun() {
            if (document.hidden) {
                return false;
            }

            return items.some(function (item) {
                return item.play && item.inView;
            });
        }

        function loop(now) {
            /* Ein zu großer Sprung (der Reiter war zu) würde alles springen lassen -
               deshalb ist ein Schritt auf 50 ms begrenzt. */
            var delta = Math.min(0.05, Math.max(0, (now - last) / 1000));

            last = now;

            items.forEach(function (item) {
                if (item.play && item.inView) {
                    item.step(delta);
                }
            });

            frameId = canRun() ? window.requestAnimationFrame(loop) : null;
        }

        /* Startet die Schleife, wenn es etwas zu tun gibt, und stoppt sie sonst. */
        function refresh() {
            if (canRun() && frameId === null) {
                last = window.performance.now();
                frameId = window.requestAnimationFrame(loop);

                return;
            }

            if (!canRun() && frameId !== null) {
                window.cancelAnimationFrame(frameId);
                frameId = null;
            }
        }

        document.addEventListener('visibilitychange', refresh);

        return {
            add: function (item) {
                items.push(item);
            },
            refresh: refresh
        };
    })();

    /* ----------------------------------------------------------------------
       Die Registrierung der Bausteine
       ---------------------------------------------------------------------- */

    var factories = {};

    function register(kind, factory) {
        factories[kind] = factory;
    }

    /* ----------------------------------------------------------------------
       Was jede Zeichnung gleich braucht
       ---------------------------------------------------------------------- */

    /*
        Die Regler unter der Zeichnung. Sie entstehen aus den Daten des Moduls, deshalb
        braucht eine neue Zeichnung hier nichts Eigenes.
    */
    /*
        Die Regler unter der Zeichnung.

        Zwei Arten sind möglich, und beide stehen im Modul: ein Schieberegler (kind
        fehlt oder ist "range") und ein Auswahlfeld (kind "select"). Ein Auswahlfeld
        braucht statt min/max/step eine Liste von Möglichkeiten:

            ['id' => 'pair', 'kind' => 'select', 'value' => 'unlike',
             'options' => [['value' => 'unlike', 'label' => [...]], ...]]

        Der Wert eines Schiebereglers ist eine Zahl, der eines Auswahlfelds eine
        Zeichenkette. values[] trägt also beides.
    */
    function buildControls(spec, ctx, onChange) {
        var controls = spec.controls || [];
        var root = node('div', 'energy-controls');
        var values = {};
        var entries = {};

        controls.forEach(function (control) {
            var isSelect = control.kind === 'select';
            var digits = isSelect ? 0 : digitsForStep(control.step);
            var wrap = node('div', 'energy-control');
            var head = node('div', 'energy-control__head');
            var label = node('span', 'energy-control__label', ctx.text(control.label));
            var readout = node('span', 'energy-control__value');
            var input = isSelect ? node('select') : node('input');

            wrap.setAttribute('data-control', control.id);
            input.setAttribute('aria-label', ctx.text(control.label));

            if (isSelect) {
                input.className = 'energy-control__select';

                (control.options || []).forEach(function (option) {
                    var choice = node('option', null, ctx.text(option.label));

                    choice.value = String(option.value);
                    input.appendChild(choice);
                });

                /* Ein Auswahlfeld zeigt seinen Wert selbst; rechts daneben bleibt es leer. */
                readout.hidden = true;
                values[control.id] = String(control.value);
                input.value = String(control.value);

                input.addEventListener('change', function () {
                    values[control.id] = input.value;
                    onChange(control.id, input.value);
                });
            } else {
                input.type = 'range';
                input.min = String(control.min);
                input.max = String(control.max);
                input.step = String(control.step);
                input.value = String(control.value);
                values[control.id] = Number(control.value);

                input.addEventListener('input', function () {
                    values[control.id] = Number(input.value);
                    onChange(control.id, Number(input.value));
                });
            }

            head.appendChild(label);
            head.appendChild(readout);
            wrap.appendChild(head);
            wrap.appendChild(input);
            root.appendChild(wrap);

            entries[control.id] = {
                node: readout,
                label: label,
                control: control,
                element: input,
                digits: digits,
                select: isSelect
            };
        });

        function sync(locale) {
            Object.keys(entries).forEach(function (id) {
                if (entries[id].select) {
                    return;
                }

                entries[id].node.textContent = number(values[id], entries[id].digits, locale)
                    + ' ' + entries[id].control.unit;
            });
        }

        function fieldOf(id) {
            return root.querySelector('[data-control="' + id + '"] input, [data-control="' + id + '"] select');
        }

        return {
            root: root,
            values: values,
            sync: sync,
            relabel: function (locale) {
                Object.keys(entries).forEach(function (id) {
                    var entry = entries[id];

                    entry.label.textContent = ctx.text(entry.control.label);

                    /* Die Möglichkeiten eines Auswahlfelds tragen ihre Beschriftung aus
                       den Daten - beim Sprachwechsel ziehen sie mit. */
                    if (entry.select) {
                        Array.prototype.forEach.call(entry.element.options, function (option, index) {
                            var source = entry.control.options[index];

                            if (source) {
                                option.textContent = ctx.text(source.label);
                            }
                        });
                    }
                });

                sync(locale);
            },
            /* Alle Regler und Auswahlfelder zurück auf ihren Startwert. */
            reset: function () {
                controls.forEach(function (control) {
                    var field = fieldOf(control.id);

                    values[control.id] = control.kind === 'select'
                        ? String(control.value)
                        : Number(control.value);

                    if (field) {
                        field.value = String(control.value);
                    }
                });
            }
        };
    }

    /*
        Die Modellannahme unter der Zeichnung. Jede Zeichnung dieser Seite zeigt ein
        Modell, deshalb steht sie überall - der Text kommt aus dem Modul.

        Der Hinweis entsteht hier und nicht im Modul-Markup, deshalb meldet er sich über
        ctx.onRelabel() für den Sprachwechsel an: sonst bliebe er in der Sprache stehen,
        in der die Zeichnung zufällig gebaut wurde.
    */
    function buildCaution(spec, ctx) {
        if (!spec.caution) {
            return null;
        }

        var note = node('p', 'energy-visual__caution');

        function paint() {
            note.textContent = ctx.t('energy.visual.model') + ': ' + ctx.text(spec.caution);
        }

        ctx.onRelabel(paint);
        paint();

        return note;
    }

    /*
        Abspielen, Pause und Zurücksetzen.

        Abspielen und Pause sind EIN Knopf: er zeigt, was er als Nächstes tut. Damit ist
        immer klar, was gerade läuft, und eine stehende Zeichnung hat genau einen Weg
        zurück in Bewegung.
    */
    function buildButtons(ctx, playing, onToggle, onReset) {
        var root = node('div', 'energy-buttons');
        var toggle = node('button', 'energy-button');
        var reset = node('button', 'energy-button');
        /* Der zuletzt gezeigte Zustand. Der Sprachwechsel braucht ihn, denn "Abspielen"
           und "Pause" sind derselbe Knopf. */
        var current = playing;

        toggle.type = 'button';
        reset.type = 'button';

        function paint(state) {
            current = state;
            toggle.textContent = ctx.t(state ? 'energy.visual.pause' : 'energy.visual.play');
            toggle.setAttribute('aria-pressed', state ? 'true' : 'false');
        }

        toggle.addEventListener('click', onToggle);
        reset.addEventListener('click', onReset);

        root.appendChild(toggle);
        root.appendChild(reset);
        paint(playing);

        ctx.onRelabel(function () {
            reset.textContent = ctx.t('energy.visual.reset');
            paint(current);
        });

        return { root: root, paint: paint };
    }

    /* Die Fläche, in der gemalt wird. Die Maße sind fest; die Zeichnung skaliert über
       die viewBox mit der Spalte mit und braucht kein eigenes Nachrechnen. */
    function buildFrame(width, height, label) {
        var frame = node('figure', 'energy-visual__frame');
        var canvas = shape('svg', {
            viewBox: '0 0 ' + width + ' ' + height,
            role: 'img',
            preserveAspectRatio: 'xMidYMid meet',
            'aria-label': label
        }, frame);

        return { frame: frame, canvas: canvas };
    }

    /*
        Ein Schaubild: Raster, zwei Achsen und die Zahlen an den Teilstrichen.

        Es gibt die beiden Umrechnungen von Wert auf Pixel zurück, damit eine Zeichnung
        nur noch ihre Kurven und Punkte setzen muss. Die Achsenzahlen entstehen hier und
        werden von relabel() in der Sprache der Seite neu geschrieben - Deutsch schreibt
        "1,5" und Englisch "1.5".

        Die Fläche im Fenster (0…60 ms) kann negativ sein (Wechselspannung); dann liegt
        die waagerechte Achse in der Mitte und wird als Linie bei null gezeichnet.
    */
    function buildPlot(canvas, options) {
        var left = options.left;
        var right = options.right;
        var top = options.top;
        var bottom = options.bottom;
        var xMin = typeof options.xMin === 'number' ? options.xMin : 0;
        var yMin = typeof options.yMin === 'number' ? options.yMin : 0;
        var xMax = options.xMax;
        var yMax = options.yMax;
        var ticks = options.ticks || 5;
        var xDigits = typeof options.xDigits === 'number' ? options.xDigits : 0;
        var yDigits = typeof options.yDigits === 'number' ? options.yDigits : 0;
        var xMarks = [];
        var yMarks = [];
        var index;

        function xOf(value) {
            return left + ((value - xMin) / (xMax - xMin)) * (right - left);
        }

        function yOf(value) {
            return bottom - ((value - yMin) / (yMax - yMin)) * (bottom - top);
        }

        for (index = 0; index <= ticks; index += 1) {
            var xValue = xMin + (index / ticks) * (xMax - xMin);
            var yValue = yMin + (index / ticks) * (yMax - yMin);

            shape('line', {
                x1: round(xOf(xValue), 1), y1: top,
                x2: round(xOf(xValue), 1), y2: bottom,
                stroke: 'var(--line)', 'stroke-width': 1
            }, canvas);

            shape('line', {
                x1: left, y1: round(yOf(yValue), 1),
                x2: right, y2: round(yOf(yValue), 1),
                stroke: 'var(--line)', 'stroke-width': 1
            }, canvas);

            xMarks.push({
                value: xValue,
                node: shape('text', {
                    x: round(xOf(xValue), 1), y: bottom + 18,
                    'text-anchor': 'middle', class: 'viz-caption'
                }, canvas)
            });

            yMarks.push({
                value: yValue,
                node: shape('text', {
                    x: left - 8, y: round(yOf(yValue), 1) + 4,
                    'text-anchor': 'end', class: 'viz-caption'
                }, canvas)
            });
        }

        /* Die waagerechte Achse liegt bei null, wenn es negative Werte gibt - sonst am
           unteren Rand. */
        var zeroY = yMin < 0 ? round(yOf(0), 1) : bottom;

        shape('line', { x1: left, y1: zeroY, x2: right, y2: zeroY, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: left, y1: bottom, x2: left, y2: top, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);

        function paint(locale) {
            xMarks.forEach(function (mark) {
                mark.node.textContent = number(mark.value, xDigits, locale);
            });

            yMarks.forEach(function (mark) {
                mark.node.textContent = number(mark.value, yDigits, locale);
            });
        }

        paint(options.locale);

        return {
            xOf: xOf,
            yOf: yOf,
            left: left,
            right: right,
            top: top,
            bottom: bottom,
            xMax: xMax,
            yMax: yMax,
            relabel: paint
        };
    }

    /* ----------------------------------------------------------------------
       1. Ladung und Elektronen

       Ein Metallkörper mit sechs freien Elektronen. Der Regler verschiebt ihre Anzahl
       gegenüber dem neutralen Zustand:
         - mehr als sechs: die zusätzlichen kommen sichtbar dazu
         - weniger als sechs: die fehlenden bleiben als leerer Ring stehen
       Genau das ist der Kern der Ladung: positiv heißt "es fehlt ein Elektron".
       ---------------------------------------------------------------------- */
    register('charge_model', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 250, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var bodyX = 165;
        var bodyY = 70;
        var bodyW = 190;
        var bodyH = 110;
        var slots = [];
        var extras = [];
        var index;

        shape('rect', {
            x: bodyX,
            y: bodyY,
            width: bodyW,
            height: bodyH,
            rx: 10,
            fill: 'var(--viz-plane)',
            stroke: 'var(--line-strong)',
            'stroke-width': 1.5
        }, canvas);

        /* Sechs feste Plätze für die freien Elektronen: zwei Reihen zu drei. */
        for (var row = 0; row < 2; row += 1) {
            for (var column = 0; column < 3; column += 1) {
                slots.push(
                    shape('circle', {
                        cx: bodyX + 45 + column * 50,
                        cy: bodyY + 38 + row * 42,
                        r: 10,
                        fill: 'none'
                    }, canvas)
                );
            }
        }

        /* Die zusätzlichen Elektronen warten rechts neben dem Körper auf ihren Auftritt. */
        for (index = 0; index < 4; index += 1) {
            extras.push(
                shape('circle', {
                    cx: bodyX + bodyW + 40 + (index % 2) * 34,
                    cy: bodyY + 30 + Math.floor(index / 2) * 44,
                    r: 10,
                    fill: 'var(--viz-current)',
                    opacity: 0
                }, canvas)
            );
        }

        var bodyLabel = shape('text', { x: bodyX + bodyW / 2, y: bodyY - 12, 'text-anchor': 'middle' }, canvas);
        var caption = shape('text', { x: 260, y: 224, 'text-anchor': 'middle' }, canvas);
        var stateText = shape('text', { x: 260, y: 244, 'text-anchor': 'middle', class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function update() {
            var excess = controls.values.excess;
            var missing = Math.max(0, -excess);
            var added = Math.max(0, excess);

            slots.forEach(function (circle, position) {
                var isMissing = position < missing;

                circle.setAttribute('r', isMissing ? 9 : 10);
                circle.setAttribute('stroke', isMissing ? 'var(--line-strong)' : 'var(--viz-current)');
                circle.setAttribute('stroke-width', isMissing ? 1.5 : 2);
                circle.setAttribute('stroke-dasharray', isMissing ? '3 3' : 'none');
                circle.setAttribute('fill', isMissing ? 'none' : 'var(--viz-current)');
                circle.setAttribute('opacity', isMissing ? 0.8 : 1);
            });

            extras.forEach(function (circle, position) {
                circle.setAttribute('opacity', position < added ? 1 : 0);
            });

            /* Die Ladung steht in der Schreibweise mit Zehnerpotenz: fünf überschüssige
               Elektronen sind 8,01 · 10⁻¹⁹ C. */
            caption.textContent = labels.electrons[ctx.locale] + ': '
                + (excess >= 0 ? '6 + ' + added : '6 \u2212 ' + missing)
                + '  \u2013  ' + labels.charge[ctx.locale] + ' Q = '
                + (excess === 0 ? '0' : (excess > 0 ? '\u2212' : '+') + scientific(Math.abs(excess) * 1.602e-19, 2, ctx.locale))
                + ' C';

            stateText.textContent = excess === 0
                ? labels.neutral[ctx.locale]
                : (excess > 0 ? labels.negative[ctx.locale] : labels.positive[ctx.locale]);

            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            bodyLabel.textContent = labels.body[locale];
            controls.relabel(locale);
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        /* relabel() schreibt die Beschriftung des Körpers und ruft dann update(), das den
           Rest der Wörter setzt. */
        var relabelAll = function (locale) {
            relabel(locale);
            update();
        };

        relabelAll(ctx.locale);

        return {
            relabel: relabelAll,
            setInView: function () {
                /* Diese Zeichnung steht still: sie zeigt einen Zustand, keinen Ablauf. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       2. Spannung

       Zwei Punkte im Stromkreis als zwei Höhen. Die Höhe steht für die Energie pro
       Ladung; die Spannung ist der Unterschied der beiden Höhen. Bei null Volt gibt es
       deshalb keine Spannung und nicht "keine Ladung" - dann sind beide Balken gleich hoch.
       ---------------------------------------------------------------------- */
    register('voltage_potential', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 250, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var baseY = 200;
        var lowHeight = 40;
        var unitPerVolt = 5;
        var maxLift = 120;
        var leftX = 150;
        var rightX = 400;

        shape('line', { x1: 70, y1: baseY, x2: 460, y2: baseY, stroke: 'var(--line-strong)', 'stroke-width': 1 }, canvas);

        var leftBar = shape('rect', {
            x: leftX - 22, y: baseY - lowHeight, width: 44, height: lowHeight,
            fill: 'var(--viz-voltage)', opacity: 0.32
        }, canvas);

        var rightBar = shape('rect', {
            x: rightX - 22, y: baseY - lowHeight, width: 44, height: lowHeight,
            fill: 'var(--viz-voltage)'
        }, canvas);

        /* Die gestrichelte Linie läuft vom unteren zum oberen Punkt: sie zeigt, worauf
           sich der Unterschied bezieht. */
        shape('line', {
            x1: leftX, y1: baseY - lowHeight, x2: rightX + 22, y2: baseY - lowHeight,
            stroke: 'var(--line)', 'stroke-width': 1, 'stroke-dasharray': '4 4'
        }, canvas);

        var arrow = shape('line', { stroke: 'var(--viz-voltage)', 'stroke-width': 2 }, canvas);
        var arrowTop = shape('path', { fill: 'none', stroke: 'var(--viz-voltage)', 'stroke-width': 2 }, canvas);
        var arrowBottom = shape('path', { fill: 'none', stroke: 'var(--viz-voltage)', 'stroke-width': 2 }, canvas);

        var potentialLabel = shape('text', { x: 70, y: 24 }, canvas);
        var lowLabel = shape('text', { x: leftX, y: baseY + 24, 'text-anchor': 'middle' }, canvas);
        var highLabel = shape('text', { x: rightX, y: baseY + 24, 'text-anchor': 'middle' }, canvas);
        var differenceLabel = shape('text', { x: rightX + 16, class: 'viz-value' }, canvas);
        var noteLabel = shape('text', { x: 260, y: 244, 'text-anchor': 'middle', class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function update() {
            var volts = controls.values.voltage;
            var lift = Math.min(maxLift, volts * unitPerVolt);
            var topY = baseY - lowHeight - lift;
            var visible = volts > 0;

            rightBar.setAttribute('y', topY);
            rightBar.setAttribute('height', lowHeight + lift);

            arrow.setAttribute('x1', rightX);
            arrow.setAttribute('x2', rightX);
            arrow.setAttribute('y1', topY);
            arrow.setAttribute('y2', baseY - lowHeight - 6);
            arrowTop.setAttribute('d', 'M ' + (rightX - 5) + ' ' + (topY + 7) + ' L ' + rightX + ' ' + topY);
            arrowBottom.setAttribute('d', 'M ' + (rightX - 5) + ' ' + (baseY - lowHeight - 13) + ' L ' + rightX + ' ' + (baseY - lowHeight - 6));

            differenceLabel.setAttribute('y', topY - 14);
            differenceLabel.textContent = number(volts, 1, ctx.locale) + ' V';

            [arrow, arrowTop, arrowBottom, differenceLabel].forEach(function (element) {
                element.setAttribute('opacity', visible ? 1 : 0);
            });

            noteLabel.textContent = visible ? labels.difference[ctx.locale] : labels.none[ctx.locale];
            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            potentialLabel.textContent = labels.potential[locale];
            lowLabel.textContent = labels.low[locale];
            highLabel.textContent = labels.high[locale];
            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        return {
            relabel: relabel,
            setInView: function () {
                /* Steht still: der Zustand ändert sich nur über den Regler. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       3. Stromstärke

       Der wichtigste Punkt dieser Zeichnung: die Punkte wandern LANGSAM. Sie wandern
       umso schneller, je größer der Strom ist - aber auch bei fünf Ampere bleiben sie
       sichtbar träge. Genau so ist es in Wirklichkeit, und genau deshalb steht unter der
       Zeichnung, dass die Energie nicht mit ihnen wandert.
       ---------------------------------------------------------------------- */
    register('current_drift', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 210, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var wireX = 40;
        var wireY = 70;
        var wireW = 440;
        var wireH = 46;
        var innerW = wireW - 24;
        var markerX = 380;
        /* Bei 1 A wandern die Punkte 14 Pixel in der Sekunde: absichtlich langsam und
           gerade noch als Bewegung zu erkennen. */
        var pixelsPerAmpere = 14;
        var dotCount = 18;
        var dots = [];
        var offset = 0;
        var playing = true;
        var index;

        shape('rect', { x: wireX, y: wireY, width: wireW, height: wireH, rx: 4, fill: 'var(--viz-plane)' }, canvas);
        shape('line', { x1: wireX, y1: wireY, x2: wireX + wireW, y2: wireY, stroke: 'var(--line-strong)', 'stroke-width': 1 }, canvas);
        shape('line', {
            x1: wireX, y1: wireY + wireH, x2: wireX + wireW, y2: wireY + wireH,
            stroke: 'var(--line-strong)', 'stroke-width': 1
        }, canvas);
        shape('line', {
            x1: markerX, y1: wireY - 12, x2: markerX, y2: wireY + wireH + 12,
            stroke: 'var(--viz-current)', 'stroke-width': 1.5, 'stroke-dasharray': '4 4'
        }, canvas);

        for (index = 0; index < dotCount; index += 1) {
            dots.push(
                shape('circle', {
                    cx: wireX + 12 + (index / dotCount) * innerW,
                    cy: wireY + 12 + (index % 3) * 11,
                    r: 3.5,
                    fill: 'var(--viz-current)'
                }, canvas)
            );
        }

        var wireLabel = shape('text', { x: wireX, y: 48 }, canvas);
        var markerLabel = shape('text', { x: markerX + 8, y: 48 }, canvas);
        var perSecondLabel = shape('text', { x: wireX, y: 152, class: 'viz-value' }, canvas);
        var countLabel = shape('text', { x: wireX, y: 172, class: 'viz-caption' }, canvas);
        var noteLabel = shape('text', { x: wireX, y: 196, class: 'viz-caption' }, canvas);

        function paintDots() {
            dots.forEach(function (dot, position) {
                dot.setAttribute('cx', round(wireX + 12 + ((position / dotCount) * innerW + offset) % innerW, 1));
            });
        }

        function updateReadout() {
            var amperes = controls.values.current;

            /* Die Zahl der Elektronen je Sekunde ist exakt: Ladung geteilt durch die
               Elementarladung. Sie ist so groß, dass sie hier nur in Zehnerpotenzen
               lesbar ist - und das ist der Punkt dieser Zeile. */
            perSecondLabel.textContent = labels.perSecond[ctx.locale] + ': '
                + number(amperes, 1, ctx.locale) + ' C/s  =  '
                + scientific(amperes / 1.602e-19, 1, ctx.locale) + ' ' + labels.electrons[ctx.locale] + '/s';
        }

        var step = {
            play: playing,
            inView: true,
            step: function (delta) {
                offset = (offset + controls.values.current * pixelsPerAmpere * delta) % innerW;
                paintDots();
            }
        };

        function setPlaying(state) {
            playing = state;
            step.play = state;
            buttons.paint(state);
            ticker.refresh();
        }

        var controls = buildControls(spec, ctx, function () {
            updateReadout();
            ticker.refresh();
        });

        var buttons = buildButtons(ctx, playing, function () {
            setPlaying(!playing);
        }, function () {
            offset = 0;
            controls.reset();
            paintDots();
            updateReadout();
            setPlaying(false);
        });

        ticker.add(step);

        function relabel(locale) {
            ctx.locale = locale;
            wireLabel.textContent = labels.wire[locale];
            markerLabel.textContent = labels.section[locale];
            countLabel.textContent = labels.counted[locale];
            noteLabel.textContent = labels.note[locale];
            controls.relabel(locale);
            updateReadout();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);
        root.appendChild(buttons.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);
        paintDots();

        /* Ohne ausdrücklichen Klick läuft hier nichts, wenn Bewegung unerwünscht ist. */
        if (ctx.prefersReducedMotion()) {
            setPlaying(false);
        }

        return {
            relabel: relabel,
            setInView: function (visible) {
                step.inView = visible;
                ticker.refresh();
            },
            reset: function () {
                offset = 0;
                controls.reset();
                paintDots();
                updateReadout();
                setPlaying(false);
            }
        };
    });

    /* ----------------------------------------------------------------------
       4. Widerstand

       Ein geschlossener Kreis mit Quelle und Widerstand. Die Spannung ist fest, der
       Widerstand ist einstellbar - und deshalb sinkt der Strom, wenn er steigt, und die
       Punkte wandern langsamer. Sie sind ein Modell für die Stromstärke, nicht für die
       Geschwindigkeit der Energie.
       ---------------------------------------------------------------------- */
    register('resistance_flow', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 250, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var fixed = spec.fixed || {};
        var voltage = typeof fixed.voltage === 'number' ? fixed.voltage : 6;
        /* Bei 1 A wandern die Punkte 10 Pixel in der Sekunde - dieselbe Größenordnung wie
           bei der Stromstärke, damit beide Zeichnungen dasselbe Bild abgeben. */
        var pixelsPerAmpere = 10;
        var dotCount = 20;
        var left = 70;
        var right = 450;
        var top = 50;
        var bottom = 200;
        var spanX = right - left;
        var spanY = bottom - top;
        var perimeter = 2 * (spanX + spanY);
        var sourceGap = 26;
        var middleY = top + spanY / 2;
        var dots = [];
        var offset = 0;
        var playing = true;
        var index;

        /* Der Kreis in vier Stücken, links mit einer Lücke für die Quelle. */
        shape('line', { x1: left, y1: top, x2: right, y2: top, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: right, y1: top, x2: right, y2: bottom, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: right, y1: bottom, x2: left, y2: bottom, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: left, y1: top, x2: left, y2: middleY - sourceGap, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: left, y1: middleY + sourceGap, x2: left, y2: bottom, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);

        /* Die Quelle: die lange Linie ist der Pluspol, die kurze der Minuspol. */
        shape('line', { x1: left, y1: middleY - sourceGap, x2: left, y2: middleY - 14, stroke: 'var(--viz-voltage)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: left, y1: middleY + 10, x2: left, y2: middleY + sourceGap, stroke: 'var(--viz-voltage)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: left - 22, y1: middleY - 14, x2: left + 22, y2: middleY - 14, stroke: 'var(--viz-voltage)', 'stroke-width': 2.5 }, canvas);
        shape('line', { x1: left - 11, y1: middleY + 10, x2: left + 11, y2: middleY + 10, stroke: 'var(--viz-voltage)', 'stroke-width': 2.5 }, canvas);

        /* Der Widerstand oben: ein Rechteck im Leiter, wie im Schaltplan. */
        shape('rect', {
            x: (left + right) / 2 - 34, y: top - 14, width: 68, height: 28,
            fill: 'var(--viz-plane)', stroke: 'var(--viz-current)', 'stroke-width': 2
        }, canvas);

        for (index = 0; index < dotCount; index += 1) {
            dots.push(shape('circle', { cx: left, cy: top, r: 3.5, fill: 'var(--viz-current)' }, canvas));
        }

        var sourceLabel = shape('text', { x: 20, y: middleY + 56 }, canvas);
        var fixedLabel = shape('text', { x: 20, y: middleY + 74, class: 'viz-caption' }, canvas);
        var resistorLabel = shape('text', { x: (left + right) / 2, y: top + 46, 'text-anchor': 'middle', class: 'viz-value' }, canvas);
        var currentLabel = shape('text', { x: 20, y: bottom + 36, class: 'viz-value' }, canvas);
        /* Die Legende für die Punkte steht über dem Kreis und nicht darunter: unten
           wäre der Platz von den zwei Zeilen der Quelle belegt. */
        var electronsLabel = shape('text', { x: left, y: top - 26, class: 'viz-caption' }, canvas);

        /* Ein Punkt auf dem Weg: oben nach rechts, rechts hinunter, unten zurück,
           links wieder hinauf. */
        function place(progress) {
            var distance = (((progress % 1) + 1) % 1) * perimeter;

            if (distance < spanX) {
                return { x: left + distance, y: top };
            }

            distance -= spanX;

            if (distance < spanY) {
                return { x: right, y: top + distance };
            }

            distance -= spanY;

            if (distance < spanX) {
                return { x: right - distance, y: bottom };
            }

            return { x: left, y: bottom - (distance - spanX) };
        }

        function paintDots() {
            dots.forEach(function (dot, position) {
                var spot = place(position / dotCount + offset);

                dot.setAttribute('cx', round(spot.x, 1));
                dot.setAttribute('cy', round(spot.y, 1));
            });
        }

        function updateReadout() {
            var amperes = voltage / controls.values.resistance;

            resistorLabel.textContent = labels.resistor[ctx.locale] + ' '
                + number(controls.values.resistance, 1, ctx.locale) + ' \u03a9';
            currentLabel.textContent = labels.current[ctx.locale] + ': I = '
                + number(voltage, 0, ctx.locale) + ' V / '
                + number(controls.values.resistance, 1, ctx.locale) + ' \u03a9 = '
                + number(amperes, 2, ctx.locale) + ' A';
        }

        var step = {
            play: playing,
            inView: true,
            step: function (delta) {
                var amperes = voltage / controls.values.resistance;

                offset = (offset + (amperes * pixelsPerAmpere * delta) / perimeter) % 1;
                paintDots();
            }
        };

        function setPlaying(state) {
            playing = state;
            step.play = state;
            buttons.paint(state);
            ticker.refresh();
        }

        var controls = buildControls(spec, ctx, function () {
            updateReadout();
            ticker.refresh();
        });

        var buttons = buildButtons(ctx, playing, function () {
            setPlaying(!playing);
        }, function () {
            offset = 0;
            controls.reset();
            paintDots();
            updateReadout();
            setPlaying(false);
        });

        ticker.add(step);

        function relabel(locale) {
            ctx.locale = locale;
            sourceLabel.textContent = labels.source[locale];
            fixedLabel.textContent = labels.fixedVoltage[locale] + ': ' + number(voltage, 0, locale) + ' V';
            electronsLabel.textContent = labels.electrons[locale];
            controls.relabel(locale);
            updateReadout();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);
        root.appendChild(buttons.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);
        paintDots();

        if (ctx.prefersReducedMotion()) {
            setPlaying(false);
        }

        return {
            relabel: relabel,
            setInView: function (visible) {
                step.inView = visible;
                ticker.refresh();
            },
            reset: function () {
                offset = 0;
                controls.reset();
                paintDots();
                updateReadout();
                setPlaying(false);
            }
        };
    });

    /* ----------------------------------------------------------------------
       5. Ohmsches Gesetz

       Das Schaubild, um das es geht: Spannung waagerecht, Strom senkrecht. Die Gerade
       läuft durch den Ursprung, ihr Anstieg ist 1/R. Der Regler für den Widerstand macht
       genau das sichtbar - eine flachere oder steilere Gerade.

       Die senkrechte Achse geht bis 3 A. Liegt der Arbeitspunkt darüber, sitzt der Punkt
       am oberen Rand, und die genauen Zahlen stehen unter dem Bild: das Bild zeigt den
       Zusammenhang, die Zahl sagt den Wert.
       ---------------------------------------------------------------------- */
    register('ohms_characteristic', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 300, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var plotLeft = 70;
        var plotRight = 390;
        var plotTop = 40;
        var plotBottom = 230;
        var maxVoltage = 24;
        var maxCurrent = 3;
        var ticks = 6;
        var yTickLabels = [];
        var index;

        function xOf(volts) {
            return plotLeft + (volts / maxVoltage) * (plotRight - plotLeft);
        }

        function yOf(amperes) {
            return plotBottom - (amperes / maxCurrent) * (plotBottom - plotTop);
        }

        /* Das Raster und die Achsen: dünn, ruhig, ohne Rahmen. */
        for (index = 0; index <= ticks; index += 1) {
            shape('line', {
                x1: round(xOf((index / ticks) * maxVoltage), 1), y1: plotTop,
                x2: round(xOf((index / ticks) * maxVoltage), 1), y2: plotBottom,
                stroke: 'var(--line)', 'stroke-width': 1
            }, canvas);

            shape('line', {
                x1: plotLeft, y1: round(yOf((index / ticks) * maxCurrent), 1),
                x2: plotRight, y2: round(yOf((index / ticks) * maxCurrent), 1),
                stroke: 'var(--line)', 'stroke-width': 1
            }, canvas);

            shape('text', {
                x: round(xOf((index / ticks) * maxVoltage), 1), y: plotBottom + 18,
                'text-anchor': 'middle', class: 'viz-caption'
            }, canvas).textContent = String(round((index / ticks) * maxVoltage, 0));

            yTickLabels.push(shape('text', {
                x: plotLeft - 8, y: round(yOf((index / ticks) * maxCurrent), 1) + 4,
                'text-anchor': 'end', class: 'viz-caption'
            }, canvas));
        }

        shape('line', { x1: plotLeft, y1: plotBottom, x2: plotRight + 16, y2: plotBottom, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);
        shape('line', { x1: plotLeft, y1: plotBottom, x2: plotLeft, y2: plotTop - 16, stroke: 'var(--line-strong)', 'stroke-width': 1.5 }, canvas);

        var curve = shape('line', { x1: plotLeft, y1: plotBottom, x2: plotLeft, y2: plotBottom, stroke: 'var(--viz-current)', 'stroke-width': 2.5 }, canvas);
        var guideX = shape('line', { stroke: 'var(--viz-voltage)', 'stroke-width': 1, 'stroke-dasharray': '3 3' }, canvas);
        var guideY = shape('line', { stroke: 'var(--viz-voltage)', 'stroke-width': 1, 'stroke-dasharray': '3 3' }, canvas);
        var point = shape('circle', { cx: plotLeft, cy: plotBottom, r: 5, fill: 'var(--viz-voltage)' }, canvas);

        var yTitle = shape('text', { x: 8, y: 24 }, canvas);
        var xTitle = shape('text', { x: plotRight + 26, y: plotBottom + 18 }, canvas);
        var readout = shape('text', { x: 8, y: 272, class: 'viz-value' }, canvas);
        var readoutSecond = shape('text', { x: 8, y: 292, class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function update() {
            var volts = controls.values.voltage;
            var ohms = controls.values.resistance;
            var amperes = ohms > 0 ? volts / ohms : 0;
            /* Die Gerade läuft bis dorthin, wo sie das Bild verlässt: bei kleinem
               Widerstand wird sie sehr steil, und genau das ist der Punkt. */
            var endVolts = Math.min(maxVoltage, maxCurrent * ohms);
            var markedCurrent = Math.min(amperes, maxCurrent);

            curve.setAttribute('x2', round(xOf(endVolts), 1));
            curve.setAttribute('y2', round(yOf(Math.min(maxCurrent, endVolts / ohms)), 1));

            point.setAttribute('cx', round(xOf(volts), 1));
            point.setAttribute('cy', round(yOf(markedCurrent), 1));

            guideX.setAttribute('x1', round(xOf(volts), 1));
            guideX.setAttribute('y1', round(yOf(markedCurrent), 1));
            guideX.setAttribute('x2', round(xOf(volts), 1));
            guideX.setAttribute('y2', plotBottom);
            guideY.setAttribute('x1', plotLeft);
            guideY.setAttribute('y1', round(yOf(markedCurrent), 1));
            guideY.setAttribute('x2', round(xOf(volts), 1));
            guideY.setAttribute('y2', round(yOf(markedCurrent), 1));

            readout.textContent = labels.workingPoint[ctx.locale] + ': '
                + number(volts, 1, ctx.locale) + ' V \u00b7 ' + number(ohms, 0, ctx.locale) + ' \u03a9';
            readoutSecond.textContent = 'I = ' + number(volts, 1, ctx.locale) + ' V / '
                + number(ohms, 0, ctx.locale) + ' \u03a9 = ' + number(amperes, 2, ctx.locale) + ' A';
            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            yTitle.textContent = labels.currentAxis[locale];
            xTitle.textContent = labels.voltageAxis[locale];

            /* Die Zahlen an der senkrechten Achse tragen ein Komma oder einen Punkt, je
               nach Sprache; die waagerechte Achse zählt in ganzen Volt. */
            yTickLabels.forEach(function (label, position) {
                label.textContent = number((position / ticks) * maxCurrent, 1, locale);
            });

            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        return {
            relabel: relabel,
            setInView: function () {
                /* Steht still: das Bild ändert sich nur über die Regler. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       6. Leistung und Energie

       Zwei Regler ergeben eine Leistung, und eine Uhr macht daraus eine Energie. Das ist
       der Unterschied, um den es hier geht: die Leistung sagt, wie schnell, die Energie
       sagt, wie viel. Der Balken links macht die Leistung sichtbar, die Zeilen rechts
       summieren sie über die Zeit.
       ---------------------------------------------------------------------- */
    register('power_energy', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 250, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var barX = 90;
        var barBase = 190;
        var barMaxHeight = 140;
        var maxPower = 24 * 5;
        var seconds = 0;
        var joules = 0;
        var playing = true;

        shape('line', {
            x1: barX - 44, y1: barBase, x2: barX + 44, y2: barBase,
            stroke: 'var(--line-strong)', 'stroke-width': 1.5
        }, canvas);

        var bar = shape('rect', { x: barX - 26, y: barBase, width: 52, height: 0, fill: 'var(--viz-power)' }, canvas);
        var barTitle = shape('text', { x: barX, y: barBase - barMaxHeight - 16, 'text-anchor': 'middle' }, canvas);
        var barValue = shape('text', { x: barX, y: barBase + 22, 'text-anchor': 'middle', class: 'viz-value' }, canvas);

        var powerLine = shape('text', { x: 200, y: 70, class: 'viz-value' }, canvas);
        var energyLine = shape('text', { x: 200, y: 110, class: 'viz-value' }, canvas);
        var energySecond = shape('text', { x: 200, y: 132, class: 'viz-caption' }, canvas);
        var timeLine = shape('text', { x: 200, y: 166, class: 'viz-value' }, canvas);
        var stateLine = shape('text', { x: 200, y: 190, class: 'viz-caption' }, canvas);

        function update() {
            var volts = controls.values.voltage;
            var amperes = controls.values.current;
            var watts = volts * amperes;
            var wattHours = joules / 3600;

            bar.setAttribute('y', round(barBase - (watts / maxPower) * barMaxHeight, 1));
            bar.setAttribute('height', round((watts / maxPower) * barMaxHeight, 1));

            barValue.textContent = number(watts, 1, ctx.locale) + ' W';

            powerLine.textContent = 'P = U \u00b7 I = ' + number(volts, 1, ctx.locale) + ' V \u00b7 '
                + number(amperes, 1, ctx.locale) + ' A = ' + number(watts, 1, ctx.locale) + ' W';
            energyLine.textContent = labels.energy[ctx.locale] + ': E = ' + number(joules, 0, ctx.locale) + ' J';
            energySecond.textContent = '= ' + number(wattHours, 3, ctx.locale) + ' Wh';
            timeLine.textContent = labels.runtime[ctx.locale] + ': ' + number(seconds, 1, ctx.locale) + ' s';
            stateLine.textContent = playing ? labels.meter[ctx.locale] : labels.stopped[ctx.locale];
            controls.sync(ctx.locale);
        }

        var step = {
            play: playing,
            inView: true,
            step: function (delta) {
                seconds += delta;
                joules += controls.values.voltage * controls.values.current * delta;
                update();
            }
        };

        function setPlaying(state) {
            playing = state;
            step.play = state;
            buttons.paint(state);
            update();
            ticker.refresh();
        }

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        var buttons = buildButtons(ctx, playing, function () {
            setPlaying(!playing);
        }, function () {
            seconds = 0;
            joules = 0;
            controls.reset();
            setPlaying(false);
        });

        ticker.add(step);

        function relabel(locale) {
            ctx.locale = locale;
            barTitle.textContent = labels.power[locale];
            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);
        root.appendChild(buttons.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        if (ctx.prefersReducedMotion()) {
            setPlaying(false);
        }

        return {
            relabel: relabel,
            setInView: function (visible) {
                step.inView = visible;
                ticker.refresh();
            },
            reset: function () {
                seconds = 0;
                joules = 0;
                controls.reset();
                setPlaying(false);
            }
        };
    });

    /* ----------------------------------------------------------------------
       7. Elektrisches Feld

       Zwei Ladungen und ihre Feldlinien. Ungleichnamig laufen die Linien von der einen
       zur anderen Ladung; gleichnamig stoßen sie sich ab und weichen einander aus, und
       dazwischen bleibt eine Stelle ohne Feld. Wie viele Linien gezeichnet sind, hängt
       an der Ladung - mehr Linien heißt stärkeres Feld, genau so wird es gelesen.
       ---------------------------------------------------------------------- */
    register('electric_field_lines', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 260, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var leftX = 150;
        var rightX = 370;
        var midY = 130;
        var radius = 15;
        var maxCurves = 8;
        var curves = [];
        var index;

        /* Ein leeres Feld von Linien: je nach Bild braucht es bis zu acht. */
        for (index = 0; index < maxCurves; index += 1) {
            curves.push(shape('path', {
                d: 'M 0 0',
                fill: 'none',
                stroke: 'var(--viz-field)',
                'stroke-width': 1.4,
                opacity: 0
            }, canvas));
        }

        /* Die Stelle ohne Feld gibt es nur beim gleichnamigen Paar. */
        var neutralLine = shape('line', {
            x1: 260, y1: 46, x2: 260, y2: 214,
            stroke: 'var(--line-strong)', 'stroke-width': 1, 'stroke-dasharray': '4 4', opacity: 0
        }, canvas);

        var leftCharge = shape('circle', { cx: leftX, cy: midY, r: radius, fill: 'var(--viz-voltage)' }, canvas);
        var rightCharge = shape('circle', { cx: rightX, cy: midY, r: radius, fill: 'var(--viz-current)' }, canvas);

        /* Die Vorzeichen als Striche und nicht als Schriftzeichen: so hängen sie an
           keiner Schriftart. Gezeichnet mit der Hintergrundfarbe wirken sie wie ein
           Loch in der Ladung - und das stimmt in beiden Erscheinungsbildern. */
        shape('path', { d: 'M ' + (leftX - 7) + ' ' + midY + ' h 14', stroke: 'var(--bg)', 'stroke-width': 2.5 }, canvas);
        shape('path', { d: 'M ' + (leftX - 7) + ' ' + midY + ' h 14 M ' + leftX + ' ' + (midY - 7) + ' v 14', stroke: 'var(--bg)', 'stroke-width': 2.5 }, canvas);

        var leftLabel = shape('text', { x: leftX, y: midY + 44, 'text-anchor': 'middle' }, canvas);
        var rightLabel = shape('text', { x: rightX, y: midY + 44, 'text-anchor': 'middle' }, canvas);
        var lineLabel = shape('text', { x: 24, y: 28 }, canvas);
        var neutralLabel = shape('text', { x: 270, y: 28, class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function update() {
            var strength = controls.values.strength;
            var like = controls.values.pair === 'like';
            var total = Math.max(2, Math.min(maxCurves, Math.round(strength * 2) + 1));
            var paths = [];
            var i;

            if (like === false) {
                /* Von der positiven zur negativen Ladung: die Linien bündeln sich und
                   weichen in der Mitte auseinander. */
                var half = (total - 1) / 2;

                for (i = 0; i < total; i += 1) {
                    var offset = (i - half) * 22;

                    paths.push('M ' + (leftX + radius) + ' ' + round(midY + offset * 0.3, 1)
                        + ' C ' + (leftX + 70) + ' ' + round(midY + offset * 1.7, 1)
                        + ', ' + (rightX - 70) + ' ' + round(midY + offset * 1.7, 1)
                        + ', ' + (rightX - radius) + ' ' + round(midY + offset * 0.3, 1));
                }
            } else {
                /* Gleichnamig: jede Ladung strahlt nach außen ab, und die Linien weichen
                   einander aus. Deshalb gibt es keine Linie entlang der Verbindungsachse. */
                var perCharge = Math.max(1, Math.min(3, Math.round(total / 2)));
                var angles = [-60, 0, 60];
                var length = 70 + strength * 26;

                for (i = 0; i < perCharge; i += 1) {
                    var rad = angles[i] * Math.PI / 180;
                    var dx = Math.cos(rad);
                    var dy = Math.sin(rad);

                    paths.push('M ' + round(leftX - dx * radius, 1) + ' ' + round(midY + dy * radius, 1)
                        + ' L ' + round(leftX - dx * length, 1) + ' ' + round(midY + dy * length, 1));
                    paths.push('M ' + round(rightX + dx * radius, 1) + ' ' + round(midY + dy * radius, 1)
                        + ' L ' + round(rightX + dx * length, 1) + ' ' + round(midY + dy * length, 1));
                }
            }

            curves.forEach(function (curve, position) {
                curve.setAttribute('d', paths[position] || 'M 0 0');
                curve.setAttribute('opacity', paths[position] ? 1 : 0);
            });

            neutralLine.setAttribute('opacity', like ? 1 : 0);
            neutralLabel.setAttribute('opacity', like ? 1 : 0);

            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            leftLabel.textContent = labels.positive[locale];
            rightLabel.textContent = labels.negative[locale];
            lineLabel.textContent = labels.fieldLine[locale];
            neutralLabel.textContent = labels.neutral[locale];
            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        return {
            relabel: relabel,
            setInView: function () {
                /* Steht still: der Zustand ändert sich nur über die Regler. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       8. Leistung

       Vier Balken auf einer gemeinsamen Skala: drei bekannte Geräte und die eigene
       Einstellung. Dass der LED-Balken neben dem Wasserkocher kaum zu sehen ist, ist
       die Aussage des Bildes und kein Zeichenfehler.
       ---------------------------------------------------------------------- */
    register('power_bars', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 265, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var maxPower = (spec.fixed && typeof spec.fixed.maxPower === 'number') ? spec.fixed.maxPower : 2500;
        var left = 150;
        var right = 480;
        var gridTop = 30;
        var gridBottom = 190;
        var ticks = 5;
        var rows = [];
        var scaleMarks = [];
        var index;

        function xOf(watts) {
            return left + (watts / maxPower) * (right - left);
        }

        for (index = 0; index <= ticks; index += 1) {
            var value = (index / ticks) * maxPower;
            var x = round(xOf(value), 1);

            shape('line', { x1: x, y1: gridTop, x2: x, y2: gridBottom, stroke: 'var(--line)', 'stroke-width': 1 }, canvas);

            scaleMarks.push({
                value: value,
                node: shape('text', {
                    x: x, y: 206, 'text-anchor': 'middle', class: 'viz-caption'
                }, canvas)
            });
        }

        /* Eine Zeile je Vergleichsgröße. */
        (spec.references || []).forEach(function (reference, position) {
            var y = 44 + position * 28;

            rows.push({
                power: Number(reference.power),
                label: reference.label,
                labelNode: shape('text', { x: 140, y: y + 10, 'text-anchor': 'end' }, canvas),
                bar: shape('rect', { x: left, y: y, width: 0, height: 12, fill: 'var(--viz-neutral)', opacity: 0.45 }, canvas),
                valueNode: shape('text', { x: left + 8, y: y + 10, class: 'viz-caption' }, canvas)
            });
        });

        var settingBar = shape('rect', { x: left, y: 132, width: 0, height: 22, fill: 'var(--viz-power)' }, canvas);
        var settingLabel = shape('text', { x: 140, y: 148, 'text-anchor': 'end' }, canvas);
        var settingValue = shape('text', { x: left + 8, y: 149, class: 'viz-value' }, canvas);
        var scaleTitle = shape('text', { x: 24, y: 22 }, canvas);
        var powerLine = shape('text', { x: 24, y: 232, class: 'viz-value' }, canvas);
        var currentLine = shape('text', { x: 24, y: 252, class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function update() {
            var volts = controls.values.voltage;
            var amperes = controls.values.current;
            var watts = volts * amperes;

            rows.forEach(function (row) {
                var width = round(xOf(row.power) - left, 1);

                row.bar.setAttribute('width', Math.max(1, width));
                row.valueNode.setAttribute('x', round(xOf(row.power) + 8, 1));
                row.valueNode.textContent = number(row.power, 0, ctx.locale) + ' W';
                row.labelNode.textContent = ctx.text(row.label);
            });

            settingBar.setAttribute('width', Math.max(1, round(xOf(watts) - left, 1)));
            settingValue.setAttribute('x', round(xOf(watts) + 8, 1));
            settingValue.textContent = number(watts, 1, ctx.locale) + ' W';

            powerLine.textContent = labels.power[ctx.locale] + ': P = U · I = '
                + number(volts, 0, ctx.locale) + ' V · ' + number(amperes, 1, ctx.locale) + ' A = '
                + number(watts, 1, ctx.locale) + ' W';

            /* Die Leistung hängt an der Spannung: bei 230 V braucht dieselbe Leistung
               einen anderen Strom als bei 12 V. */
            currentLine.textContent = labels.current[ctx.locale] + ': I = P / U = '
                + number(watts, 1, ctx.locale) + ' W / 230 V = ' + number(watts / 230, 2, ctx.locale) + ' A';

            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            scaleTitle.textContent = labels.scale[locale];
            settingLabel.textContent = labels.setting[locale];

            /* Die Zahlen an der Skala tragen ein Tausenderzeichen oder ein Komma, je nach
               Sprache - sie werden hier neu geschrieben und nicht nur einmal beim Aufbau. */
            scaleMarks.forEach(function (mark) {
                mark.node.textContent = number(mark.value, 0, locale);
            });

            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        return {
            relabel: relabel,
            setInView: function () {
                /* Steht still: das Bild ändert sich nur über die Regler. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       9. Energie als Fläche

       Leistung nach oben, Zeit nach rechts: die Fläche unter der Leistungslinie ist die
       Energie. Bleibt die Leistung gleich, ist diese Fläche ein Rechteck - und genau
       das zeigt diese Zeichnung.
       ---------------------------------------------------------------------- */
    register('power_time_area', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 300, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        var plot = buildPlot(canvas, {
            left: 76,
            right: 430,
            top: 40,
            bottom: 230,
            xMax: 10,
            yMax: 3000,
            ticks: 5,
            locale: ctx.locale
        });
        var price = (spec.fixed && typeof spec.fixed.pricePerKwh === 'number') ? spec.fixed.pricePerKwh : 0.3;
        var currency = (spec.fixed && typeof spec.fixed.currency === 'string') ? spec.fixed.currency : '€';

        var area = shape('rect', { x: plot.left, y: plot.bottom, width: 0, height: 0, fill: 'var(--viz-power)', opacity: 0.22 }, canvas);
        var edge = shape('rect', { x: plot.left, y: plot.bottom, width: 0, height: 0, fill: 'none', stroke: 'var(--viz-power)', 'stroke-width': 2 }, canvas);
        var areaLabel = shape('text', { 'text-anchor': 'middle', class: 'viz-value' }, canvas);
        var areaHint = shape('text', { x: plot.left + 6, y: plot.top + 14, class: 'viz-caption' }, canvas);
        var yTitle = shape('text', { x: 8, y: 24 }, canvas);
        var xTitle = shape('text', { x: plot.right + 10, y: plot.bottom + 18 }, canvas);
        var firstLine = shape('text', { x: 8, y: 262, class: 'viz-value' }, canvas);
        var secondLine = shape('text', { x: 8, y: 282, class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function update() {
            var watts = controls.values.power;
            var hours = controls.values.time;
            var wattHours = watts * hours;
            var kilowattHours = wattHours / 1000;
            var x = plot.xOf(hours);
            var y = plot.yOf(watts);
            var width = round(x - plot.left, 1);
            var height = round(plot.bottom - y, 1);

            [area, edge].forEach(function (node) {
                node.setAttribute('x', plot.left);
                node.setAttribute('y', y);
                node.setAttribute('width', Math.max(1, width));
                node.setAttribute('height', Math.max(1, height));
            });

            areaLabel.setAttribute('x', round((plot.left + x) / 2, 1));
            areaLabel.setAttribute('y', round((y + plot.bottom) / 2 + 4, 1));
            areaLabel.textContent = labels.energy[ctx.locale] + ' ' + number(wattHours, 0, ctx.locale) + ' Wh';

            firstLine.textContent = 'E = P · t = ' + number(watts, 0, ctx.locale) + ' W · '
                + number(hours, 1, ctx.locale) + ' h = ' + number(wattHours, 0, ctx.locale) + ' Wh = '
                + number(kilowattHours, 3, ctx.locale) + ' kWh';

            secondLine.textContent = labels.price[ctx.locale] + ' ' + number(price, 2, ctx.locale) + ' '
                + currency + '/kWh  ·  ' + labels.cost[ctx.locale] + ' '
                + number(kilowattHours * price, 2, ctx.locale) + ' ' + currency;

            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            yTitle.textContent = labels.power[locale];
            xTitle.textContent = labels.time[locale];
            areaHint.textContent = labels.area[locale];
            plot.relabel(locale);
            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        return {
            relabel: relabel,
            setInView: function () {
                /* Steht still: das Bild ändert sich nur über die Regler. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       10. Zwei Geräte, dieselbe Energie

       Gerät A gibt Leistung und Zeit vor, daraus folgt seine Energie. Gerät B hat eine
       eigene Leistung, und seine Zeit wird so berechnet, dass beide Flächen gleich groß
       sind. Die waagerechte Achse wächst mit, wenn Gerät B länger braucht - sonst wäre
       sein Rechteck nur ein Strich am Rand.
       ---------------------------------------------------------------------- */
    register('power_time_compare', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 300, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        /* Das Raster liegt in einer eigenen Gruppe: bei jeder Änderung wird es neu
           gezeichnet, damit die Achse zu den beiden Rechtecken passt. Die Rechtecke
           stehen DAHINTER und nicht darin - sie bleiben also oben. */
        var plotLayer = shape('g', {}, canvas);
        var plot = null;
        var rectA = shape('rect', { fill: 'var(--viz-voltage)', opacity: 0.22 }, canvas);
        var edgeA = shape('rect', { fill: 'none', stroke: 'var(--viz-voltage)', 'stroke-width': 2 }, canvas);
        var rectB = shape('rect', { fill: 'var(--viz-power)', opacity: 0.22 }, canvas);
        var edgeB = shape('rect', { fill: 'none', stroke: 'var(--viz-power)', 'stroke-width': 2 }, canvas);
        var labelA = shape('text', { class: 'viz-value' }, canvas);
        var labelB = shape('text', { class: 'viz-value' }, canvas);
        var yTitle = shape('text', { x: 8, y: 24 }, canvas);
        var xTitle = shape('text', { x: 440, y: 248 }, canvas);
        var firstLine = shape('text', { x: 8, y: 264, class: 'viz-value' }, canvas);
        var secondLine = shape('text', { x: 8, y: 284, class: 'viz-caption' }, canvas);
        var noteLine = shape('text', { x: 440, y: 32, class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function drawPlot(maxTime) {
            while (plotLayer.firstChild !== null) {
                plotLayer.removeChild(plotLayer.firstChild);
            }

            plot = buildPlot(plotLayer, {
                left: 76,
                right: 420,
                top: 44,
                bottom: 230,
                xMax: maxTime,
                yMax: 3000,
                ticks: 5,
                locale: ctx.locale
            });
        }

        function update() {
            var powerA = controls.values.powerA;
            var timeA = controls.values.timeA;
            var powerB = controls.values.powerB;
            var energy = powerA * timeA;
            var timeB = powerB > 0 ? energy / powerB : 0;
            /* Die Achse wächst in Schritten von fünf Stunden: dann liegen die Zahlen an
               den Teilstrichen auf ganzen Werten und nicht bei 0,8 und 1,6 Stunden. */
            var maxTime = Math.max(5, Math.ceil(Math.max(timeA, timeB) / 5) * 5);

            /* Das Raster nur neu zeichnen, wenn sich die Achse wirklich ändert. */
            if (plot === null || plot.xMax !== maxTime) {
                drawPlot(maxTime);
                plot.xMax = maxTime;
            }

            function place(rect, edge, watts, hours) {
                var x = plot.xOf(hours);
                var y = plot.yOf(watts);
                var width = Math.max(1, round(x - plot.left, 1));
                var height = Math.max(1, round(plot.bottom - y, 1));

                [rect, edge].forEach(function (node) {
                    node.setAttribute('x', plot.left);
                    node.setAttribute('y', y);
                    node.setAttribute('width', width);
                    node.setAttribute('height', height);
                });

                return { x: x, y: y };
            }

            var a = place(rectA, edgeA, powerA, timeA);
            var b = place(rectB, edgeB, powerB, timeB);

            labelA.setAttribute('x', round((plot.left + a.x) / 2, 1));
            labelA.setAttribute('y', round((a.y + plot.bottom) / 2 + 4, 1));
            labelA.textContent = labels.deviceA[ctx.locale] + ': ' + number(energy, 0, ctx.locale) + ' Wh';

            labelB.setAttribute('x', round((plot.left + b.x) / 2, 1));
            labelB.setAttribute('y', round((b.y + plot.bottom) / 2 + 4, 1));
            labelB.textContent = labels.deviceB[ctx.locale] + ': ' + number(timeB, 2, ctx.locale) + ' h';

            noteLine.setAttribute('x', plot.right - 4);
            noteLine.textContent = labels.sameEnergy[ctx.locale];

            firstLine.textContent = 'E = P · t = ' + number(powerA, 0, ctx.locale) + ' W · '
                + number(timeA, 1, ctx.locale) + ' h = ' + number(energy, 0, ctx.locale) + ' Wh';

            secondLine.textContent = 'E = ' + number(energy, 0, ctx.locale) + ' Wh  ·  '
                + labels.deviceB[ctx.locale] + ': t = E / P = ' + number(energy, 0, ctx.locale) + ' Wh / '
                + number(powerB, 0, ctx.locale) + ' W = ' + number(timeB, 2, ctx.locale) + ' h';

            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            yTitle.textContent = labels.power[locale];
            xTitle.textContent = labels.time[locale];

            if (plot !== null) {
                plot.relabel(locale);
            }

            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        return {
            relabel: relabel,
            setInView: function () {
                /* Steht still: das Bild ändert sich nur über die Regler. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       11. Gleichstrom und Wechselstrom

       Dieselbe Achse, zwei Kurven: eine waagerechte Gerade für Gleichstrom, eine
       Sinuskurve für Wechselstrom. Beim Wechselstrom stehen zwei gestrichelte Linien
       dabei - der Scheitelwert als höchster Punkt und der Effektivwert, mit dem
       gerechnet wird.
       ---------------------------------------------------------------------- */
    register('dc_ac_wave', function (root, spec, ctx) {
        var labels = spec.labels;
        var parts = buildFrame(520, 300, ctx.text(spec.a11y));
        var canvas = parts.canvas;
        /* Die Zeitachse des Bildes reicht von 0 bis 60 ms: das sind drei Perioden der
           Netzfrequenz. Nicht "window" nennen - das ist der Name des Fensters. */
        var spanMs = 60;
        var samples = 240;
        var plot = buildPlot(canvas, {
            left: 66,
            right: 412,
            top: 40,
            bottom: 240,
            xMax: spanMs,
            yMin: -400,
            yMax: 400,
            ticks: 4,
            locale: ctx.locale
        });

        var peakLine = shape('line', { stroke: 'var(--viz-neutral)', 'stroke-width': 1, 'stroke-dasharray': '4 4' }, canvas);
        var peakLineLow = shape('line', { stroke: 'var(--viz-neutral)', 'stroke-width': 1, 'stroke-dasharray': '4 4' }, canvas);
        var effectiveLine = shape('line', { stroke: 'var(--viz-current)', 'stroke-width': 1, 'stroke-dasharray': '2 3' }, canvas);
        var effectiveLineLow = shape('line', { stroke: 'var(--viz-current)', 'stroke-width': 1, 'stroke-dasharray': '2 3' }, canvas);
        var curve = shape('path', { fill: 'none', stroke: 'var(--viz-voltage)', 'stroke-width': 2.5 }, canvas);
        var kindLabel = shape('text', { x: plot.left + 8, y: plot.bottom - 12 }, canvas);
        var peakLabel = shape('text', { x: plot.right + 8, class: 'viz-caption' }, canvas);
        var effectiveLabel = shape('text', { x: plot.right + 8, class: 'viz-caption' }, canvas);
        var yTitle = shape('text', { x: 8, y: 24 }, canvas);
        var xTitle = shape('text', { x: plot.right + 8, y: plot.bottom + 18 }, canvas);
        var firstLine = shape('text', { x: 8, y: 264, class: 'viz-value' }, canvas);
        var secondLine = shape('text', { x: 8, y: 284, class: 'viz-caption' }, canvas);

        var controls = buildControls(spec, ctx, function () {
            update();
        });

        function voltageAt(tMs) {
            var amplitude = controls.values.amplitude;

            if (controls.values.kind === 'dc') {
                return amplitude;
            }

            return amplitude * Math.sin(2 * Math.PI * controls.values.frequency * tMs / 1000);
        }

        function update() {
            var amplitude = controls.values.amplitude;
            var frequency = controls.values.frequency;
            var isDirect = controls.values.kind === 'dc';
            var path = '';
            var index;

            for (index = 0; index <= samples; index += 1) {
                var tMs = (index / samples) * spanMs;

                path += (index === 0 ? 'M ' : 'L ') + round(plot.xOf(tMs), 1) + ' '
                    + round(plot.yOf(voltageAt(tMs)), 1) + ' ';
            }

            curve.setAttribute('d', path.trim());

            /* Beim Gleichstrom gibt es keinen Scheitelwert und keinen Effektivwert -
               die Spannung ist einfach da. Deshalb stehen die Linien nur beim
               Wechselstrom im Bild. */
            [peakLine, peakLineLow, effectiveLine, effectiveLineLow, peakLabel, effectiveLabel].forEach(function (node) {
                node.setAttribute('opacity', isDirect ? 0 : 1);
            });

            var effective = amplitude / Math.SQRT2;

            [[peakLine, amplitude], [peakLineLow, -amplitude], [effectiveLine, effective], [effectiveLineLow, -effective]]
                .forEach(function (pair) {
                    pair[0].setAttribute('x1', plot.left);
                    pair[0].setAttribute('x2', plot.right);
                    pair[0].setAttribute('y1', round(plot.yOf(pair[1]), 1));
                    pair[0].setAttribute('y2', round(plot.yOf(pair[1]), 1));
                });

            peakLabel.setAttribute('y', round(plot.yOf(amplitude) - 6, 1));
            peakLabel.textContent = labels.peak[ctx.locale] + ' ' + number(amplitude, 0, ctx.locale) + ' V';
            effectiveLabel.setAttribute('y', round(plot.yOf(effective) - 6, 1));
            effectiveLabel.textContent = labels.effective[ctx.locale] + ' ' + number(effective, 0, ctx.locale) + ' V';

            kindLabel.textContent = isDirect ? labels.dc[ctx.locale] : labels.ac[ctx.locale];

            var period = 1000 / frequency;

            firstLine.textContent = labels.peak[ctx.locale] + ': '
                + number(amplitude, 0, ctx.locale) + ' V  ·  ' + labels.effective[ctx.locale] + ': '
                + number(effective, 0, ctx.locale) + ' V';

            secondLine.textContent = labels.period[ctx.locale] + ': T = 1 / f = 1 / '
                + number(frequency, 0, ctx.locale) + ' Hz = ' + number(period, 1, ctx.locale) + ' ms';

            controls.sync(ctx.locale);
        }

        function relabel(locale) {
            ctx.locale = locale;
            yTitle.textContent = labels.voltageAxis[locale];
            xTitle.textContent = labels.timeAxis[locale];
            plot.relabel(locale);
            controls.relabel(locale);
            update();
        }

        root.appendChild(parts.frame);
        root.appendChild(controls.root);

        var caution = buildCaution(spec, ctx);

        if (caution) {
            root.appendChild(caution);
        }

        relabel(ctx.locale);

        return {
            relabel: relabel,
            setInView: function () {
                /* Steht still: das Bild ändert sich nur über die Regler. */
            },
            reset: function () {
                controls.reset();
                update();
            }
        };
    });

    /* ----------------------------------------------------------------------
       Nach außen
       ---------------------------------------------------------------------- */

    window.LernkarteiVisuals = {
        has: function (kind) {
            return Object.prototype.hasOwnProperty.call(factories, kind);
        },
        /*
            Baut eine Zeichnung und hängt an ihren Sprachwechsel noch das an, was sich
            unterwegs angemeldet hat (der Modell-Hinweis und die drei Knöpfe). Damit muss
            keine einzelne Zeichnung daran denken.
        */
        create: function (kind, root, spec, ctx) {
            if (!window.LernkarteiVisuals.has(kind)) {
                return null;
            }

            var extra = [];

            ctx.onRelabel = function (apply) {
                extra.push(apply);
            };

            var instance = factories[kind](root, spec, ctx);

            if (!instance) {
                return instance;
            }

            /*
                Einmal gleich: der Sprachwechsel läuft erst beim Umschalten, und der
                erste Aufbau soll seinen Text trotzdem schon tragen - ohne das bliebe
                der Knopf "Zurücksetzen" beim Laden leer.
            */
            extra.forEach(function (apply) {
                apply(ctx.locale);
            });

            var relabel = instance.relabel;

            instance.relabel = function (locale) {
                relabel(locale);
                extra.forEach(function (apply) {
                    apply(locale);
                });
            };

            return instance;
        }
    };
})();
