# AlphaLearn für ILIAS

Zwei ILIAS-Plugins, die zusammen den KI-Tutor von AlphaLearn in ILIAS bringen.
Ein Code aus dem Portal einfügen — fertig. Kein SOAP, kein technischer
Benutzer, keine Rechtevorlagen, kein LTI-Objekt.

| Plugin | Steckplatz | Zweck |
|---|---|---|
| `AlphabeesTutor` | `uihk` | zeigt das Widget auf den Seiten eines Kurses mit Zuordnung |
| `AlphabeesTutorSync` | `crnhk` | holt die Zuordnungen und sendet Kurse, Mitglieder, Lernstand und Kursdateien |

Beide gehören zusammen und tragen dieselbe Versionsnummer. Das Cron-Plugin
verweigert die Aktivierung, solange das andere nicht verbunden ist: ihm gehören
die Tabellen und die Kopplung.

## Was das gegenüber LTI besser macht

Ein LTI-Launch hängt am Resource Link. Das Widget lebt dann im Kasten eines
Objekts — klickt die lernende Person auf eine PDF, ist es weg. Ein begleitender
Tutor braucht aber jede Seite. Das Plugin rendert auf jeder Seite des Kurses,
und zwar ohne im Seitenaufbau jemals unser Backend zu fragen.

Der LTI-Weg bleibt daneben bestehen — für ILIAS 9/10, für gehostete
Installationen ohne Plugin-Rechte, für Häuser mit Änderungsstopp.

## Einbauen

Fertige Pakete: **[Releases](https://github.com/alphabees-25/ilias-plugin-alphabees/releases/latest)**
— `AlphabeesTutor-<version>.zip` und `AlphabeesTutorSync-<version>.zip`. Beide
tragen dieselbe Version und gehören zusammen. Was sich je Version geändert hat,
steht im [Changelog](CHANGELOG.md).

### Unterstützte Versionen

| | |
|---|---|
| ILIAS | **11.0 – 11.999** |
| Geprüft gegen | ILIAS 11.4 (2026-09-03), PHP 8.4 |
| PHP | was ILIAS 11 ohnehin verlangt: ≥ 8.3, < 8.5 |
| ILIAS 9 / 10 | nicht unterstützt — je Hauptversion ein eigener Branch |
| ILIAS 12 | noch nicht erschienen |

Die Bindung in `plugin.php` ist **hart**. ILIAS prüft sie in
`ilPluginInfo::isCompliantToILIAS()`, und es gibt keinen Schalter, der das
übergeht: auf einer 10er-Installation lässt sich das Plugin nicht aktivieren,
und eine 12er wird es ebenso ablehnen, bis es dafür einen Branch gibt.

Geprüft wurde bisher gegen **eine** Installation (11.4). Die benutzten
Steckplätze sind über die 11er-Reihe stabil, gemessen ist es dort aber nicht.


### Der kurze Weg

Auf dem ILIAS-Server, als root:

```bash
./install.sh /var/www/html                      # oder
./install.sh /var/www/html --docker <container>
```

Das Skript macht genau die drei Schritte, die Server-Zugang brauchen, und
prüft nach jedem, ob er gewirkt hat — der wichtigste Grund dafür: `build`
meldet `[OK]` und schreibt trotzdem nichts, wenn ihm das Schreibrecht auf
`artifacts/` fehlt. Danach geht es in der Oberfläche weiter.

### Oder von Hand

Fertige Pakete stehen unter [Releases](https://github.com/alphabees-25/ilias-plugin-alphabees/releases):
`AlphabeesTutor-<version>.zip` und `AlphabeesTutorSync-<version>.zip`. Beide
tragen dieselbe Version und gehören zusammen. Selbst bauen: `./build.sh`.

```bash
# 1. Beide Plugins an ihren Platz. Das ZIP enthält das Plugin-Verzeichnis an
#    der Wurzel, also direkt im jeweiligen Steckplatz-Ordner entpacken.
P=/var/www/html/public/Customizing/global/plugins/Services
mkdir -p "$P/UIComponent/UserInterfaceHook" "$P/Cron/CronHook"
unzip -q AlphabeesTutor-1.0.0.zip     -d "$P/UIComponent/UserInterfaceHook"
unzip -q AlphabeesTutorSync-1.0.0.zip -d "$P/Cron/CronHook"
chown -R www-data:www-data /var/www/html/public/Customizing/global/plugins

# 2. Klassen bekannt machen. ILIAS lädt Plugin-Klassen über den
#    composer-Classmap (siehe composer.json der ILIAS-Installation,
#    "./public/Customizing/global/plugins"). Ohne diesen Schritt findet
#    ILIAS die Klassen nicht.
cd /var/www/html && composer dump-autoload -o

# 3. Plugin-Verzeichnis einlesen
php cli/setup.php build
```

**Schritt 2 und 3 brauchen Schreibrecht** auf `vendor/` und `artifacts/`. In
den üblichen Abbildern gehören beide `root`, nicht `www-data`. Laufen die
Befehle als `www-data`, meldet composer eine Verweigerung — und `build` meldet
`[OK]` und schreibt trotzdem nichts, was schwerer zu bemerken ist. Prüfen:

```bash
grep -l Alphabees /var/www/html/artifacts/*.php   # muss etwas finden
```

### Wer was macht

| Schritt | Wer | Wie oft |
|---|---|---|
| Dateien, `composer dump-autoload`, `cli/setup.php build` | jemand mit Server-Zugang (IT oder Hoster) | einmal, dann bei Updates |
| Installieren + Aktivieren | ILIAS-Administrator, Weboberfläche | einmal |
| Code einfügen, Agenten zuordnen | E-Learning-Team, Portal + Weboberfläche | laufend |

ILIAS hat **keinen Marktplatz**: die Plugin-Verwaltung kann weder hochladen
noch herunterladen. Die Liste auf docu.ilias.de ist ein Verzeichnis mit Links.
Jedes Plugin — gelistet oder nicht — wird gleich eingespielt, seit ILIAS 9.

Danach in ILIAS: **Administration → Plugins**. Dort stehen beide Einträge;
erst *Installieren*, dann *Aktivieren* — zuerst `AlphabeesTutor`, denn ihm
gehören die Tabellen und `AlphabeesTutorSync` verweigert die Aktivierung ohne
ihn.

> `php cli/setup.php update --legacy-plugin=<Name>` ist für **spätere**
> Aktualisierungen. Für die Erstinstallation reicht es nicht: ILIAS aktiviert
> darüber nur, was bereits installiert ist, und einen CLI-Unterbefehl zum
> Installieren eines einzelnen Plugins gibt es nicht.

Zum Schluss **AlphabeesTutor → Konfigurieren**, den Code aus dem
AlphaLearn-Portal (Integrationen → ILIAS) einfügen, verbinden.

Die vier Cron-Jobs erscheinen unter **Administration → Cron-Jobs** und sind
vorab aktiv. Der Job „Zuordnungen holen" ist der wichtige: ohne ihn bleibt die
lokale Tabelle leer und es erscheint in keinem Kurs etwas.

### Aktualisieren — der Schritt, den man nicht auslassen darf

Neue Dateien allein schalten das Plugin **ab**. `ilPluginInfo::isActive()`
verlangt `!isUpdateRequired()`, und das ist wahr, sobald die eingespielte
Version von der abweicht, die ILIAS zuletzt aktualisiert hat. Dann liefert
`getActivePluginsInSlot('uihk')` nichts mehr und `getPluginJobs()` ebenso: der
Tutor verschwindet aus allen Kursen und die Hintergrundläufe schweigen —
ohne Fehlermeldung, weil aus ILIAS' Sicht alles in Ordnung ist.

Die Reihenfolge ist deshalb:

```bash
./install.sh /var/www/html            # Dateien, composer, build
cd /var/www/html
php cli/setup.php update --legacy-plugin=AlphabeesTutor
php cli/setup.php update --legacy-plugin=AlphabeesTutorSync
```

Oder in der Oberfläche: **Administration → Plugins → Aktualisieren** an beiden
Einträgen. Danach steht dort bei beiden dieselbe Version unter „installiert"
und „verfügbar".

> Solange nur die Dateien getauscht und `build` noch nicht gelaufen ist,
> arbeitet das Plugin weiter — ILIAS vergleicht gegen das Artefakt, nicht
> gegen `plugin.php`. Es meldet dann aber die **alte** Version ans Portal,
> während der neue Code läuft. Genau deshalb sortiert das Backend Fähigkeiten
> nie nach dem Versionsstring, sondern nach `ilias_plugin_version_code` —
> der ist eine Konstante im Code und wandert sofort mit.

## Wie es arbeitet

```
Portal                        ILIAS
  │                             │
  │◀── Zuordnungen holen ───────┤  alle 15 min   (PlacementPullJob)
  │                             │
  │◀── Kurse, Mitglieder ───────┤  alle 6 h      (StructurePushJob)
  │                             │
  │◀── Kursdateien ─────────────┤  alle 12 h     (ContentPushJob)
  │                             │
  │◀── Lebenszeichen, Reste ────┤  alle 5 min    (QueueDrainJob)
  │                             │
  │        (nie im Seitenaufbau)│
```

Der Seitenaufbau liest ausschließlich lokal: ein Primärschlüssel-Treffer auf
`ui_uihk_alphabees_plc` und ein im Session-Cache gehaltener Baumlauf von der
aktuellen `ref_id` zum umgebenden Kurs. Ist die Tabelle leer oder älter als drei
Stunden, erscheint nichts. Eine ILIAS-Seite darf nie auf unsere Erreichbarkeit
warten.

## Identität

Die lernende Person reist als `{usr_id}@{installations-uuid}.ilias` — genau die
Kennung, die ILIAS selbst einem xAPI-Werkzeug auf der Datenschutzstufe „ILIAS
user ID" gibt (`ilCmiXapiUser::getIdent`). Wer über LTI kommt und wer über
dieses Plugin kommt, ist für das Backend dieselbe Person, ohne
Zuordnungstabelle dazwischen.

Die UUID wird zur Laufzeit gelesen, nie fest verdrahtet: sie entsteht je
Installation.

## Was hier nicht entschieden wird

- **Rollen.** Wir senden die ILIAS-Rollennamen (`il_crs_member_92`), übersetzt
  wird im Backend. Zwei Kopien der Zuordnung — die zweite altert.
- **Lernstands-Status.** Wir senden die rohe `ilLPStatus`-Zahl, aus demselben
  Grund.
- **Klarnamen.** Wir senden sie mit, gespeichert werden sie nur, wenn der
  Mandant es im Portal erlaubt hat. Die `usr_id` allein genügt, um Lernstand
  und Ergebnisse einer Person zuzuordnen.

## Sicherheit

Jeder Aufruf ist Ed25519-signiert, mit Zeitfenster (5 min) und
Einmal-Kennzahl gegen Wiedereinspielung. Der private Schlüssel entsteht beim
Verbinden in ILIAS und verlässt die Installation nie; nach draußen geht nur der
öffentliche Teil.

Ausnahme ist der Verbindungsaufruf selbst — da kennt das Backend unseren
Schlüssel ja noch nicht. Den Mandantenbezug trägt dort der Einmal-Code, der
24 Stunden gilt und mit dem Aufruf verbraucht ist.

Trennen leert die Zugangsdaten, **behält aber die Zuordnungen**. Wer
versehentlich trennt oder sein ILIAS neu aufsetzt, verliert keine einzige
Agent-Zuordnung.

## Cron einrichten

Ohne einen laufenden ILIAS-Cron holt das Plugin nie neue Zuordnungen. ILIAS
startet seine Jobs nicht selbst — es wartet auf einen Aufruf von aussen:

```
*/5 * * * * php /var/www/html/cli/cron.php run-jobs root default
```

Kein Passwort nötig, `run-jobs <user> <client_id>` genügt. ILIAS entscheidet
bei jedem Aufruf selbst, welcher Job nach seinem Rhythmus fällig ist.

## Entwickeln

```bash
./build.sh                                    # Syntax + Versionen + ZIPs
find plugins -name '*.php' -exec php -l {} \;  # nur Syntax
```

Veröffentlichen: Version in **beiden** `plugin.php` anheben, Abschnitt im
`CHANGELOG.md` ergänzen, dann `git tag v<version> && git push --tags`. Die
Freigabe-Aktion baut die ZIPs, prüft dass Tag und Plugin-Version
übereinstimmen, und hängt sie an das Release. `build.sh` bricht ab, wenn die
beiden Plugins verschiedene Versionen tragen oder die Version nicht im
CHANGELOG steht.

Die beiden Slot-Klassen (`ilAlphabeesTutorUIHookGUI`,
`ilAlphabeesTutorSyncPlugin`) sind bewusst dünn. Beide Steckplätze sind in
ILIAS 11 als veraltet markiert; wenn sie verschwinden, ist nur der Adapter neu
zu schreiben, nicht die Logik darunter.

---

© Alphabees UG (haftungsbeschränkt), Berlin · [alphalearn.ai](https://alphalearn.ai)
