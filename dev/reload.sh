#!/usr/bin/env bash
#
# GroupManagement – Entwicklungs-Helfer für eine lokale ILIAS-Docker-Installation
#
#   ./dev/reload.sh            Code ins ILIAS bringen und Plugin neu laden
#   ./dev/reload.sh --pull     vorher den neusten Stand von GitHub holen (git pull)
#   ./dev/reload.sh --watch    bei jeder Dateiänderung automatisch neu laden (braucht fswatch)
#   ./dev/reload.sh install    Plugin das erste Mal installieren und aktivieren
#   ./dev/reload.sh status     gefundene Konfiguration anzeigen
#   ./dev/reload.sh logs       Docker-Logs des ILIAS-Containers verfolgen
#   ./dev/reload.sh shell      Shell im ILIAS-Container öffnen
#
# Einstellungen (optional) in dev/config.env – siehe dev/config.env.example.
# Läuft mit der macOS-Standard-Bash (3.2).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="$SCRIPT_DIR/config.env"

PLUGIN_NAME="GroupManagement"
PLUGIN_SUBPATH="Customizing/global/plugins/Services/Repository/RepositoryObject/$PLUGIN_NAME"

# Defaults (können per config.env oder Umgebungsvariable überschrieben werden)
CONTAINER="${CONTAINER:-}"           # Name des ILIAS-Containers (leer = automatisch suchen)
ILIAS_DIR="${ILIAS_DIR:-}"           # ILIAS-Verzeichnis im Container (leer = automatisch suchen)
WEB_USER="${WEB_USER:-www-data}"     # Benutzer des Webservers im Container
SYNC_MODE="${SYNC_MODE:-auto}"       # auto | mount | copy
ILIAS_URL="${ILIAS_URL:-}"           # z.B. http://localhost:8080 – wird nach dem Reload geöffnet
OPEN_BROWSER="${OPEN_BROWSER:-0}"    # 1 = Browser nach dem Reload öffnen

# shellcheck disable=SC1090
[ -f "$CONFIG_FILE" ] && . "$CONFIG_FILE"

CANDIDATE_DIRS="/var/www/html /var/www/html/ilias /var/www/ilias /var/www /srv/www/ilias /app /opt/ilias"

# ----------------------------------------------------------------------
# Ausgabe
# ----------------------------------------------------------------------
if [ -t 1 ]; then
    C_BLUE=$'\033[1;34m'; C_GREEN=$'\033[1;32m'; C_YELLOW=$'\033[1;33m'; C_RED=$'\033[1;31m'; C_OFF=$'\033[0m'
else
    C_BLUE=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_OFF=""
fi
step() { printf '%s==>%s %s\n' "$C_BLUE" "$C_OFF" "$*"; }
ok()   { printf '%s ✔ %s %s\n' "$C_GREEN" "$C_OFF" "$*"; }
warn() { printf '%s ! %s %s\n' "$C_YELLOW" "$C_OFF" "$*" >&2; }
die()  { printf '%s ✘ %s %s\n' "$C_RED" "$C_OFF" "$*" >&2; exit 1; }

# ----------------------------------------------------------------------
# Docker / Container
# ----------------------------------------------------------------------
check_docker() {
    command -v docker >/dev/null 2>&1 || die "docker wurde nicht gefunden. Läuft Docker Desktop?"
    docker info >/dev/null 2>&1 || die "Docker läuft nicht. Bitte Docker Desktop starten."
}

container_has_ilias() { # $1 container, $2 dir
    docker exec "$1" test -f "$2/ilias.ini.php" >/dev/null 2>&1
}

find_ilias_dir_in() { # $1 container -> gibt Verzeichnis aus
    local dir
    if [ -n "$ILIAS_DIR" ]; then
        container_has_ilias "$1" "$ILIAS_DIR" && { echo "$ILIAS_DIR"; return 0; }
        return 1
    fi
    for dir in $CANDIDATE_DIRS; do
        if container_has_ilias "$1" "$dir"; then
            echo "$dir"
            return 0
        fi
    done
    return 1
}

detect_container() {
    local name dir found=""
    if [ -n "$CONTAINER" ]; then
        docker ps --format '{{.Names}}' | grep -qx "$CONTAINER" \
            || die "Container '$CONTAINER' läuft nicht (siehe 'docker ps')."
        dir="$(find_ilias_dir_in "$CONTAINER")" \
            || die "Im Container '$CONTAINER' wurde kein ILIAS gefunden. Bitte ILIAS_DIR in dev/config.env setzen."
        ILIAS_DIR="$dir"
        return
    fi

    for name in $(docker ps --format '{{.Names}}'); do
        if dir="$(find_ilias_dir_in "$name")"; then
            if [ -z "$found" ]; then
                found="$name"
                ILIAS_DIR="$dir"
            else
                warn "Mehrere ILIAS-Container gefunden ($found, $name) – verwende '$found'. Mit CONTAINER=... in dev/config.env festlegen."
            fi
        fi
    done
    [ -n "$found" ] || die "Kein laufender ILIAS-Container gefunden. Läuft ILIAS? Sonst CONTAINER und ILIAS_DIR in dev/config.env setzen."
    CONTAINER="$found"
}

check_web_user() {
    if ! docker exec "$CONTAINER" id -u "$WEB_USER" >/dev/null 2>&1; then
        warn "Benutzer '$WEB_USER' existiert im Container nicht – verwende root. (WEB_USER in dev/config.env anpassen)"
        WEB_USER="root"
    fi
}

# Befehl im ILIAS-Verzeichnis des Containers als Webserver-Benutzer ausführen
in_ilias() {
    docker exec -u "$WEB_USER" -w "$ILIAS_DIR" -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp "$CONTAINER" "$@"
}

in_ilias_root() {
    docker exec -u 0 -w "$ILIAS_DIR" "$CONTAINER" "$@"
}

# Plugin-Verzeichnis im Container (bricht ab, falls ILIAS_DIR leer ist)
target_dir() { echo "${ILIAS_DIR:?ILIAS_DIR ist leer}/$PLUGIN_SUBPATH"; }

setup_context() {
    check_docker
    detect_container
    check_web_user
}

# ----------------------------------------------------------------------
# Code in den Container bringen
# ----------------------------------------------------------------------
is_mounted() {
    local marker=".mount-check-$$-$RANDOM"
    local marker_path="${PLUGIN_DIR:?}/${marker:?}"
    local result=1
    touch "$marker_path"
    if docker exec "$CONTAINER" test -f "$(target_dir)/$marker" >/dev/null 2>&1; then
        result=0
    fi
    rm -f "$marker_path"
    return $result
}

sync_code() {
    local mode="$SYNC_MODE" target
    if [ "$mode" = "auto" ]; then
        if is_mounted; then mode="mount"; else mode="copy"; fi
    fi

    if [ "$mode" = "mount" ]; then
        ok "Plugin-Ordner ist in den Container gemountet – Änderungen sind sofort sichtbar."
        return
    fi

    target="$(target_dir)"
    case "$target" in
        */RepositoryObject/"$PLUGIN_NAME") ;;
        *) die "Unerwarteter Zielordner: $target" ;;
    esac

    step "Kopiere Plugin nach $CONTAINER:$target"
    in_ilias_root mkdir -p "$target"
    # alten Stand im Container entfernen (nur Inhalt des Plugin-Ordners)
    in_ilias_root find "$target" -mindepth 1 -delete
    # COPYFILE_DISABLE verhindert macOS-Metadaten (._*-Dateien)
    COPYFILE_DISABLE=1 tar -C "$PLUGIN_DIR" \
        --exclude=.git --exclude='./dev/config.env' --exclude='.DS_Store' --exclude='.mount-check-*' \
        -cf - . | docker exec -i -u 0 "$CONTAINER" tar -xf - -C "$target" 2>/dev/null \
        || die "Kopieren fehlgeschlagen."
    if [ "$WEB_USER" != "root" ]; then
        in_ilias_root chown -R "$WEB_USER" "$target"
    fi
    ok "Code kopiert."
}

# ----------------------------------------------------------------------
# Neu laden
# ----------------------------------------------------------------------
lint_php() {
    step "Prüfe PHP-Syntax"
    local out
    out="$(in_ilias sh -c "find '$(target_dir)/' -name '*.php' -not -path '*/vendor/*' -exec php -l {} \; 2>&1" | grep -v '^No syntax errors' || true)"
    if [ -n "$out" ]; then
        printf '%s\n' "$out" >&2
        die "PHP-Syntaxfehler – bitte beheben."
    fi
    ok "Syntax ok."
}

dump_autoload() {
    step "Aktualisiere Autoloader (composer dump-autoload)"
    if in_ilias sh -c 'command -v composer' >/dev/null 2>&1; then
        if in_ilias composer dump-autoload --quiet; then
            ok "Autoloader aktualisiert."
        else
            warn "composer dump-autoload fehlgeschlagen – neue Klassen werden evtl. nicht gefunden."
        fi
    else
        warn "composer ist im Container nicht installiert – neue Klassen werden evtl. nicht gefunden."
    fi
}

build_artifacts() {
    step "Erzeuge ILIAS-Artifacts (Plugin-Infos, ilCtrl-Struktur)"
    in_ilias php setup/setup.php build-artifacts --yes >/dev/null \
        || die "build-artifacts fehlgeschlagen. Details: ./dev/reload.sh shell → php setup/setup.php build-artifacts"
    ok "Artifacts erzeugt."
}

update_plugin() {
    step "Aktualisiere Plugin (DB-Schritte, Sprachen, Aktivierung)"
    local out
    if out="$(in_ilias php setup/setup.php update --plugin "$PLUGIN_NAME" --yes 2>&1)"; then
        ok "Plugin aktualisiert."
    else
        printf '%s\n' "$out" >&2
        warn "Plugin-Update fehlgeschlagen. Ist das Plugin schon installiert? → ./dev/reload.sh install"
        return 1
    fi
}

open_browser() {
    if [ "$OPEN_BROWSER" = "1" ] && [ -n "$ILIAS_URL" ] && command -v open >/dev/null 2>&1; then
        open "$ILIAS_URL"
    fi
}

do_reload() {
    local started
    started="$(date +%s)"
    sync_code
    lint_php
    dump_autoload
    build_artifacts
    update_plugin || true
    ok "Fertig in $(( $(date +%s) - started ))s – Seite im Browser neu laden."
}

do_install() {
    sync_code
    lint_php
    dump_autoload
    build_artifacts
    step "Installiere und aktiviere Plugin"
    if in_ilias php setup/setup.php install --plugin "$PLUGIN_NAME" --yes; then
        ok "Plugin installiert und aktiviert."
    else
        warn "Installation per Kommandozeile fehlgeschlagen."
        warn "Alternative: in ILIAS unter Administration → Erweiterungen → Plugins → GroupManagement → Installieren, dann Aktivieren."
        exit 1
    fi
}

do_watch() {
    command -v fswatch >/dev/null 2>&1 || die "fswatch fehlt. Installieren mit: brew install fswatch"
    do_reload
    step "Beobachte $PLUGIN_DIR – Abbrechen mit Ctrl+C"
    fswatch -o -l 1 -e '/\.git/' -e '\.mount-check-' -e '/dev/config\.env$' "$PLUGIN_DIR" | while read -r _; do
        echo
        step "Änderung erkannt ($(date +%H:%M:%S))"
        ( do_reload ) || warn "Reload fehlgeschlagen – warte auf nächste Änderung."
    done
}

do_pull() {
    step "Hole neusten Stand von GitHub"
    git -C "$PLUGIN_DIR" pull --ff-only || die "git pull fehlgeschlagen (lokale Änderungen?)."
    ok "Code ist aktuell: $(git -C "$PLUGIN_DIR" log -1 --format='%h %s')"
}

do_status() {
    echo "Plugin-Ordner (Mac): $PLUGIN_DIR"
    echo "Container:           $CONTAINER"
    echo "ILIAS-Verzeichnis:   $ILIAS_DIR"
    echo "Plugin im Container: $(target_dir)"
    echo "Webserver-Benutzer:  $WEB_USER"
    if is_mounted; then echo "Sync-Modus:          mount (live)"; else echo "Sync-Modus:          copy"; fi
    echo "Git-Branch:          $(git -C "$PLUGIN_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '-')"
}

# ----------------------------------------------------------------------
# Main
# ----------------------------------------------------------------------
PULL=0
WATCH=0
CMD="reload"
for arg in "$@"; do
    case "$arg" in
        --pull) PULL=1 ;;
        --watch) WATCH=1 ;;
        --open) OPEN_BROWSER=1 ;;
        reload|install|status|logs|shell) CMD="$arg" ;;
        -h|--help) sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Unbekannte Option: $arg (siehe --help)" ;;
    esac
done

if [ "$PULL" = "1" ]; then do_pull; fi
setup_context

case "$CMD" in
    status) do_status ;;
    logs) docker logs -f --tail 100 "$CONTAINER" ;;
    shell) docker exec -it -u "$WEB_USER" -w "$ILIAS_DIR" "$CONTAINER" sh -c 'command -v bash >/dev/null && exec bash || exec sh' ;;
    install) do_install; open_browser ;;
    reload)
        if [ "$WATCH" = "1" ]; then
            do_watch
        else
            do_reload
            open_browser
        fi
        ;;
esac
