# Lokal entwickeln mit ILIAS in Docker (Mac)

## Einmalig einrichten

### 1. Repository auf den Mac holen

```bash
mkdir -p ~/Projekte && cd ~/Projekte
```

```bash
git clone -b claude/funny-gates-2ove6u https://github.com/hjlauener/ilias.git GroupManagement
```

### 2. Plugin-Ordner in den ILIAS-Container bringen

**Variante A – Live-Mount (empfohlen):** PHP-Änderungen sind sofort sichtbar.
Vorlage: `dev/docker-compose.override.example.yml`. Als
`docker-compose.override.yml` neben deine ILIAS-`docker-compose.yml` legen,
Service-Namen und Pfade anpassen und den Container neu starten:

```bash
docker compose up -d
```

**Variante B – ohne Mount:** nichts tun. Das Skript erkennt das und kopiert
den Code bei jedem Reload in den Container.

### 3. Plugin installieren

```bash
cd ~/Projekte/GroupManagement
```

```bash
./dev/reload.sh install
```

Falls die Installation per Kommandozeile scheitert: in ILIAS unter
*Administration → Erweiterungen → Plugins → GroupManagement* auf
*Installieren* und dann *Aktivieren* klicken.

Das Skript sucht den ILIAS-Container automatisch (den Container, in dem
`ilias.ini.php` liegt). Klappt das nicht, `dev/config.env.example` nach
`dev/config.env` kopieren und `CONTAINER` / `ILIAS_DIR` eintragen
(Containername siehe `docker ps`).

### 4. Testdaten in ILIAS

- Einen Kurs anlegen und ein paar Testbenutzer als **Mitglieder** aufnehmen.
- Im Kurs *Neues Objekt hinzufügen → Gruppenmanagement*.
- Unter *Einstellungen* auf **online** stellen.
- Zum Testen der Studierendensicht: mit einem Testbenutzer in einem privaten
  Browserfenster einloggen, oder im Objekt *Ansicht Studierende* öffnen.

## Täglicher Ablauf (zusammen mit Claude)

1. Claude ändert den Code und pusht auf den Branch.
2. Du klickst im Finder doppelt auf **`dev/GroupManagement neu laden.command`**.
   Das holt den neusten Stand (`git pull`) und lädt das Plugin im lokalen
   ILIAS neu.
   - Beim ersten Doppelklick fragt macOS evtl. nach: Rechtsklick → *Öffnen*.
3. Seite im Browser neu laden und testen. Rückmeldungen und Fehlermeldungen
   (Screenshot oder Text) an Claude schicken.

Wenn du selbst am Code arbeitest, lädt dieser Befehl bei jeder gespeicherten
Datei automatisch neu (vorher einmal `brew install fswatch`):

```bash
./dev/reload.sh --watch
```

## Was „neu laden“ macht

| Schritt | Wozu |
| --- | --- |
| Code kopieren (nur ohne Mount) | Code in den Container bringen |
| `php -l` auf alle Plugin-Dateien | Syntaxfehler sofort sehen statt weisser Seite |
| `composer dump-autoload` | neue Klassen werden gefunden |
| `php setup/setup.php build-artifacts` | ILIAS kennt Plugin-Version und neue GUI-Klassen (ilCtrl) |
| `php setup/setup.php update --plugin GroupManagement` | DB-Updates (bei neuer Version in `plugin.php`), Sprachdateien neu laden, Plugin aktiv halten |

## Weitere Befehle

```bash
./dev/reload.sh status
```

```bash
./dev/reload.sh logs
```

```bash
./dev/reload.sh shell
```

## Fehlersuche

- **Weisse Seite / Fehler 500:** `./dev/reload.sh logs` zeigt die PHP-Fehler
  (je nach Image). ILIAS schreibt Fehler ausserdem in sein Log-Verzeichnis
  (siehe `ilias.ini.php`, Abschnitt `[log]`).
- **„Plugin-Update fehlgeschlagen“** beim ersten Mal: Plugin ist noch nicht
  installiert → `./dev/reload.sh install`.
- **Sprachtexte erscheinen als `-rep_robj_xgrm_...-`:** neu laden. Hilft das
  nicht, unter *Administration → Plugins → GroupManagement → Sprachen
  aktualisieren*.
