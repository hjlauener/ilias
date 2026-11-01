# GroupManagement – ILIAS Repository-Objekt-Plugin

Plugin für [ILIAS](https://www.ilias.de) (Version 9), mit dem Dozierende die
Mitglieder eines Kurses in Gruppen einteilen.

## Konzept

1. **Repository-Objekt „Gruppenmanagement“** – wird in einem Kurs (oder in
   einem Ordner/einer Gruppe innerhalb eines Kurses) angelegt und nutzt die
   Standard-Rollen und -Rechte von ILIAS (Sichtbar, Lesen, Schreiben,
   Löschen, Rechte bearbeiten).
2. **Gruppenmanagements innerhalb des Objekts** – Dozierende (Schreibrecht)
   legen ein oder mehrere Gruppenmanagements an und wählen dabei eine
   **Methode a–j**. Jedes Gruppenmanagement kann aktiviert werden, es ist aber
   **immer höchstens eines aktiv**. Studierende sehen nur das aktive.
3. **Methode A – Zufallszuteilung durch Dozierende** (umgesetzt)
   - Die Mitglieder des übergeordneten Kurses (Rolle *Mitglied*, ohne
     Kursadministratoren und Tutoren) werden zufällig und gleichmässig
     (Gruppengrössen unterscheiden sich um höchstens 1) eingeteilt in
     - eine **feste Anzahl Gruppen** („Gruppe 1 … Gruppe n“) oder
     - eine **feste Anzahl Themen** (ein Thema pro Zeile, pro Thema eine Gruppe).
   - „Zufallszuteilung durchführen“ erstellt eine Vorschau, die nur Dozierende
     sehen (beliebig oft neu auslosbar).
   - **„Go“** gibt die Einteilung frei – erst dann ist sie für Studierende
     sichtbar. Falls noch nicht ausgelost wurde, lost „Go“ zuerst aus; ein
     inaktives Gruppenmanagement wird dabei aktiviert.
   - „Freigabe zurücknehmen“ verbirgt die Einteilung wieder.
   - „Neue Kursmitglieder zuteilen“ verteilt später beigetretene Mitglieder
     auf die jeweils kleinsten Gruppen, ohne bestehende Zuteilungen zu ändern.
   - Option „Studierende sehen alle Gruppen“ (sonst nur die eigene Gruppe).
4. **Methoden B–J** sind in der Auswahl sichtbar, aber noch deaktiviert
   („in Vorbereitung“).

## Installation

```bash
mkdir -p Customizing/global/plugins/Services/Repository/RepositoryObject
cd Customizing/global/plugins/Services/Repository/RepositoryObject
git clone <repo-url> GroupManagement
```

Danach im ILIAS-Hauptverzeichnis:

```bash
composer du
```

```bash
php setup/setup.php update
```

Dann unter *Administration → Erweiterungen → Plugins* das Plugin
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
`published` (nach „Go“, für Studierende sichtbar).

### Weitere Methoden hinzufügen (B–J)

1. Methode in `ilGrmMethods::IMPLEMENTED` eintragen.
2. Methodenspezifische Einstellungen im Formular
   (`ilObjGroupManagementGUI::initManagementForm`) und die Logik ergänzen.
3. Sprachvariablen `method_x` / `method_x_info` anpassen.

## Tests

```bash
./libs/composer/vendor/bin/phpunit Customizing/global/plugins/Services/Repository/RepositoryObject/GroupManagement/tests
```
