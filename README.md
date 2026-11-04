# GroupManagement – ILIAS Repository-Objekt-Plugin

Plugin für [ILIAS](https://www.ilias.de) (Version 9, 10 und 11), mit dem Dozierende die
Mitglieder eines Kurses in Gruppen einteilen.

## Konzept

1. **Repository-Objekt „Gruppenmanagement“** – wird in einem Kurs (oder in
   einem Ordner/einer Gruppe innerhalb eines Kurses) angelegt und nutzt die
   Standard-Rollen und -Rechte von ILIAS (Sichtbar, Lesen, Schreiben,
   Löschen, Rechte bearbeiten).
2. **Gruppenmanagements innerhalb des Objekts** – Dozierende (Schreibrecht)
   legen ein oder mehrere Gruppenmanagements an und wählen dabei eine
   **Methode a–j** (pro Gruppenmanagement genau eine Methode). Gruppenmanagements
   werden mit einem Umschalter aktiviert/deaktiviert; **mehrere können gleichzeitig
   aktiv sein**. Studierende sehen nur aktive.
   - Studierende sehen zuerst eine **Übersicht** der aktiven Gruppenmanagements
     (mit ihrer Gruppe, sobald freigegeben). Ein Klick auf den Namen öffnet die
     Einteilung; die **eigene Gruppe steht abgehoben zuoberst**.
3. **Methode A – Zufallszuteilung durch Dozierende** (umgesetzt)
   - Die Mitglieder des übergeordneten Kurses (Rolle *Mitglied*, ohne
     Kursadministratoren und Tutoren) werden zufällig und gleichmässig
     (Gruppengrössen unterscheiden sich um höchstens 1) eingeteilt in
     - eine **feste Anzahl Gruppen** („Gruppe 1 … Gruppe n“) oder
     - eine **feste Anzahl Themen** (ein Thema pro Zeile, pro Thema eine Gruppe).
   - „Zufallszuteilung durchführen“ erstellt eine Vorschau, die nur Dozierende
     sehen (beliebig oft neu auslosbar).
   - **„Los“** gibt die Einteilung frei – erst dann ist sie für Studierende
     sichtbar. Falls noch nicht ausgelost wurde, lost „Los“ zuerst aus; ein
     inaktives Gruppenmanagement wird dabei aktiviert.
   - „Freigabe zurücknehmen“ verbirgt die Einteilung wieder.
   - „Neue Kursmitglieder zuteilen“ verteilt später beigetretene Mitglieder
     auf die jeweils kleinsten Gruppen, ohne bestehende Zuteilungen zu ändern.
   - Option „Studierende sehen alle Gruppen“ (sonst nur die eigene Gruppe).
4. **Methode B – Feste Gruppengrösse** (umgesetzt)
   - Die Dozierenden geben die Gruppengrösse ein (2er-, 3er-, 4er-Gruppen …);
     die Anzahl Gruppen ergibt sich aus der Anzahl Kursmitglieder.
   - Wenn es nicht aufgeht:
     - *möglichst genau die gewünschte Grösse* (Voreinstellung): einzelne
       Gruppen eine Person mehr oder weniger (11 Personen, 4er → 4, 4, 3),
     - *höchstens so gross*: einzelne Gruppen kleiner (10 Personen, 3er → 3, 3, 2, 2),
     - *mindestens so gross*: übrige Personen werden verteilt (11 Personen, 4er → 6, 5).
   - Gleiche Abläufe wie bei Methode A: Zufallszuteilung (Vorschau), „Los“,
     Freigabe zurücknehmen, neue Kursmitglieder zuteilen.
5. **Methoden C–J** sind in der Auswahl sichtbar, aber noch deaktiviert
   („in Vorbereitung“).

## Installation

Zielordner (ab ILIAS 10 liegt `Customizing` unter `public/`):

- ILIAS 10/11: `public/Customizing/global/plugins/Services/Repository/RepositoryObject/GroupManagement`
- ILIAS 9: `Customizing/global/plugins/Services/Repository/RepositoryObject/GroupManagement`

Danach im ILIAS-Hauptverzeichnis `composer du` ausführen und das Plugin installieren:

- ILIAS 10/11: `php cli/setup.php install --legacy-plugin GroupManagement`
- ILIAS 9: `php setup/setup.php install --plugin GroupManagement`

Alternativ unter *Administration → Erweiterungen → Plugins* das Plugin
„GroupManagement“ installieren und aktivieren. Anschliessend in den Kursen
(bzw. in den Rollenvorlagen) das Recht „Gruppenmanagement anlegen“ für die
gewünschten Rollen vergeben.

Neu angelegte Objekte sind **offline**; unter *Einstellungen* online schalten,
damit Studierende sie sehen.

## Lokale Entwicklung (ILIAS in Docker)

Siehe [`dev/README.md`](dev/README.md): ein Skript holt den neusten Stand und lädt
das Plugin im lokalen ILIAS neu (Doppelklick auf `dev/GroupManagement neu laden.command`).

## Aufbau

| Datei | Zweck |
| --- | --- |
| `plugin.php` | Plugin-Metadaten (ID `xgrm`) |
| `classes/class.ilGroupManagementPlugin.php` | Plugin-Klasse |
| `classes/class.ilObjGroupManagement*.php` | Repository-Objekt, GUI, ListGUI, Access |
| `classes/class.ilGrmManagement.php` | Ein Gruppenmanagement (Methode, Einstellungen, Status) |
| `classes/class.ilGrmMethods.php` | Register der Methoden a–j (welche umgesetzt sind) |
| `classes/class.ilGrmRepository.php` | Datenbankzugriff |
| `classes/class.ilGrmRandomAssigner.php` | Zufallsalgorithmus (Methode A) |
| `classes/class.ilGrmCourseContext.php` | Ermittelt übergeordneten Kurs und Mitglieder |
| `sql/dbupdate.php` | Datenbanktabellen |
| `lang/` | Sprachdateien Deutsch/Englisch |
| `tests/` | PHPUnit-Tests für Algorithmus und Einstellungen |

### Status eines Gruppenmanagements

`draft` (noch nicht ausgelost) → `drawn` (ausgelost, nur Dozierende) →
`published` (nach „Los“, für Studierende sichtbar).

### Weitere Methoden hinzufügen (C–J)

1. Methode in `ilGrmMethods::IMPLEMENTED` eintragen.
2. Methodenspezifische Einstellungen im Formular
   (`ilObjGroupManagementGUI::initManagementForm`) und die Logik ergänzen.
3. Sprachvariablen `method_x` / `method_x_info` anpassen.

## Tests

```bash
./vendor/composer/vendor/bin/phpunit public/Customizing/global/plugins/Services/Repository/RepositoryObject/GroupManagement/tests
```
