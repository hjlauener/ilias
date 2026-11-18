# Test-ILIAS für automatische Tests

Eine ILIAS-11-Installation wie beim Auftraggeber (`srsolutions/ilias:11` +
`mariadb:10.11`), mit der Claude in einer Cloud-Sitzung das Plugin installieren
und im Browser (Playwright) durchklicken kann. **Nur zum Testen** – die
Passwörter in `docker-compose.yml` sind absichtlich einfach.

## Starten

```bash
docker compose -f dev/test-ilias/docker-compose.yml -p ilias up -d
```

Beim ersten Start installiert sich ILIAS selbst (einige Minuten). Danach ist es
unter http://localhost:8080 erreichbar (Benutzer `root`, Passwort `TestAdmin1234`).

Hinweis: Die Datenbank läuft mit `utf8` statt `utf8mb4`, sonst bricht die
ILIAS-Installation mit „Row size too large“ ab.

## Plugin installieren

```bash
GRM_NO_BROWSER=1 ./dev/reload.sh install
```

## Testdaten anlegen

Installiert Deutsch, legt die Dozentin `dozent` und 12 Studierende
(`anna`, `ben`, … `lea`, Passwort `Test1234!`) sowie den Kurs „Testkurs“ an:

```bash
docker cp dev/test-ilias/testdata.php ilias-ilias-1:/tmp/testdata.php
```

```bash
docker exec -u www-data -w /var/www/html ilias-ilias-1 php /tmp/testdata.php
```

Danach einmal `./dev/reload.sh`, damit auch die deutschen Plugin-Texte geladen
werden.

Neue Benutzer müssen in ILIAS beim ersten Anmelden das Passwort ändern; das
Skript schaltet das für die Testinstallation aus. Bereits angemeldete Benutzer
mit offenem Passwortwechsel: in der Datenbank `last_password_change` setzen.

## Rechte

Das Plugin setzt beim Anlegen eines Gruppenmanagement-Objekts Standardrechte für
die Kursrollen (Mitglieder: sehen/lesen). Für ältere Objekte zeigt die
Inhaltsseite einen Knopf „Standardrechte setzen“.
