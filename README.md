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
     - *höchstens so gross* (Voreinstellung): einzelne Gruppen kleiner (10 Personen, 3er → 3, 3, 2, 2),
     - *mindestens so gross*: übrige Personen werden verteilt (11 Personen, 4er → 6, 5).
   - Gleiche Abläufe wie bei Methode A: Zufallszuteilung (Vorschau), „Los“,
     Freigabe zurücknehmen, neue Kursmitglieder zuteilen.
5. **Methode C – First Come – First Serve** (umgesetzt)
   - Die Dozierenden legen Anzahl Gruppen und Gruppengrösse (= Plätze pro Gruppe) fest.
   - **„Los – Einschreibung öffnen“** erstellt die Gruppen; die Studierenden
     schreiben sich selbst ein – wer zuerst kommt, erhält den Platz.
   - Optional **Warteliste**: Ist eine Gruppe voll, kommen Studierende auf die
     Warteliste; meldet sich jemand ab, rückt die erste Person automatisch nach.
   - Nach der Vergabe können die **Plätze pro Gruppe einzeln geändert** werden;
     frei gewordene Plätze werden sofort aus der Warteliste besetzt.
   - Einschreiben, Abmelden und Plätze ändern laufen gesperrt (`ilAtomQuery`),
     damit derselbe letzte Platz nicht zweimal vergeben wird.
6. **Personen von Hand verschieben oder entfernen** (alle Methoden): In der
   Detailansicht unter den Gruppen („Person verschieben oder entfernen“). Bei
   Methode C wird ein frei gewordener Platz automatisch aus der Warteliste
   besetzt; Dozierende dürfen volle Gruppen überbelegen.
7. **Methode D – Anmeldung mit Prioritäten** (umgesetzt)
   - Einstellungen: Gruppen oder Themen, Plätze pro Gruppe, Anzahl Wünsche pro
     Person (1–10), optionaler Anmeldeschluss.
   - **„Anmeldung öffnen“**: Die Studierenden geben ihre Wünsche in der
     Reihenfolge ihrer Priorität an (nur der 1. Wunsch ist Pflicht) und können
     sie bis zum Anmeldeschluss ändern oder zurückziehen. Der Zeitpunkt der
     Anmeldung spielt keine Rolle.
   - **„Anmeldung schliessen und zuteilen“**: Ein Algorithmus verteilt die
     Plätze (`ilGrmPriorityAssigner`, Min-Cost-Flow): zuerst erhalten möglichst
     viele Personen einen Platz, dann wird die Summe der quadrierten Prioritäten
     (1, 4, 9, …) minimiert – lieber zwei 2. Wünsche als ein 1. und ein 3. Wunsch.
     Gleichstände werden zufällig aufgelöst. Die Zuteilung ist beweisbar optimal
     (geprüft gegen eine vollständige Suche).
   - **Nachkorrekturmodus** (nur für Dozierende sichtbar): Statistik
     („1. Wunsch: 8 · 2. Wunsch: 4 · ohne Platz: 1“), Gruppen mit dem Rang des
     erfüllten Wunsches pro Person, Personen ohne Platz mit ihren Wünschen,
     Plätze pro Gruppe ändern, Personen verschieben, „Freie Plätze auffüllen“,
     „Zuteilung neu berechnen“, „Anmeldung wieder öffnen“.
   - **„Los“** gibt die Einteilung frei; Studierende sehen ihre Gruppe und
     welchen Wunsch sie erhalten haben.
8. **Methode E – Einschreibung mit automatisch neuen Gruppen** (umgesetzt)
   - Die Dozierenden legen die Gruppengrösse (= Plätze pro Gruppe) fest.
   - **„Los – Einschreibung öffnen“** erstellt „Gruppe 1“. Die Studierenden
     schreiben sich mit einem Klick ein (ohne Gruppenwahl) und kommen in die
     erste Gruppe mit freiem Platz.
   - Ist eine Gruppe voll, ist sie für die Einschreibung geschlossen; die nächste
     Person eröffnet automatisch eine neue Gruppe („Gruppe 2“, „Gruppe 3“ …,
     benannt wie die erste Gruppe, also in der Sprache der Dozierenden).
   - Meldet sich jemand ab, wird der Platz frei und geht an die nächste Person,
     die sich einschreibt. Die Plätze können pro Gruppe geändert werden (0 Plätze =
     Gruppe nimmt niemanden mehr auf).
   - Einschreiben und das Eröffnen neuer Gruppen laufen gesperrt (`ilAtomQuery`).
9. **Methode F – Feste Gruppen mit Tauschbörse** (umgesetzt)
   - Einteilung wie Methode A (Anzahl Gruppen oder Themen, Zufallszuteilung,
     „Los“).
   - Nach „Los“ ist die **Tauschbörse** offen (Einstellung „Tauschbörse offen“,
     danach in der Detailansicht mit „Tauschbörse schliessen/öffnen“ umschaltbar):
     - Studentin A (Gruppe 1) trägt einen **Tauschwunsch** ein: eine oder mehrere
       Gruppen, in die sie wechseln möchte, optional mit Bemerkung (z. B. „Montags
       habe ich keine Zeit“).
     - Die Personen der gewünschten Gruppen sehen das Angebot („Platz in
       Gruppe 1“, ohne Namen) und können es mit **„Tausch annehmen“** zusagen:
       Die beiden Plätze werden getauscht, die Gruppengrössen bleiben gleich.
     - Passen zwei Tauschwünsche zusammen (1 → 2 und 2 → 1), wird sofort
       automatisch getauscht.
     - Tauschwünsche können zurückgezogen werden; wer verschoben wird, verliert
       seinen Tauschwunsch.
   - Die Dozierenden sehen die offenen Tauschwünsche (mit Namen) und ein
     Protokoll der durchgeführten Tauschvorgänge.
   - Alle Tauschvorgänge laufen gesperrt (`ilGrmSwapMarket`, `ilAtomQuery`):
     Ein Angebot kann nicht zweimal angenommen werden.
10. **Methoden G–J** sind in der Auswahl sichtbar, aber noch deaktiviert
    („in Vorbereitung“).

### Rechte

Beim Anlegen eines Gruppenmanagement-Objekts setzt das Plugin Standardrechte
für die Kursrollen, sofern eine Rolle noch gar keine Rechte darauf hat
(Mitglieder: sehen/lesen, Tutoren: zusätzlich bearbeiten, Kursadministratoren:
alles). Sonst würden Kursmitglieder das Objekt nicht sehen, weil die
Rollenvorlagen der Kurse den Objekttyp des Plugins nicht kennen. Für ältere
Objekte zeigt die Inhaltsseite den Knopf „Standardrechte setzen“.

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
| `classes/class.ilGrmRandomAssigner.php` | Zufallsalgorithmus (Methoden A, B, F) |
| `classes/class.ilGrmEnrollment.php` | Einschreiben (C, E), Warteliste, Plätze, Verschieben – gesperrt (`ilAtomQuery`) |
| `classes/class.ilGrmSwapMarket.php` | Tauschbörse: Tauschwünsche, Tausch, Protokoll – gesperrt (Methode F) |
| `classes/class.ilGrmPriorityAssigner.php` | Zuteilung nach Prioritäten (Methode D) |
| `classes/class.ilGrmPriorityRegistration.php` | Wünsche speichern/zurückziehen, Anmeldung schliessen – gesperrt (Methode D) |
| `classes/class.ilGrmPermissions.php` | Standardrechte der Kursrollen |
| `classes/class.ilGrmCourseContext.php` | Ermittelt übergeordneten Kurs und Mitglieder |
| `sql/dbupdate.php` | Datenbanktabellen |
| `lang/` | Sprachdateien Deutsch/Englisch |
| `tests/` | PHPUnit-Tests für die Algorithmen |
| `dev/` | Entwicklung: `grm`-Skript, Test-ILIAS (`dev/test-ilias`) |

### Status eines Gruppenmanagements

`draft` (noch nicht ausgelost) → `drawn` (ausgelost, nur Dozierende) →
`published` (nach „Los“, für Studierende sichtbar). Methode D hat zusätzlich
`open` (Anmeldung offen) zwischen `draft` und `drawn`. Bei den Methoden C und E
bedeutet `published` „Einschreibung offen“.

### Weitere Methoden hinzufügen (G–J)

1. Methode in `ilGrmMethods::IMPLEMENTED` eintragen.
2. Methodenspezifische Einstellungen im Formular
   (`ilObjGroupManagementGUI::initManagementForm`) und die Logik ergänzen.
3. Sprachvariablen `method_x` / `method_x_info` anpassen.

## Tests

```bash
./vendor/composer/vendor/bin/phpunit public/Customizing/global/plugins/Services/Repository/RepositoryObject/GroupManagement/tests
```
