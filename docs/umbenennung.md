# Umbenennung auf deutsche Namen — Vorschlag

Stand 27.09.2026. **Noch nichts umbenannt.** Diese Datei ist die Tabelle zum
Freigeben (Schritt 5) und wird nach dem Umbenennen gelöscht.

## Regeln

* **Deutsche Namen** für Funktionen, Konstanten und sprechende Variablen in PHP
  und JavaScript. Keine Umlaute, kein ß (`ae`, `oe`, `ue`, `ss`).
* Schreibweisen bleiben: Funktionen und Variablen `camelCase`, Konstanten
  `GROSS_MIT_UNTERSTRICH`.
* **Nicht umbenannt** (bleibt englisch): Datei- und Ordnernamen, Tabellen und
  Spalten der Datenbank, JSON-Feldnamen der API, URL-Parameter (`?category=`,
  `?parent_id=`, `?id=`), CSS-Klassen und CSS-Variablen, Übersetzungsschlüssel,
  PHP-/JS-Schlüsselwörter und eingebaute Funktionen.
* Betroffen sind **504 Namen** (Funktionen und Konstanten) plus die sprechenden
  Variablen; dazu kommen alle Aufrufstellen (rund 3 000 Stellen im Repo).

## Glossar (wird überall gleich benutzt)

| englisch | deutsch | | englisch | deutsch |
| --- | --- | --- | --- | --- |
| card | Karte | | find | finde / hole |
| category | Kategorie | | create | lege … an |
| subcategory | Unterkategorie | | update / change | aendere |
| area (learning area) | Bereich | | delete | loesche |
| exercise | Uebung (task = Aufgabe) | | save / store | speichere |
| review (rating) | Bewertung | | build | baue |
| repetition logic | Wiederholung | | render / draw | zeichne |
| progress | Fortschritt | | show / hide | zeige / verstecke |
| due | faellig | | load / fetch | lade / hole |
| interval | Intervall | | open / close | oeffne / schliesse |
| stability | Stabilitaet | | wire | verdrahte |
| difficulty | Schwierigkeit | | read / write | lies / schreibe |
| state / status | Zustand / Status | | normalise | normalisiere |
| user | Benutzer | | validate / check | pruefe |
| session | Sitzung | | count | zaehle / anzahl |
| streak | Serie | | queue | Warteschlange |
| queue / row / column | Warteschlange / Zeile / Spalte | | known / unsure / new | gewusst / unsicher / neu |

---

## 1. PHP

### `public/index.php`
Keine Funktionen. **Nichts zu tun.**

### `public/api/account.php`
| alt | neu | Art |
| --- | --- | --- |
| handle_account_request | behandleKontoAnfrage | Funktion |

### `public/api/auth.php`
| alt | neu | Art |
| --- | --- | --- |
| handle_auth_request | behandleAnmeldeAnfrage | Funktion |

### `public/api/import_cards.php`
| alt | neu | Art |
| --- | --- | --- |
| CARD_IMPORT_PREVIEW_ROWS | IMPORT_VORSCHAU_ZEILEN | Konstante |

### `public/api/review.php`
| alt | neu | Art |
| --- | --- | --- |
| REVIEW_MODES | WIEDERHOLUNGS_MODI | Konstante |

### `src/config/database.php`
| alt | neu | Art |
| --- | --- | --- |
| create_database_connection | erstelleDatenbankVerbindung | Funktion |

### `src/helpers/html.php`
| alt | neu | Art |
| --- | --- | --- |
| escape_html | maskiereHtml | Funktion |
| asset_url | dateiAdresse | Funktion |

### `src/helpers/json_response.php`
| alt | neu | Art |
| --- | --- | --- |
| send_json | sendeJson | Funktion |
| send_json_success | sendeJsonErfolg | Funktion |
| send_json_error | sendeJsonFehler | Funktion |

### `src/helpers/request_input.php`
| alt | neu | Art |
| --- | --- | --- |
| read_json_object | liesJsonObjekt | Funktion |
| clean_input_text | bereinigeTextEingabe | Funktion |
| require_input_text | verlangeTextEingabe | Funktion |
| optional_input_text | optionaleTextEingabe | Funktion |
| optional_positive_id | optionalePositiveId | Funktion |
| optional_icon_scale | optionalerSymbolMassstab | Funktion |
| optional_flag | optionalesJaNein | Funktion |
| optional_svg_icon | optionalesSymbolSvg | Funktion |
| require_query_id | verlangeIdAusAdresse | Funktion |
| optional_query_language | optionaleSpracheAusAdresse | Funktion |
| SUPPORTED_CONTENT_LANGUAGES | UNTERSTUETZTE_INHALTSSPRACHEN | Konstante |

### `src/helpers/session_user.php`
| alt | neu | Art |
| --- | --- | --- |
| current_user_id | angemeldeteBenutzerId | Funktion |
| session_user_id_from_php_session | benutzerIdAusSitzung | Funktion |
| session_user_exists | benutzerExistiert | Funktion |
| session_user_required_error | fehlerBenutzerFehlt | Funktion |
| SESSION_COOKIE_LIFETIME | SITZUNGSCOOKIE_LEBENSDAUER | Konstante |

### `src/helpers/svg_sanitizer.php`
| alt | neu | Art |
| --- | --- | --- |
| svg_sanitize | bereinigeSvg | Funktion |
| svg_clean_children | bereinigeSvgKinder | Funktion |
| svg_clean_element | bereinigeSvgElement | Funktion |
| svg_use_points_inside | svgUseZeigtNachInnen | Funktion |
| svg_text_is_dangerous | svgTextIstGefaehrlich | Funktion |
| svg_looks_risky | svgWirktRiskant | Funktion |
| SVG_MAX_UPLOAD_BYTES | SVG_MAX_HOCHLADEBYTES | Konstante |
| SVG_MAX_STORED_BYTES | SVG_MAX_SPEICHERBYTES | Konstante |
| SVG_BLOCKED_ELEMENT_LOOKUP | SVG_GESPERRTE_ELEMENTE | Konstante |

### `src/helpers/translations.php`
| alt | neu | Art |
| --- | --- | --- |
| learning_app_translations | lernkarteiUebersetzungen | Funktion |
| t | **bleibt `t`** (kurzer Helfer, überall benutzt) | Funktion |
| t_fill | fuellTextEin | Funktion |

### `src/services/card_service.php`
| alt | neu | Art |
| --- | --- | --- |
| normalize_card_row | normalisiereKartenZeile | Funktion |
| find_card | findeKarte | Funktion |
| update_card | aendereKarte | Funktion |
| delete_card | loescheKarte | Funktion |
| delete_progress_of_categories | loescheFortschrittVonKategorien | Funktion |
| card_exercise_table_available | uebungsTabelleVorhanden | Funktion |
| card_exercise_params_available | uebungsParameterVorhanden | Funktion |
| card_exercise_join | kartenUebungsJoin | Funktion |
| card_exercise_from_row | uebungAusZeile | Funktion |
| card_exercise_params_from_row | uebungsParameterAusZeile | Funktion |
| save_card_exercise | speichereKartenUebung | Funktion |
| card_language_has_question | spracheHatFrage | Funktion |
| card_exercise_from_request | uebungAusAnfrage | Funktion |
| card_map_region_is_valid | kartenRegionIstGueltig | Funktion |
| card_language_columns | sprachSpalten | Funktion |
| card_columns | kartenSpalten | Funktion |
| card_column_available | kartenSpalteVorhanden | Funktion |
| card_content_languages | inhaltsSprachen | Funktion |
| card_read_columns | kartenLeseSpalten | Funktion |
| card_language_is_complete | spracheIstVollstaendig | Funktion |
| card_localized_text | kartenTextInSprache | Funktion |
| card_texts_from_body | kartenTexteAusAnfrage | Funktion |
| create_card_translated | legeKarteMitSprachenAn | Funktion |
| delete_cards_of_categories | loescheKartenVonKategorien | Funktion |
| CARD_MAX_TEXT_LENGTH | KARTE_MAX_TEXTLAENGE | Konstante |
| CARD_MAP_REGION_PATTERN | KARTE_REGION_MUSTER | Konstante |
| CARD_MAP_REGION_MAX_LENGTH | KARTE_REGION_MAX_LAENGE | Konstante |

### `src/services/category_service.php`
| alt | neu | Art |
| --- | --- | --- |
| category_columns | kategorieSpalten | Funktion |
| category_columns_from_metadata | kategorieSpaltenAusMetadaten | Funktion |
| category_column_available | kategorieSpalteVorhanden | Funktion |
| category_select_sql | kategorieAuswahlSql | Funktion |
| find_main_categories | findeHauptkategorien | Funktion |
| find_subcategories | findeUnterkategorien | Funktion |
| find_category | findeKategorie | Funktion |
| category_exists | kategorieExistiert | Funktion |
| category_sibling_name_exists | geschwisterNameExistiert | Funktion |
| create_category | legeKategorieAn | Funktion |
| update_category | aendereKategorie | Funktion |
| category_subtree_ids | teilbaumIds | Funktion |
| category_subtree_stats | teilbaumZahlen | Funktion |
| category_delete_dependents | loeschAbhaengigkeiten | Funktion |
| delete_category_tree | loescheKategorieBaum | Funktion |
| normalize_category_rows | normalisiereKategorieZeilen | Funktion |
| normalize_category_row | normalisiereKategorieZeile | Funktion |
| normalize_optional_text | normalisiereOptionalenText | Funktion |
| category_ids_with_children | kategorienMitKindern | Funktion |
| CATEGORY_MAX_NAME_LENGTH | KATEGORIE_MAX_NAMENSLAENGE | Konstante |
| CATEGORY_MAX_DEPTH | KATEGORIE_MAX_TIEFE | Konstante |

### `src/services/card_import_service.php`
| alt | neu | Art |
| --- | --- | --- |
| card_import_read_file | liesImportDatei | Funktion |
| card_import_column_for | spalteFuerUeberschrift | Funktion |
| card_import_missing_columns | fehlendeSpalten | Funktion |
| card_import_row_is_empty | zeileIstLeer | Funktion |
| card_import_fatal | importFehler | Funktion |
| card_import_validate | pruefeImportZeilen | Funktion |
| card_import_row_problem | zeilenProblem | Funktion |
| card_import_language_used | spracheBenutzt | Funktion |
| card_import_language_complete | spracheVollstaendig | Funktion |
| card_import_row_front | zeilenVorderseite | Funktion |
| card_import_front_key | vorderseitenSchluessel | Funktion |
| card_import_card_from_row | karteAusZeile | Funktion |
| card_import_preview_row | vorschauZeile | Funktion |
| card_import_existing_fronts | vorhandeneVorderseiten | Funktion |
| card_import_insert | schreibeImportKarten | Funktion |
| CARD_IMPORT_HEADER | IMPORT_PFLICHTSPALTEN | Konstante |
| CARD_IMPORT_OPTIONAL | IMPORT_OPTIONALE_SPALTEN | Konstante |
| CARD_IMPORT_MAX_BYTES | IMPORT_MAX_BYTES | Konstante |
| CARD_IMPORT_MAX_ROWS | IMPORT_MAX_ZEILEN | Konstante |
| CARD_IMPORT_SEPARATOR | IMPORT_TRENNZEICHEN | Konstante |
| CARD_IMPORT_ALIASES | IMPORT_SPALTENNAMEN | Konstante |
| CARD_IMPORT_TRUE_VALUES | IMPORT_JA_WERTE | Konstante |
| CARD_IMPORT_FALSE_VALUES | IMPORT_NEIN_WERTE | Konstante |

### `src/services/review_service.php`
| alt | neu | Art |
| --- | --- | --- |
| review_find_progress | findeFortschritt | Funktion |
| review_status_of | statusVon | Funktion |
| review_is_due | istFaellig | Funktion |
| review_cards_with_progress | kartenMitFortschritt | Funktion |
| review_branch_category_ids | kategorieUndKinderIds | Funktion |
| review_cards_in_categories | kartenInKategorien | Funktion |
| review_summarise_cards | zaehleKartenNachStatus | Funktion |
| review_interval_previews | intervallVorschauen | Funktion |
| review_due_timestamp | faelligkeitsZeitpunkt | Funktion |
| review_public_progress | oeffentlicherFortschritt | Funktion |
| review_calculate | berechneFortschritt | Funktion |
| review_rate_card | bewerteKarte | Funktion |
| review_run_in_transaction | inTransaktion | Funktion |
| review_store_progress | speichereFortschritt | Funktion |
| review_undo_rating | nimmBewertungZurueck | Funktion |
| review_row_matches | zeilePasstZuWerten | Funktion |
| review_build_queue | baueWarteschlange | Funktion |
| review_directions_of | richtungenDerKarte | Funktion |
| review_cards_all_categories | alleKartenAllerKategorien | Funktion |
| REVIEW_STATE_NEW | ZUSTAND_NEU | Konstante |
| REVIEW_STATE_LEARNING | ZUSTAND_LERNEN | Konstante |
| REVIEW_STATE_KNOWN | ZUSTAND_GEWUSST | Konstante |
| REVIEW_RATINGS | BEWERTUNGEN | Konstante |
| REVIEW_FIRST_STABILITY | ERSTE_STABILITAET | Konstante |
| REVIEW_STABILITY_FACTOR | STABILITAETSFAKTOR | Konstante |
| REVIEW_MIN_STABILITY | MIN_STABILITAET | Konstante |
| REVIEW_AGAIN_MINUTES | NOCHMAL_MINUTEN | Konstante |
| REVIEW_DIFFICULTY_START | SCHWIERIGKEIT_START | Konstante |
| REVIEW_DIFFICULTY_STEP | SCHWIERIGKEIT_SCHRITT | Konstante |
| REVIEW_DIFFICULTY_MIN | SCHWIERIGKEIT_MIN | Konstante |
| REVIEW_DIFFICULTY_MAX | SCHWIERIGKEIT_MAX | Konstante |
| REVIEW_KNOWN_MIN_STABILITY | GEWUSST_AB_STABILITAET | Konstante |

### `src/services/study_session_service.php`
| alt | neu | Art |
| --- | --- | --- |
| study_session_timestamp | sitzungsZeitpunkt | Funktion |
| study_session_has_category | sitzungHatKategorie | Funktion |
| study_session_record_rating | schreibeBewertungInSitzung | Funktion |
| study_session_find_open | findeOffeneSitzung | Funktion |
| study_session_close | schliesseSitzung | Funktion |
| study_session_take_back_rating | nimmBewertungAusSitzungZurueck | Funktion |
| STUDY_SESSION_KNOWN_RATINGS | SITZUNG_GEWUSSTE_BEWERTUNGEN | Konstante |

### `src/services/dashboard_service.php`
| alt | neu | Art |
| --- | --- | --- |
| dashboard_streak | serieInTagen | Funktion |
| dashboard_streaks_by_category | serienJeKategorie | Funktion |
| dashboard_streak_days | zaehleSerienTage | Funktion |
| DASHBOARD_STREAK_MAX_DAYS | SERIE_MAX_TAGE | Konstante |

### `src/services/user_service.php`
| alt | neu | Art |
| --- | --- | --- |
| user_columns | benutzerSpalten | Funktion |
| user_column_available | benutzerSpalteVorhanden | Funktion |
| user_sign_in_ready | anmeldungBereit | Funktion |
| user_csrf_token | csrfToken | Funktion |
| user_csrf_valid | csrfTokenGueltig | Funktion |
| user_identifier_is_email | kennungIstEmail | Funktion |
| user_identifier_problem | kennungProblem | Funktion |
| user_password_problem | passwortProblem | Funktion |
| user_find | findeBenutzer | Funktion |
| user_register | registriereBenutzer | Funktion |
| user_sign_in | meldeBenutzerAn | Funktion |
| user_password_matches | passwortPasst | Funktion |
| user_sign_in_session | setzeAnmeldung | Funktion |
| user_sign_out_session | beendeAnmeldung | Funktion |
| user_public_data | oeffentlicheBenutzerdaten | Funktion |
| user_initials | benutzerInitialen | Funktion |
| delete_user_account | loescheBenutzerkonto | Funktion |
| USER_NAME_MIN_LENGTH | BENUTZER_NAME_MIN_LAENGE | Konstante |
| USER_NAME_MAX_LENGTH | BENUTZER_NAME_MAX_LAENGE | Konstante |
| USER_EMAIL_MAX_LENGTH | BENUTZER_EMAIL_MAX_LAENGE | Konstante |
| USER_PASSWORD_MIN_LENGTH | BENUTZER_PASSWORT_MIN_LAENGE | Konstante |
| USER_PASSWORD_MAX_LENGTH | BENUTZER_PASSWORT_MAX_LAENGE | Konstante |
| USER_DEFAULT_ROLE | BENUTZER_STANDARDROLLE | Konstante |
| USER_FAILED_SIGN_IN_DELAY | BENUTZER_WARTEZEIT_NACH_FEHLVERSUCH | Konstante |
| USER_DUMMY_HASH | BENUTZER_BLINDHASH | Konstante |

### `src/services/exercise_service.php`
| alt | neu | Art |
| --- | --- | --- |
| exercise_catalog | uebungsKatalog | Funktion |
| exercise_type_keys | uebungsTypSchluessel | Funktion |
| exercise_type_is_known | uebungsTypBekannt | Funktion |
| exercise_type_label | uebungsTypName | Funktion |
| exercise_type_default_params | uebungsStandardParameter | Funktion |
| exercise_normalise_params | normalisiereUebungsParameter | Funktion |
| exercise_params_are_valid | uebungsParameterGueltig | Funktion |
| exercise_task_builders | aufgabenBauer | Funktion |
| exercise_build_task | baueAufgabe | Funktion |
| exercise_draw | zieheZahl | Funktion |
| exercise_pick | waehleZufaellig | Funktion |
| exercise_number | zahlFuerSprache | Funktion |
| exercise_decimal | dezimalzahl | Funktion |
| exercise_rounded | gerundeteZahl | Funktion |
| exercise_same | gleicherText | Funktion |
| exercise_sentence | uebungsSatz | Funktion |
| exercise_superscript | hochzahl | Funktion |
| exercise_fraction_text | bruchText | Funktion |
| exercise_greatest_common_divisor | groessterGemeinsamerTeiler | Funktion |
| exercise_least_common_multiple | kleinstesGemeinsamesVielfaches | Funktion |
| exercise_draw_multiple | zieheVielfaches | Funktion |
| exercise_draw_series | zieheZahlenreihe | Funktion |
| exercise_series_text | zahlenreiheText | Funktion |
| exercise_unit_families | einheitenFamilien | Funktion |
| exercise_parse_cell | zerlegeUebungsZelle | Funktion |
| EXERCISE_FRACTION_DENOMINATORS | UEBUNG_BRUCH_NENNER | Konstante |
| EXERCISE_PERCENTAGES | UEBUNG_PROZENTWERTE | Konstante |
| EXERCISE_DECIMALS | UEBUNG_NACHKOMMASTELLEN | Konstante |

### `src/services/exercise_tasks.php`
| alt | neu | Art |
| --- | --- | --- |
| exercise_task_times_table | aufgabeEinmaleins | Funktion |
| exercise_task_division_inverse | aufgabeDivisionUmgekehrt | Funktion |
| exercise_task_fraction | aufgabeBruch | Funktion |
| exercise_task_negative_parens | aufgabeNegativeKlammern | Funktion |
| exercise_task_powers_scientific | aufgabeZehnerpotenzen | Funktion |
| exercise_task_linear_equation | aufgabeLineareGleichung | Funktion |
| exercise_formula_task | aufgabeFormelUmstellen | Funktion |
| exercise_task_pythagoras | aufgabePythagoras | Funktion |
| exercise_task_percent | aufgabeProzent | Funktion |
| exercise_task_percent_energy | aufgabeProzentEnergie | Funktion |
| exercise_task_rule_of_three | aufgabeDreisatz | Funktion |
| EXERCISE_PYTHAGORAS_TRIPLES | PYTHAGORAS_TRIPEL | Konstante |

> **Achtung, Sonderfall:** Diese Namen werden **dynamisch** gebildet
> (`'exercise_task_' . $type` in `exercise_service.php`, an zwei Stellen mit
> `function_exists()`). Beim Umbenennen müssen Präfix, Aufbau der Namen und die
> Zuordnungstabelle (`exercise_task_builders`) zusammen geändert werden — und
> `bin/import_energy_cards.php` prüft die Namen ebenfalls.

### `src/services/exercise_tasks_energy.php`
| alt | neu | Art |
| --- | --- | --- |
| exercise_task_unit_conversion | aufgabeEinheitenUmrechnen | Funktion |
| exercise_task_energy_formula | aufgabeEnergieformel | Funktion |
| exercise_task_efficiency | aufgabeWirkungsgrad | Funktion |
| exercise_task_utilisation | aufgabeAuslastung | Funktion |
| exercise_task_full_load_hours | aufgabeVolllaststunden | Funktion |
| exercise_task_quarter_hours | aufgabeViertelstunden | Funktion |
| exercise_task_statistics_spread | aufgabeStreuung | Funktion |
| exercise_task_mean_value | aufgabeMittelwert | Funktion |
| exercise_task_standard_deviation | aufgabeStandardabweichung | Funktion |
| exercise_task_data_table | aufgabeDatenTabelle | Funktion |

### `bin/import_cards_csv.php`
| alt | neu | Art |
| --- | --- | --- |
| import_arguments | liesArgumente | Funktion |
| import_int_option | liesZahlOption | Funktion |
| import_print_usage | zeigeHilfe | Funktion |
| import_strip_bom | entferneByteOrderMark | Funktion |
| import_read_record | liesDatensatz | Funktion |
| import_read_csv | liesCsv | Funktion |
| import_group_rows | gruppiereZeilen | Funktion |
| import_print_file | zeigeDateiUebersicht | Funktion |
| import_print_group | zeigeGruppenPlan | Funktion |
| import_write | schreibeKarten | Funktion |
| import_main | hauptAblauf | Funktion |
| CARD_CSV_COLUMNS | CSV_SPALTEN | Konstante |
| CARD_CSV_SAMPLES | CSV_BEISPIELE | Konstante |

### `bin/import_energy_cards.php`
| alt | neu | Art |
| --- | --- | --- |
| import_main | hauptAblauf | Funktion |
| import_read_arguments | liesArgumente | Funktion |
| import_print_usage | zeigeHilfe | Funktion |
| import_check_owner | pruefeBesitzer | Funktion |
| import_read_csv | liesCsv | Funktion |
| import_parse_exercise | zerlegeUebung | Funktion |
| import_region_is_valid | regionIstGueltig | Funktion |
| import_row_is_empty | zeileIstLeer | Funktion |
| import_expectation_problems | pruefeErwartung | Funktion |
| import_read_state | liesIstZustand | Funktion |
| import_print_step_a | zeigeSchrittA | Funktion |
| import_print_step_b | zeigeSchrittB | Funktion |
| import_front_collisions | findeVorderseitenKonflikte | Funktion |
| import_execute | fuehreAus | Funktion |
| import_delete_subcategories | loescheUnterkategorien | Funktion |
| CSV_HEADER | CSV_KOPFZEILE | Konstante |
| CSV_HEADER_WITH_REGION | CSV_KOPFZEILE_MIT_REGION | Konstante |
| CSV_HEADER_WITH_EXERCISE | CSV_KOPFZEILE_MIT_UEBUNG | Konstante |
| CSV_HEADER_WITH_REGION_AND_EXERCISE | CSV_KOPFZEILE_MIT_REGION_UND_UEBUNG | Konstante |
| MAX_SUBCATEGORY_LENGTH | MAX_UNTERKATEGORIE_LAENGE | Konstante |
| MAX_CARD_TEXT_LENGTH | MAX_KARTENTEXT_LAENGE | Konstante |
| MAX_REGION_LENGTH | MAX_REGION_LAENGE | Konstante |
| REGION_PATTERN | REGION_MUSTER | Konstante |

### `bin/import_english_csv.php`
**Bereits vollständig deutsch** (`meldung`, `titelzeile`, `kurz`, `csv_lesen`,
`sorte_finden`, `seiten_bauen`, `zeiten_karten`, `kategorie_name`, `NAMEN`,
`SORTEN`). **Nichts umzubenennen.** Vorschlag optional: `meldung` → `zeigeZeile`,
`kurz` → `kuerzeText`, `seiten_bauen` → `baueSprachSeiten` (sprechender). Bitte
sagen, ob das mitgemacht werden soll.

---

## 2. JavaScript (`public/assets/js/app.js`, 231 Funktionen)

`app.js` ist eine einzige Datei; die Umbenennung erfolgt in einem Durchgang und
wird danach komplett durchgetestet. Verschachtelte Kurzfunktionen bleiben
(`step`, `commit`, `swap`, `updateRow`, `acceptFile`, `showError` lokal).

**Bleibt unverändert (kurze Helfer, bewusst):** `t`, `el`, `pad2`.

### Laden, Zwischenspeicher, Netzwerk
| alt | neu |
| --- | --- |
| loadBootstrap | ladeGrunddaten |
| fetchCategoryOne | holeKategorie |
| findCachedCategory | findeImSpeicher |
| streakForCategory | serieFuerKategorie |
| fetchCards | holeKarten |
| fetchExerciseTasks | holeUebungsAufgaben |
| bootstrapDropCards | verwerfeKartenImSpeicher |
| bootstrapDropCategories | verwerfeKategorienImSpeicher |
| bootstrapDropAll | verwerfeSpeicher |
| fetchJson | holeJson |
| fetchCategories | holeKategorien |
| apiRequest | sendeApiAnfrage |

### Anmeldung und Konto
| alt | neu |
| --- | --- |
| authFetch | sendeAnmeldeAnfrage |
| loadAuthState | ladeAnmeldeZustand |
| renderAccountSlot | zeichneKontoBereich |
| signOut | meldeAb |
| goToStartPage | geheZurStartseite |
| accountNoteText | kontoHinweisText |
| refreshAfterAuthChange | ladeNachAnmeldeWechsel |
| openAuthDialog | oeffneAnmeldeDialog |
| renderAuthDialog | zeichneAnmeldeDialog |
| addAuthFields | fuegeAnmeldeFelderHinzu |
| setEyeState | setzeAugenZustand |
| runAuthSubmit | sendeAnmeldung |
| authFailure | anmeldeFehler |
| errorMessage | fehlerText |
| accountFetch | sendeKontoAnfrage |
| openAccountDialog | oeffneKontoDialog |
| closeAccountDialog | schliesseKontoDialog |
| showAccountStep | zeigeKontoSchritt |
| buildAccountList | baueKontoListe |
| addAccountRow | fuegeKontoZeileHinzu |
| formatMemberSince | formatiereMitgliedSeit |
| submitAccountDelete | loescheKonto |
| showAccountError | zeigeKontoFehler |
| setAccountBusy | setzeKontoBeschaeftigt |
| wireAccountDialog | verdrahteKontoDialog |

### Rückmeldung und Hinweise
| alt | neu |
| --- | --- |
| hideFeedback | versteckeMeldung |
| showFeedback | zeigeMeldung |
| showPageNote | zeigeSeitenHinweis |
| hidePageNote | versteckeSeitenHinweis |
| setPendingNote | merkeHinweis |
| showPendingNote | zeigeGemerktenHinweis |

### Thema, Sprache, Text
| alt | neu |
| --- | --- |
| updateThemeControl | aktualisiereThemaKnopf |
| rotateThemeIcon | dreheThemaSymbol |
| applyTheme | wendeThemaAn |
| updateLanguageUnderline | aktualisiereSprachStrich |
| updateLanguageButtons | aktualisiereSprachKnoepfe |
| applyLocale | wendeSpracheAn |
| translateStaticText | uebersetzeFesteTexte |
| displayName | anzeigeName |
| shortLabel | kurzeBeschriftung |
| cardText | kartenText |
| cardLanguageName | sprachName |
| prefersReducedMotion | willWenigerBewegung |
| readStorage | liesSpeicher |
| writeStorage | schreibeSpeicher |

### Startseite, Kacheln, Zeichnen
| alt | neu |
| --- | --- |
| setHeading | setzeUeberschrift |
| applyRevealOrder | setzeAuftauchReihenfolge |
| animateCount | zaehleHoch |
| showSkeletons | zeigePlatzhalter |
| clearLinked | entferneVerbundene |
| initialLetter | ersterBuchstabe |
| fillIconCircle | fuelleSymbolKreis |
| useMeasuredIconScale | nutzeGemessenenMassstab |
| measureIconScale | messeSymbolMassstab |
| buildAreaTile | baueBereichsKachel |
| hideStates | versteckeZustaende |
| showError | zeigeFehler |
| renderHome | zeichneStartseite |
| renderAreaCards | zeichneBereichsKarten |
| render | zeichne |
| renderDetail | zeichneDetailansicht |
| renderFigures | zeichneZahlenzeile |
| renderDashboard | zeichneKennzahlen |
| showEntryEmpty | zeigeLeerzustand |
| categoryMeta | kategorieDaten |
| tileDataLine | kachelInfoZeile |

### Listenzeilen, Sidebar, Menü, Brotkrumen
| alt | neu |
| --- | --- |
| buildSidebarLink | baueSeitenleisteEintrag |
| buildMenu | baueMenue |
| toggleMenu | schalteMenue |
| closeMenu | schliesseMenue |
| buildEntryRow | baueListenZeile |
| buildLearnButton | baueLernenKnopf |
| entryDueCount | faelligeKartenDerZeile |
| buildCardRow | baueKartenZeile |
| updateFooterControls | aktualisiereFusszeile |
| openAddForCurrentEntry | oeffneAnlegen |
| openEditForCurrentEntry | oeffneBearbeiten |
| setCrumb | setzeBrotkrumen |

### Kachelreihe (Karussell)
| alt | neu |
| --- | --- |
| tilesPerView | kachelnProAnsicht |
| tileStep | kachelSchritt |
| scheduleTileNavigation | planeKachelNavigation |
| updateTileNavigation | aktualisiereKachelNavigation |
| scrollTilesBy | scrolleKachelnUm |
| setTilesScroll | setzeKachelStand |
| beginTileDrag | beginneKachelZiehen |
| endTileDrag | beendeKachelZiehen |
| scrollTilesToPointer | scrolleKachelnZumZeiger |
| wireTileNavigation | verdrahteKachelNavigation |

### Dialoge und Formulare
| alt | neu |
| --- | --- |
| openDialog | oeffneDialog |
| closeDialog | schliesseDialog |
| setDialogError | setzeDialogFehler |
| setFieldError | setzeFeldFehler |
| clearFieldError | loescheFeldFehler |
| clearDialogErrors | loescheDialogFehler |
| addField | fuegeFeldHinzu |
| addTranslationGroup | fuegeUebersetzungsGruppeHinzu |
| growTextarea | lasseTextfeldWachsen |
| handleLoadError | behandleLadefehler |
| openCategoryForm | oeffneKategorieFormular |
| iconPayload | symbolDaten |
| validateCategoryForm | pruefeKategorieFormular |
| openCardForm | oeffneKartenFormular |
| validateCardForm | pruefeKartenFormular |
| buildCardPreview | baueKartenVorschau |
| updateCardPreview | aktualisiereKartenVorschau |
| wireCardDialogShortcuts | verdrahteKartenKuerzel |
| buildLanguageTabs | baueSprachReiter |
| switchCardLanguage | wechsleKartenSprache |
| updateCardLanguageTabs | aktualisiereSprachReiter |

### Symbolfeld
| alt | neu |
| --- | --- |
| normaliseIconSvg | normalisiereSymbolSvg |
| renderIconPreview | zeichneSymbolVorschau |
| buildIconField | baueSymbolFeld |

### Löschen
| alt | neu |
| --- | --- |
| deletePreviewParts | loeschVorschauTeile |
| knownDependents | bekannteAbhaengigkeiten |
| requestDelete | frageLoeschen |
| confirmDelete | bestaetigeLoeschen |
| queueDelete | reiheLoeschenEin |
| undoPendingDelete | nimmLoeschenZurueck |
| finishPendingDelete | schliesseLoeschenAb |
| flushPendingDelete | sendeWartendesLoeschen |
| sendDelete | sendeLoeschen |
| askDeleteAgain | frageLoeschenErneut |

### Kartenliste und Suche
| alt | neu |
| --- | --- |
| renderCardTools | zeichneKartenWerkzeuge |
| renderCardList | zeichneKartenListe |
| cardStatusMeta | kartenStatusDaten |
| cardMatches | kartePasstZurSuche |
| formatDueDate | formatiereFaelligkeit |

### Lernmodus
| alt | neu |
| --- | --- |
| startLearning | starteLernen |
| beginLearnSession | beginneLernSitzung |
| limitNewCards | begrenzeNeueKarten |
| openNewCardsDialog | oeffneNeueKartenDialog |
| closeNewCardsDialog | schliesseNeueKartenDialog |
| buildNewCardsQuick | baueNeueKartenAuswahl |
| buildNewCardsChoice | baueNeueKartenKnopf |
| markNewCardsQuick | markiereNeueKartenAuswahl |
| confirmNewCards | bestaetigeNeueKarten |
| wireNewCardsDialog | verdrahteNeueKartenDialog |
| openLearnView | oeffneLernansicht |
| renderLearnCard | zeichneLernKarte |
| buildLearnButtons | baueAntwortKnoepfe |
| learnRatingKeys | bewertungsTasten |
| flipLearnCard | dreheLernKarte |
| rateLearnCard | bewerteLernKarte |
| setLearnButtonsDisabled | sperreAntwortKnoepfe |
| moveToNextLearnCard | geheZurNaechstenKarte |
| undoLearnRating | nimmLernBewertungZurueck |
| showLearnSummary | zeigeLernZusammenfassung |
| endLearnSessionOnServer | beendeLernSitzungAufDemServer |
| closeLearnView | schliesseLernansicht |
| askBeforeClosingLearn | frageVorDemSchliessen |
| showLearnNotice | zeigeLernHinweis |
| hideLearnNotice | versteckeLernHinweis |
| wireLearning | verdrahteLernen |
| wireLearnKeyboard | verdrahteLernTasten |
| wireLearnSwipe | verdrahteLernWischen |
| formatLearnInterval | formatiereIntervall |

### Landkarten
| alt | neu |
| --- | --- |
| loadMap | ladeLandkarte |
| regionsOfArea | regionenDesBereichs |
| buildCardMap | baueKartenLandkarte |
| showMap | zeigeLandkarte |
| addMapField | fuegeLandkartenFeldHinzu |
| dialogMapValue | dialogRegionWert |
| fillRegionOptions | fuelleRegionenAus |
| updateMapPreview | aktualisiereLandkartenVorschau |
| parseMapRegion | zerlegeRegion |
| regionLabel | regionName |
| countryName | landName |

### Übungsaufgaben
| alt | neu |
| --- | --- |
| exerciseTask | uebungsAufgabe |
| exerciseText | uebungsText |
| exerciseTypeSettings | uebungsTypEinstellungen |
| cardKindValue | kartenArtWert |
| exerciseParamFieldName | uebungsParameterFeld |
| setCardKind | setzeKartenArt |
| addExerciseFields | fuegeUebungsFelderHinzu |
| showExerciseHint | zeigeUebungsHinweis |
| clearExerciseParamFields | loescheUebungsParameterFelder |
| buildExerciseParamFields | baueUebungsParameterFelder |
| addExerciseChoiceField | fuegeAuswahlFeldHinzu |
| buildExercisePreview | baueUebungsVorschau |
| readExerciseParams | liesUebungsParameter |
| scheduleExercisePreview | planeUebungsVorschau |
| refreshExercisePreview | erneuereUebungsVorschau |
| showExerciseExample | zeigeUebungsBeispiel |

### CSV-Import
| alt | neu |
| --- | --- |
| openImportDialog | oeffneImportDialog |
| buildImportPanel | baueImportBereich |
| chooseImportFile | waehleImportDatei |
| showImportProblem | zeigeImportProblem |
| checkImportFile | pruefeImportDatei |
| uploadImport | ladeImportHoch |
| renderImportResult | zeichneImportErgebnis |
| importRowMessage | importZeilenMeldung |
| buildImportTable | baueImportTabelle |
| runImport | starteImport |
| importMessage | importFehlerText |

### Navigation und Kopfzeile
| alt | neu |
| --- | --- |
| isOwnViewLink | istEigeneAdresse |
| clearOverlaysForNavigation | raeumeOverlaysAb |
| showViewFromUrl | zeigeAnsichtAusAdresse |
| wireNavigation | verdrahteNavigation |
| storedLocale | gespeicherteSprache |
| init | starte |
| routeFromUrl | leseKategorieAusAdresse |
| showHeadActions | zeigeKopfAktionen |
| wireHeadActions | verdrahteKopfAktionen |
| wholeNumberInRange | ganzeZahlImBereich |

---

## 3. Ablauf nach der Freigabe

1. Eine Datei umbenennen, danach sofort: `php -l` beziehungsweise `node --check`,
   Seite laden, Handprüfung.
2. Reihenfolge (jede Stufe für sich testbar):
   `src/helpers/` → `src/config/` → `src/services/` (ohne `exercise_*`) →
   `public/api/` → `src/services/exercise_*` (der dynamische Sonderfall) →
   `bin/` → `public/assets/js/app.js`.
3. Alles, was die Namen als Text enthält, mitziehen: `docs/` (diese Tabelle,
   `technik.md`, `project-brief.md`), Kommentare und die Aufrufe in `bin/`.
4. Nach jeder Stufe die komplette Handprüfung aus `docs/verification.md`.

## 4. Zu bestätigen

1. **Namen so übernehmen?** (Tabelle oben)
2. **`t`, `el`, `pad2`** bleiben kurz — einverstanden?
3. **`bin/import_english_csv.php`** ist schon deutsch: unverändert lassen oder die
   drei kurzen Namen sprechender machen?
4. **Sprechende Variablen** werden in derselben Runde mit umbenannt (z. B.
   `$cards` → `$karten`, `$row` → `$zeile`, `$statement` → `$abfrage`,
   `cardId` → `kartenId`, `dueCount` → `faelligeAnzahl`). Lokale Kurzvariablen in
   Schleifen (`i`, `e`, `x`) bleiben.

