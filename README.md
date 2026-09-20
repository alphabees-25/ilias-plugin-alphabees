# AlphaLearn für ILIAS

Zwei ILIAS-Plugins, die zusammen den KI-Tutor von AlphaLearn in ILIAS bringen.
Ein Code aus dem Portal einfügen — fertig. Kein SOAP, kein technischer
Benutzer, keine Rechtevorlagen, kein LTI-Objekt.

| Plugin | Steckplatz | Zweck |
|---|---|---|
| `AlphabeesTutor` | `uihk` | zeigt das Widget auf den Seiten eines Kurses mit Zuordnung |
| `AlphabeesTutorSync` | `crnhk` | holt die Zuordnungen und sendet Kurse, Mitglieder, Lernstand |

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

Für ILIAS 11. Die Versionsbindung in `plugin.php` ist hart: ILIAS prüft sie in
`ilPluginInfo::isCompliantToILIAS()`, und es gibt keinen Schalter, der das
übergeht. Für andere ILIAS-Hauptversionen gibt es einen eigenen Branch.

```bash
# 1. Beide Plugins an ihren Platz
cp -r plugins/Services/* /var/www/html/public/Customizing/global/plugins/Services/

# 2. Klassen bekannt machen. ILIAS lädt Plugin-Klassen über den
#    composer-Classmap (siehe composer.json der ILIAS-Installation,
#    "./public/Customizing/global/plugins"). Ohne diesen Schritt findet
#    ILIAS die Klassen nicht.
cd /var/www/html && composer dump-autoload -o

# 3. Installieren und aktivieren
php cli/setup.php update --legacy-plugin=AlphabeesTutor     <config.json>
php cli/setup.php update --legacy-plugin=AlphabeesTutorSync <config.json>
```

Danach in ILIAS: **Administration → Plugins → AlphabeesTutor → Konfigurieren**,
den Code aus dem AlphaLearn-Portal (Integrationen) einfügen, verbinden.

Die drei Cron-Jobs erscheinen unter **Administration → Cron-Jobs** und sind
vorab aktiv. Der Job „Zuordnungen holen" ist der wichtige: ohne ihn bleibt die
lokale Tabelle leer und es erscheint in keinem Kurs etwas.

## Wie es arbeitet

```
Portal                        ILIAS
  │                             │
  │◀── Zuordnungen holen ───────┤  alle 15 min   (PlacementPullJob)
  │                             │
  │◀── Kurse, Mitglieder ───────┤  alle 6 h      (StructurePushJob)
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

## Entwickeln

```bash
# Syntax aller Dateien
find plugins -name '*.php' -exec php -l {} \;
```

Die beiden Slot-Klassen (`ilAlphabeesTutorUIHookGUI`,
`ilAlphabeesTutorSyncPlugin`) sind bewusst dünn. Beide Steckplätze sind in
ILIAS 11 als veraltet markiert; wenn sie verschwinden, ist nur der Adapter neu
zu schreiben, nicht die Logik darunter.

---

© Alphabees GbR · [alphalearn.ai](https://alphalearn.ai)
