# GroupManagement – Projektregeln

ILIAS Repository-Objekt-Plugin (ID `xgrm`). Ziel-Versionen: ILIAS 9, 10 und 11
(lokale Testumgebung des Auftraggebers: ILIAS 11 in Docker).

## Sprachdateien

- **Jeder neue oder geänderte Text kommt immer in beide Dateien:**
  `lang/ilias_de.lang` und `lang/ilias_en.lang`. Die Schlüssel beider Dateien
  müssen identisch sein (gleiche Reihenfolge).
- Deutsch ohne englische Begriffe (z.B. „Los“ statt „Go“, „Sichtbar“ statt „Online“).
  Schweizer Schreibweise (ss statt ß).
- Kein Text fest im PHP-Code; Plugin-Texte über `$this->txt("key")`.

Prüfen:

```bash
diff <(cut -d'#' -f1 lang/ilias_de.lang) <(cut -d'#' -f1 lang/ilias_en.lang)
```

## Oberfläche

- Löschen und andere Rückfragen wie im ILIAS-Kern: Interruptive Modal
  (Overlay), siehe `buildDeleteModal()` in `ilObjGroupManagementGUI`.
- Nur UI-Aufrufe verwenden, die in ILIAS 9, 10 und 11 gleich sind
  (z.B. kein `$f->legacy(...)`, dessen Signatur sich in ILIAS 11 geändert hat).

## Datenbank

- Neue DB-Schritte in `sql/dbupdate.php` anhängen und die Version in
  `plugin.php` erhöhen, sonst führt ILIAS sie nicht aus.
- Spaltennamen nicht aus der Liste reservierter Wörter (z.B. `status`).

## Entwickeln / Testen

- Der Auftraggeber testet mit `grm` (holt den Branch und lädt das Plugin im
  lokalen Docker-ILIAS neu), siehe `dev/README.md`.
- Vor jedem Push: `php -l` auf alle PHP-Dateien und den Sprachdatei-Check oben.
