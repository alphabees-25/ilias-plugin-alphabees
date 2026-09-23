# AlphaLearn für ILIAS

Zwei ILIAS-Plugins, die den KI-Tutor von AlphaLearn in eine ILIAS-Installation
einbinden.

| Plugin | Steckplatz | Aufgabe |
|---|---|---|
| `AlphabeesTutor` | `uihk` | rendert das Widget auf den Seiten eines Kurses, dem im Portal ein Agent zugeordnet ist |
| `AlphabeesTutorSync` | `crnhk` | vier Cron-Jobs: Zuordnungen abrufen, Kursstruktur und Mitglieder senden, Kursdateien senden, Warteschlange und Lebenszeichen |

Beide tragen dieselbe Versionsnummer und werden gemeinsam ausgeliefert. Die
Trennung folgt einer ILIAS-Vorgabe: ein Plugin belegt genau einen Steckplatz.
`AlphabeesTutor` besitzt die Datenbanktabellen und die Kopplung;
`AlphabeesTutorSync` prüft in `beforeActivation()`, dass es aktiviert ist, und
verweigert sonst die eigene Aktivierung.

## Unterstützte Versionen

| | |
|---|---|
| ILIAS | 11.0 – 11.999 |
| Geprüft gegen | ILIAS 11.4 (2026-09-03), PHP 8.4 |
| PHP | Anforderung von ILIAS 11: ≥ 8.3, < 8.5 |
| Andere Hauptversionen | eigener Branch je Hauptversion |

Die Versionsbindung in `plugin.php` ist bindend. ILIAS prüft sie in
`ilPluginInfo::isCompliantToILIAS()`; eine Aktivierung auf einer anderen
Hauptversion ist nicht vorgesehen und lässt sich nicht übergehen.

Geprüft wurde bisher gegen eine Installation. Die benutzten Steckplätze sind
über die 11er-Reihe stabil, gemessen ist das dort jedoch nicht.

## Installation

### Pakete

Die Release-Seite führt `AlphabeesTutor-<version>.zip` und
`AlphabeesTutorSync-<version>.zip`:
**[Releases](https://github.com/alphabees-25/ilias-plugin-alphabees/releases/latest)**.
Die Änderungen je Version stehen im [Changelog](CHANGELOG.md). Selbst bauen:
`./build.sh`.

Jedes ILIAS-Plugin wird über das Dateisystem eingespielt. Die
Plugin-Verwaltung von ILIAS kennt weder Upload noch Download; das Verzeichnis
auf docu.ilias.de führt Links, keine Pakete.

### Zuständigkeiten

| Schritt | Ausführende Rolle | Häufigkeit |
|---|---|---|
| Dateien einspielen, `composer dump-autoload`, `cli/setup.php build` | Server-Zugang (IT oder Hoster) | einmalig, danach bei jedem Update |
| Installieren und Aktivieren | ILIAS-Administration, Weboberfläche | einmalig |
| Verbindungscode einfügen, Agenten zuordnen | E-Learning-Team, Portal und ILIAS | laufend |

### Schritt 1 — Dateien auf den Server

Mit Skript, auf dem ILIAS-Server als root:

```bash
./install.sh /var/www/html
./install.sh /var/www/html --docker <container>     # ILIAS im Container
```

Das Skript führt die drei Schritte aus, die Server-Zugang erfordern, und prüft
nach jedem, ob er gewirkt hat. Die Prüfung ist erforderlich, weil
`cli/setup.php build` ohne Schreibrecht auf `artifacts/` mit `[OK]` endet und
dennoch nichts schreibt.

Von Hand:

```bash
# 1. Pakete in den jeweiligen Steckplatz-Ordner entpacken. Das ZIP enthält das
#    Plugin-Verzeichnis an der Wurzel.
P=/var/www/html/public/Customizing/global/plugins/Services
mkdir -p "$P/UIComponent/UserInterfaceHook" "$P/Cron/CronHook"
unzip -q AlphabeesTutor-<version>.zip     -d "$P/UIComponent/UserInterfaceHook"
unzip -q AlphabeesTutorSync-<version>.zip -d "$P/Cron/CronHook"
chown -R www-data:www-data /var/www/html/public/Customizing/global/plugins

# 2. Klassen bekannt machen. ILIAS lädt Plugin-Klassen über den
#    composer-Classmap (siehe composer.json der Installation,
#    "./public/Customizing/global/plugins").
cd /var/www/html && composer dump-autoload -o

# 3. Plugin-Verzeichnis einlesen.
php cli/setup.php build
```

Schritt 2 und 3 benötigen Schreibrecht auf `vendor/` und `artifacts/`; in
gängigen Abbildern gehören beide `root`. Kontrolle:

```bash
grep -l Alphabees /var/www/html/artifacts/*.php    # muss einen Treffer liefern
```

### Schritt 2 — Installieren und aktivieren

**Administration → Plugins**. Beide Einträge zuerst *Installieren*, dann
*Aktivieren*, in dieser Reihenfolge:

1. `AlphabeesTutor`
2. `AlphabeesTutorSync`

`php cli/setup.php update --legacy-plugin=<Name>` ist für spätere
Aktualisierungen vorgesehen. Für die Erstinstallation genügt es nicht: ILIAS
aktiviert darüber nur, was bereits installiert ist, und einen CLI-Unterbefehl
zum Installieren eines einzelnen Plugins gibt es nicht.

### Schritt 3 — Verbinden

**AlphabeesTutor → Konfigurieren**. Den Verbindungscode aus dem
AlphaLearn-Portal (Integrationen → ILIAS) einfügen und speichern.

Eine bereits verbundene Installation nimmt keinen weiteren Code an; die
Konfigurationsseite zeigt dann *Jetzt aktualisieren* und *Trennen*. Für eine
Verbindung zu einem anderen Konto ist zuvor zu trennen.

### Schritt 4 — Cron

ILIAS startet seine Cron-Jobs nicht selbst, sondern wartet auf einen Aufruf von
außen. Ohne ihn bleibt die lokale Zuordnungstabelle leer und in keinem Kurs
erscheint ein Agent.

```
*/5 * * * * php /var/www/html/cli/cron.php run-jobs <admin> <client>
```

`run-jobs` benötigt kein Passwort; `<admin>` ist ein ILIAS-Benutzer mit
Administratorrechten (üblicherweise `root`), `<client>` die Mandantenkennung
(üblicherweise `default`). ILIAS entscheidet bei jedem Aufruf, welcher Job nach
seinem Rhythmus fällig ist.

Die vier Jobs erscheinen unter **Administration → Cron-Jobs** und sind vorab
aktiviert. Die Konfigurationsseite des Plugins meldet, wenn seit mehr als zwei
Stunden kein Lauf stattgefunden hat.

## Aktualisierung

Neue Dateien allein deaktivieren das Plugin. `ilPluginInfo::isActive()` setzt
`!isUpdateRequired()` voraus, und das trifft zu, sobald die eingespielte
Version von der zuletzt aktualisierten abweicht. In diesem Zustand liefern
`getActivePluginsInSlot('uihk')` und `getPluginJobs()` nichts: das Widget
erscheint nicht mehr, die Hintergrundläufe stehen, und ILIAS meldet keinen
Fehler.

Reihenfolge:

```bash
./install.sh /var/www/html                                  # Dateien, composer, build
cd /var/www/html
php cli/setup.php update --legacy-plugin=AlphabeesTutor
php cli/setup.php update --legacy-plugin=AlphabeesTutorSync
```

Alternativ in der Oberfläche: **Administration → Plugins → Aktualisieren** an
beiden Einträgen. Danach stimmen dort installierte und verfügbare Version
überein.

Wird `build` nach dem Dateitausch nicht ausgeführt, arbeitet das Plugin weiter:
ILIAS vergleicht gegen das Artefakt, nicht gegen `plugin.php`. Es meldet dann
die vorherige Version an das Portal, während der neue Code läuft. Das Backend
sortiert Fähigkeiten deshalb nach `ilias_plugin_version_code` — einer
Konstanten im Code — und nicht nach dem Versionsstring.

## Arbeitsweise

```
Portal                        ILIAS
  │                             │
  │◀── Zuordnungen abrufen ─────┤  alle 15 min   PlacementPullJob
  │                             │
  │◀── Kurse, Mitglieder ───────┤  alle 6 h      StructurePushJob
  │                             │
  │◀── Kursdateien ─────────────┤  alle 12 h     ContentPushJob
  │                             │
  │◀── Lebenszeichen, Reste ────┤  alle 5 min    QueueDrainJob
```

Der Seitenaufbau liest ausschließlich lokal: einen Primärschlüssel-Treffer auf
`ui_uihk_alphabees_plc` und einen im Session-Cache gehaltenen Baumlauf von der
aktuellen `ref_id` zum umgebenden Kurs. Ist die Tabelle leer, erscheint nichts.
Der Seitenaufbau stellt zu keinem Zeitpunkt eine Anfrage an das Backend.

Der `uihk`-Hook ergänzt ausschließlich (`APPEND`) und ausschließlich beim Teil
`template_show` der Hauptvorlage. ILIAS ruft denselben Hook auch für
Brotkrumenleiste, rechte Spalte, Kompetenzansicht und Dashboard-Teile auf;
diese Aufrufe bleiben unberührt.

## Identität

Die lernende Person wird als `{usr_id}@{installations-uuid}.ilias` übergeben —
dieselbe Kennung, die ILIAS einem xAPI-Werkzeug auf der Datenschutzstufe
„ILIAS user ID" ausstellt (`ilCmiXapiUser::getIdent`). Eine Person über LTI und
dieselbe Person über dieses Plugin sind für das Backend identisch; eine
Zuordnungstabelle entfällt.

Die Installations-UUID wird zur Laufzeit gelesen. Sie entsteht je Installation
und ist nirgends fest hinterlegt.

## Aufgabenteilung mit dem Backend

Das Plugin überträgt Rohwerte und interpretiert sie nicht:

- **Rollen** werden als ILIAS-Rollenname übertragen (`il_crs_member_92`); die
  Übersetzung erfolgt im Backend.
- **Lernstand** wird als `ilLPStatus`-Zahl übertragen, aus demselben Grund.
- **Klarnamen** werden übertragen, aber nur gespeichert, wenn der Mandant es im
  Portal erlaubt hat. Zur Zuordnung von Lernstand und Ergebnissen genügt die
  `usr_id`.

Zwei Interpretationen derselben Daten würden auseinanderlaufen, sobald eine von
beiden altert.

## Sicherheit

Jeder Aufruf ist Ed25519-signiert und trägt Zeitstempel und Einmal-Kennzahl.
Das Backend akzeptiert eine Abweichung von 300 Sekunden und weist eine bereits
verwendete Kennzahl ab. Das Schlüsselpaar entsteht beim Verbinden in ILIAS; der
private Teil verlässt die Installation nicht.

Der Verbindungsaufruf selbst ist unsigniert — das Backend kennt den Schlüssel
zu diesem Zeitpunkt noch nicht. Den Mandantenbezug stellt der Einmal-Code her:
24 Stunden gültig, mit dem Aufruf verbraucht, in der Datenbank nur als Hash
hinterlegt.

Trennen löscht die Zugangsdaten und behält die Zuordnungen. Eine erneute
Verbindung stellt den vorherigen Stand wieder her.

## Entwicklung

```bash
./build.sh                                     # Syntax, Versionsgleichheit, ZIPs
find plugins -name '*.php' -exec php -l {} \;  # nur Syntax
```

Der Codestil folgt `@PSR12`, wie ILIAS ihn in
`scripts/PHP-CS-Fixer/code-format.php_cs` festlegt. Die CI prüft Syntax,
Codestil und das Bauskript.

Veröffentlichen: Version in **beiden** `plugin.php` anheben, Abschnitt im
`CHANGELOG.md` ergänzen, dann `git tag v<version> && git push --tags`. Die
Release-Aktion baut die Pakete, prüft die Übereinstimmung von Tag und
Plugin-Version und hängt sie an das Release. `build.sh` bricht ab, wenn die
Plugins verschiedene Versionen tragen oder die Version im Changelog fehlt.

Die beiden Steckplatz-Klassen (`ilAlphabeesTutorUIHookGUI`,
`ilAlphabeesTutorSyncPlugin`) sind bewusst schmal gehalten. Beide Steckplätze
sind in ILIAS 11 als veraltet gekennzeichnet; entfallen sie, ist der Adapter
neu zu schreiben, nicht die darunterliegende Logik.

## Lizenz

GPL-3.0, entsprechend dem ILIAS-Kern. Siehe [LICENSE](LICENSE).

---

© Alphabees UG (haftungsbeschränkt), Berlin · [alphalearn.ai](https://alphalearn.ai)
