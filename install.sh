#!/usr/bin/env bash
#
# Spielt beide Plugins in eine ILIAS-Installation ein.
#
#   ./install.sh /var/www/html
#   ./install.sh /var/www/html --docker ilias11-app
#
# Macht genau die drei Schritte, die Server-Zugang brauchen: Dateien an ihren
# Platz, Klassen bekannt machen, Verzeichnis einlesen. Installieren und
# Aktivieren geschieht danach in ILIAS unter Administration -> Plugins.
#
# WARUM ES DIESES SKRIPT GIBT
#
# Die drei Schritte sind einzeln unspektakulaer und zusammen fehleranfaellig:
# der Pfad ist nicht frei waehlbar, `build` fehlt gern, und beide Befehle
# brauchen Schreibrecht auf Verzeichnisse, die in gaengigen Abbildern root
# gehoeren. Faellt eines davon aus, erscheint das Plugin einfach nicht — ohne
# Fehlermeldung. `build` ist der schlimmste Fall: es meldet [OK] und schreibt
# trotzdem nichts.
#
# Deshalb prueft dieses Skript nach jedem Schritt, ob er gewirkt hat.

set -euo pipefail

ILIAS_PATH="${1:-}"
DOCKER_CONTAINER=""

if [ "${2:-}" = "--docker" ]; then
  DOCKER_CONTAINER="${3:-}"
  [ -n "$DOCKER_CONTAINER" ] || { echo "--docker braucht einen Containernamen" >&2; exit 2; }
fi

if [ -z "$ILIAS_PATH" ]; then
  cat >&2 <<'USAGE'
Aufruf:
  ./install.sh <ilias-verzeichnis> [--docker <container>]

Beispiele:
  ./install.sh /var/www/html
  ./install.sh /var/www/html --docker ilias11-app

Das ILIAS-Verzeichnis ist das, in dem ilias.ini.php und cli/setup.php liegen.
USAGE
  exit 2
fi

cd "$(dirname "$0")"
SRC="plugins/Services"

# In einem Container laufen die ILIAS-Befehle drinnen, die Dateien werden
# vorher hineinkopiert. Ohne Container laeuft alles direkt.
run_in_ilias() {
  if [ -n "$DOCKER_CONTAINER" ]; then
    docker exec "$DOCKER_CONTAINER" sh -lc "$1"
  else
    sh -lc "cd '$ILIAS_PATH' && $1"
  fi
}

say() { printf '\n== %s\n' "$1"; }
fail() { printf '\nFEHLGESCHLAGEN: %s\n' "$1" >&2; exit 1; }

# --- Vorpruefung -----------------------------------------------------------
say "ILIAS pruefen"
run_in_ilias "test -f '$ILIAS_PATH/cli/setup.php'" \
  || fail "In $ILIAS_PATH liegt kein cli/setup.php. Falsches Verzeichnis?"
VERSION=$(run_in_ilias "grep -o 'ILIAS_VERSION = \"[^\"]*\"' '$ILIAS_PATH/ilias_version.php' | head -1 | cut -d'\"' -f2" || true)
echo "   Version: ${VERSION:-unbekannt}"
case "$VERSION" in
  11.*) ;;
  "")   echo "   (Version nicht lesbar — weiter auf eigenes Risiko)" ;;
  *)    fail "Diese Fassung ist fuer ILIAS 11. Gefunden: $VERSION. Es gibt je Hauptversion einen eigenen Branch." ;;
esac

# --- 1. Dateien ------------------------------------------------------------
say "Dateien einspielen"
TARGET="$ILIAS_PATH/public/Customizing/global/plugins/Services"
run_in_ilias "mkdir -p '$TARGET/UIComponent/UserInterfaceHook' '$TARGET/Cron/CronHook'"

copy_plugin() {
  local src="$1" dst="$2" name="$3"
  if [ -n "$DOCKER_CONTAINER" ]; then
    tar -cf - -C "$(dirname "$src")" "$(basename "$src")" \
      | docker exec -i "$DOCKER_CONTAINER" sh -lc "rm -rf '$dst/$name' && tar -xf - -C '$dst'"
  else
    rm -rf "${dst:?}/$name"
    cp -r "$src" "$dst/"
  fi
  echo "   $name -> $dst/$name"
}

copy_plugin "$SRC/UIComponent/UserInterfaceHook/AlphabeesTutor" \
            "$TARGET/UIComponent/UserInterfaceHook" "AlphabeesTutor"
copy_plugin "$SRC/Cron/CronHook/AlphabeesTutorSync" \
            "$TARGET/Cron/CronHook" "AlphabeesTutorSync"

OWNER=$(run_in_ilias "stat -c '%U:%G' '$ILIAS_PATH/public' 2>/dev/null || echo www-data:www-data")
run_in_ilias "chown -R '$OWNER' '$TARGET'" || echo "   (chown uebersprungen)"
echo "   Eigentuemer: $OWNER"

# --- 2. Klassen ------------------------------------------------------------
say "Klassen bekannt machen (composer dump-autoload)"
run_in_ilias "cd '$ILIAS_PATH' && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload -o --no-scripts 2>&1 | tail -1" \
  || fail "composer dump-autoload fehlgeschlagen. Schreibrecht auf vendor/ pruefen."

CLASSMAP=$(run_in_ilias "grep -rl 'ilAlphabeesTutorPlugin' '$ILIAS_PATH/vendor' 2>/dev/null | head -1" || true)
[ -n "$CLASSMAP" ] || fail "Die Klassen stehen nicht im Classmap. Hat composer wirklich geschrieben?"
echo "   im Classmap: ja"

# --- 3. Verzeichnis einlesen ----------------------------------------------
say "Plugin-Verzeichnis einlesen (cli/setup.php build)"
run_in_ilias "cd '$ILIAS_PATH' && php cli/setup.php build >/dev/null 2>&1" \
  || fail "cli/setup.php build fehlgeschlagen."

# Die wichtigste Pruefung des ganzen Skripts: build meldet [OK] und schreibt
# trotzdem nichts, wenn ihm das Schreibrecht auf artifacts/ fehlt.
FOUND=$(run_in_ilias "grep -rl Alphabees '$ILIAS_PATH/artifacts' 2>/dev/null | head -1" || true)
[ -n "$FOUND" ] || fail "ILIAS kennt die Plugins nicht. 'build' hat nichts geschrieben — meist fehlt Schreibrecht auf $ILIAS_PATH/artifacts (gehoert oft root). Als root wiederholen."
echo "   ILIAS kennt die Plugins: ja"

# --- Fertig ----------------------------------------------------------------
cat <<'DONE'

Fertig. Weiter in ILIAS:

  1. ERSTINSTALLATION — Administration -> Plugins
     AlphabeesTutor      -> Installieren, dann Aktivieren
     AlphabeesTutorSync  -> Installieren, dann Aktivieren
     (In dieser Reihenfolge: dem ersten gehoeren die Tabellen.)

  1b. AKTUALISIERUNG — stattdessen an beiden Eintraegen "Aktualisieren",
     oder auf der Kommandozeile:

       php cli/setup.php update --legacy-plugin=AlphabeesTutor
       php cli/setup.php update --legacy-plugin=AlphabeesTutorSync

     Ohne diesen Schritt ist das Plugin ABGESCHALTET: ILIAS haelt ein
     Plugin fuer inaktiv, solange die eingespielte Version von der
     zuletzt aktualisierten abweicht. Der Tutor verschwindet dann aus
     allen Kursen und die Hintergrundlaeufe schweigen -- ohne Fehler.

  2. AlphabeesTutor -> Konfigurieren
     Den Verbindungscode aus dem AlphaLearn-Portal einfuegen.

Noch eines, ohne das nichts passiert: ILIAS startet seine Cron-Jobs nicht
selbst. Laeuft auf diesem Server noch keiner, eine Zeile in die crontab:

  */5 * * * * php <ilias-verzeichnis>/cli/cron.php run-jobs <admin> <client>

Kein Passwort noetig; <admin> ist ein ILIAS-Benutzer mit Administratorrechten
(meist root), <client> die Mandantenkennung (meist default).

Das Plugin sagt es auf seiner Konfigurationsseite auch selbst, samt fertiger
Zeile fuer diese Installation.
DONE
