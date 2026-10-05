-- ============================================================================
-- card_variants.sql - Satzvarianten einer Grammatik-Karte
--
-- Legt genau eine neue Tabelle an: `card_variants`. Sonst passiert nichts. Es
-- wird keine bestehende Tabelle, keine Spalte und keine Zeile geaendert, und es
-- wird nichts geloescht. Ausgefuehrt wird die Datei von Hand:
--
--   mysql -u BENUTZER -p study < database/card_variants.sql
--
-- In phpMyAdmin: die Datenbank waehlen, Reiter "SQL", den Inhalt einfuegen und
-- ausfuehren.
--
-- WARUM EINE EIGENE TABELLE
--
-- Eine Grammatik-Karte ist eine Regel ("much + uncountable noun"). Gezeigt wird
-- sie als einer von mehreren Beispielsaetzen - mehrere Wege zur selben Regel,
-- damit niemand einen einzelnen Satz auswendig lernt. Eine Karte hat deshalb
-- null bis n Varianten, und eine Karte ohne Varianten bleibt genau die Karte,
-- die sie vorher war.
--
-- Fuenf Spaltenpaare in `cards` waeren der andere Weg gewesen. Dagegen spricht:
-- sie waeren bei jeder der uebrigen Karten leer (die Tabelle hat ueber 7500
-- Zeilen), und eine sechste Variante braeuchte wieder eine Schemaaenderung. Eine
-- Zeile je Variante waechst dagegen mit.
--
-- WARUM DER FORTSCHRITT HIER NICHT HAENGT
--
-- Es gibt bewusst keine Spalte in `user_card_progress` und keinen Fremdschluessel
-- von dort hierher. Gelernt wird die Karte, nicht der Satz: eine falsche Antwort
-- auf Variante 3 zaehlt fuer die Karte, und beim naechsten Anzeigen darf Variante
-- 4 kommen. Wuerde der Fortschritt je Variante liegen, waere dieselbe Regel fuenf
-- Karten - genau das soll verhindert werden.
--
-- WAS DIE TABELLE NICHT ENTHAELT
--
-- `front` und `back` fehlen absichtlich: die Importdatei traegt sie, sie sind
-- dort aber Zeichen fuer Zeichen dieselben wie `front_de` und `back_de`. Eine
-- zweite Kopie desselben Satzes waere nur eine Stelle mehr, an der etwas
-- auseinanderlaufen kann. `category` und `rule_key` aus der Datei stehen hier
-- ebenfalls nicht: die Karten liegen in einer gemeinsamen Kategorie, und die
-- Regel eines Satzes ist aus dem Satz selbst nicht ablesbar - beides gehoert
-- nicht in eine Zeile, die nur Textvarianten haelt.
--
-- ON DELETE CASCADE, WARUM
--
-- Die Varianten gehoeren der Karte. Wird eine Karte geloescht - etwa wenn eine
-- Liste mit --replace neu gefuellt wird -, verschwinden ihre Varianten mit. Ohne
-- das blieben Varianten ohne Karte stehen, und die naechste Anzeige muesste
-- daran denken, sie zu ueberspringen.
--
-- WARUM (card_id, variant_number) EINDEUTIG IST
--
-- Und nicht `variant_key`: derselbe Schluessel "G001-V1" kommt bei jedem Konto
-- wieder vor, das seine eigene Kopie desselben Baums hat (siehe
-- categories.owner_user_id). Ein eindeutiger variant_key wuerde den Import fuer
-- das zweite Konto mit einem Schluesselkonflikt abbrechen. Die Nummer innerhalb
-- der Karte ist dagegen genau das, was sich nicht wiederholen darf.
--
-- Der eindeutige Schluessel auf (card_id, variant_number) faengt mit card_id an
-- und ist damit gleichzeitig der Index, den der Fremdschluessel braucht. Ein
-- zweiter Index auf card_id allein waere derselbe Baum ein zweites Mal.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `card_variants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `card_id` int unsigned NOT NULL COMMENT 'Die Karte, deren Variante das ist',
  `variant_number` tinyint unsigned NOT NULL COMMENT 'Die Nummer der Variante innerhalb der Karte, 1 bis n',
  `variant_key` varchar(32) NOT NULL COMMENT 'Der Schluessel aus der Importdatei, etwa G001-V1',
  `front_de` text DEFAULT NULL,
  `back_de` text DEFAULT NULL,
  `front_en` text DEFAULT NULL,
  `back_en` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_card_variants_number` (`card_id`,`variant_number`),
  CONSTRAINT `fk_variants_card` FOREIGN KEY (`card_id`) REFERENCES `cards` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- Kontrolle
--
-- Erwartet: die Tabelle mit den acht Spalten, dem eindeutigen Schluessel
-- uniq_card_variants_number und dem Fremdschluessel fk_variants_card, und
-- darunter die Zahl 0 (vor dem ersten Import ist noch keine Variante da).
-- ============================================================================

SHOW CREATE TABLE `card_variants`;

SELECT COUNT(*) AS varianten FROM `card_variants`;
