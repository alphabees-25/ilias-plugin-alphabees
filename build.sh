#!/usr/bin/env bash
#
# Baut je Plugin ein ZIP, das ein ILIAS-Admin direkt entpacken kann.
#
#   dist/AlphabeesTutor-1.0.0.zip      -> AlphabeesTutor/…
#   dist/AlphabeesTutorSync-1.0.0.zip  -> AlphabeesTutorSync/…
#
# Das Plugin-Verzeichnis liegt in der ZIP an der WURZEL, nicht der
# Installationspfad. ILIAS-Admins entpacken in den Steckplatz-Ordner, und ein
# mitgeliefertes `Services/UIComponent/…` würde dort ein zweites Mal
# auftauchen.
#
# Geprüft wird vorher, dass beide plugin.php dieselbe Version tragen und dass
# diese Version im CHANGELOG steht. Ein Release mit auseinanderlaufenden
# Versionen ist der Fehler, den man erst beim Kunden bemerkt.

set -euo pipefail

cd "$(dirname "$0")"

SRC="plugins/Services"
OUT="dist"

# Name|Pfad. Bewusst kein assoziatives Feld: macOS liefert bash 3.2, das
# kennt `declare -A` nicht, und dieses Skript soll auf dem Rechner des
# Entwicklers genauso laufen wie im CI.
PLUGINS="AlphabeesTutor|$SRC/UIComponent/UserInterfaceHook/AlphabeesTutor
AlphabeesTutorSync|$SRC/Cron/CronHook/AlphabeesTutorSync"

version_of() {
  # $version = '1.0.0';  ->  1.0.0
  grep -oE "\\\$version\s*=\s*'[^']+'" "$1/plugin.php" | head -1 | sed "s/.*'\\(.*\\)'/\\1/"
}

# --- Versionen prüfen ------------------------------------------------------
VERSION=""
echo "$PLUGINS" | while IFS='|' read -r name dir; do
  [ -f "$dir/plugin.php" ] || { echo "FEHLT: $dir/plugin.php" >&2; exit 1; }
done

for entry in $PLUGINS; do
  name="${entry%%|*}"
  dir="${entry##*|}"
  v="$(version_of "$dir")"
  [ -n "$v" ] || { echo "Keine \$version in $dir/plugin.php" >&2; exit 1; }
  if [ -z "$VERSION" ]; then
    VERSION="$v"
  elif [ "$v" != "$VERSION" ]; then
    echo "Versionen laufen auseinander: $name=$v, erwartet $VERSION" >&2
    echo "Beide Plugins müssen dieselbe Version tragen." >&2
    exit 1
  fi
done

if ! grep -q "^## \[$VERSION\]" CHANGELOG.md; then
  echo "CHANGELOG.md hat keinen Abschnitt '## [$VERSION]'." >&2
  exit 1
fi

# --- Syntax prüfen ---------------------------------------------------------
if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do
    php -l "$f" >/dev/null || { echo "Syntaxfehler: $f" >&2; exit 1; }
  done < <(find "$SRC" -name '*.php')
else
  echo "Hinweis: php nicht gefunden, Syntaxprüfung übersprungen." >&2
fi

# --- Packen ----------------------------------------------------------------
rm -rf "$OUT"
mkdir -p "$OUT"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

for entry in $PLUGINS; do
  name="${entry%%|*}"
  dir="${entry##*|}"
  rm -rf "${STAGE:?}/$name"
  mkdir -p "$STAGE/$name"
  # -a kopiert keine .git-Reste, weil im Quellbaum keine liegen; die beiden
  # -x-Muster fangen ab, was sich beim Entwickeln einschleicht.
  (cd "$dir" && tar -cf - \
      --exclude='.DS_Store' --exclude='*.log' --exclude='vendor' .) \
    | (cd "$STAGE/$name" && tar -xf -)

  cp LICENSE "$STAGE/$name/LICENSE"
  cp CHANGELOG.md "$STAGE/$name/CHANGELOG.md"

  zip_path="$OUT/$name-$VERSION.zip"
  (cd "$STAGE" && zip -qr "$OLDPWD/$zip_path" "$name")
  printf '%-22s %s  (%s)\n' "$name" "$zip_path" "$(du -h "$zip_path" | cut -f1)"
done

echo
echo "Version $VERSION gebaut. Inhalt der ersten ZIP:"
unzip -l "$OUT/AlphabeesTutor-$VERSION.zip" | sed -n '4,12p'
