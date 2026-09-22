# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

Beide Plugins tragen **dieselbe Version** und werden gemeinsam veröffentlicht.
Eine Installation mit gemischten Ständen ist nicht vorgesehen.

## [Unveröffentlicht]

## [1.3.3] — 2026-09-22

### Geändert
- **Die Konfigurationsseite sagt, warum sie kein Code-Feld zeigt.** Ist die
  Installation bereits verbunden, stehen dort nur „Jetzt aktualisieren" und
  „Trennen". Wer im Portal gerade einen Verbindungscode erzeugt hatte, suchte
  die Eingabe vergeblich — und der Code kann aus einem anderen Konto stammen
  als dem, an dem die Installation hängt. Jetzt steht es als Satz über den
  Knöpfen.
- Der Zustand nennt zusätzlich das **Portal**, mit dem diese Installation
  verbunden ist. Auf einer Instanz, die zwischen Test und Produktion
  gewandert ist, war sonst nicht zu sehen, wohin sie meldet.
- Firmenname auf **Alphabees UG (haftungsbeschränkt)** berichtigt.

## [1.3.2] — 2026-09-21

### Behoben
- **Nach dem Fortsetzen war der Tutor weg.** Eine pausierte Verbindung
  antwortet auf den Zuordnungs-Abruf mit einer leeren Liste und ihrem
  Zustand — der Endpunkt bleibt absichtlich erreichbar, sonst erführe ein
  pausiertes Plugin nie vom Fortsetzen. Das Plugin übernahm diese Leere
  aber: es löschte seine Zuordnungen und den API-Schlüssel. Früher
  verschwand das Widget dadurch nicht (dafür sorgt die Zustandsprüfung im
  Renderer), aber nach dem Fortsetzen stand der Kurs ohne Agenten da.
- **Und blieb es bis zu zwanzig Minuten.** Vom Fortsetzen erfährt das
  Plugin nur über das Lebenszeichen, und das läuft als letzter der vier
  Jobs — der Zuordnungs-Abruf desselben Laufs war da noch geblockt und
  wartete danach auf seinen eigenen Viertelstundentakt. Der Wechsel zurück
  auf „aktiv" stellt die Jobs jetzt sofort wieder fällig.

## [1.3.1] — 2026-09-21

### Hinzugefügt
- `install.sh` — macht die drei Schritte, die Server-Zugang brauchen, und
  prüft nach jedem, ob er gewirkt hat. Gegen eine laufende ILIAS 11.4
  durchgespielt, auch im Docker-Modus.

### Geändert
- **Nach dem Verbinden läuft der erste Abgleich innerhalb von Minuten statt
  Stunden.** Der Strukturlauf hat einen Sechs-Stunden-Takt; wer gerade den
  Code eingefügt hatte, fand im Portal einen halben Tag lang keinen Kurs,
  dem er einen Agenten zuordnen konnte. Das Verbinden stellt die eigenen
  Cron-Jobs jetzt sofort wieder fällig, der nächste Anstoß nimmt sie mit.

### Behoben
- **Das Widget hängt nur noch in der Seite selbst.** ILIAS ruft denselben
  Hook auch für die Brotkrumenleiste, die rechte Spalte, die
  Kompetenzansicht und zwei Dashboard-Teile auf — sieben Aufrufstellen im
  Kern. Die Prüfung dafür war geschrieben, aber nie verdrahtet, das Snippet
  landete also in jedem dieser Fragmente. Sichtbar war es nicht (ein
  JavaScript-Wächter machte die Mehrfachen wirkungslos), im Dokument stand
  es trotzdem.
- **Deinstallieren lässt jetzt wirklich nichts zurück.** ILIAS legt die
  Zeilen seiner Plugin-Cron-Jobs beim ersten Zugriff selbst an und löscht
  sie nie wieder — `unregisterJob` gibt es nur für den Kern. Nach einer
  Deinstallation blieben vier verwaiste Zeilen mit Zeitplan und letztem
  Ergebnis stehen, die eine Neuinstallation geerbt hätte. Das Sync-Plugin
  räumt sie nun selbst weg.

## [1.3.0] — 2026-09-21

### Hinzugefügt
- **Pausieren, fortsetzen, trennen wirken jetzt auch im Plugin.** Das Backend
  meldet den Verbindungszustand in jeder Antwort, die das Plugin ohnehin
  abholt (Lebenszeichen, Zuordnungs-Abruf). Pausiert heisst: das Widget
  verschwindet sofort, die Hintergrundläufe halten an. Getrennt heisst: das
  Plugin löscht seine eigene Kopplung — behält aber die Zuordnungen, damit
  ein erneutes Verbinden alles wiederherstellt.
- Der Zustand steht auf der Konfigurationsseite.

### Geändert
- Das Lebenszeichen läuft auch pausiert weiter. Es ist der einzige Aufruf,
  den eine pausierte Verbindung beantwortet — und damit der einzige Weg
  zurück. Wer auch das abschaltet, kommt nie wieder online.

## [1.2.1] — 2026-09-21

### Behoben
- Die Cron-Prüfung stürzte ab (`queryF` ohne Platzhalter). Sie war neu und
  bis zum ersten vollständigen Durchlauf nie ausgeführt worden.
- Der Warteschlangen-Job meldete fest verdrahtet „Backend unreachable",
  auch wenn der wahre Grund ein HTTP 500 war. Jetzt steht der echte Fehler
  in der Meldung — eine, die in die falsche Richtung schickt, ist schlimmer
  als gar keine.

## [1.2.0] — 2026-09-21

### Hinzugefügt
- **Hinweis, wenn der ILIAS-Cron nicht läuft.** ILIAS startet seine Jobs nicht
  selbst; fehlt der Anstoß von außen, stehen unsere Jobs auf „aktiv" und
  laufen trotzdem nie — nach außen sieht das aus wie ein kaputtes Plugin. Die
  Konfigurationsseite sagt es jetzt, mit der fertigen crontab-Zeile.

## [1.1.0] — 2026-09-21

### Hinzugefügt
- **Kursdateien ohne SOAP und WebDAV.** Ein neuer Cron-Job (`ContentPushJob`,
  alle 12 h) liest die Dateien zugeordneter Kurse in ILIAS selbst und schickt
  sie in die Wissensbasis. Damit entfallen technischer Benutzer, eigene Rolle,
  Rechtevorgaben, SOAP-Freischaltung im Setup und IP-Freigabe.
  Das Plugin meldet dafür die Fähigkeit `content`; das Backend hört daraufhin
  auf, dieselben Dateien per SOAP zu holen.
- Zweistufiger Abgleich: erst ein Verzeichnis ohne Inhalt, dann nur die
  Dateien, die dem Backend fehlen. Ein Kurs mit 300 unveränderten PDFs löst
  keinen einzigen Upload aus.

### Geändert
- `checkAccessOfUser` statt `checkAccess` beim Rendern — die Prüfung benennt
  jetzt, wessen Recht gemeint ist.
- Die Zuordnungen verfallen nicht mehr nach drei Stunden. Blieb der Abruf aus,
  verschwand der Tutor vorher aus allen Kursen, ohne Hinweis.

### Behoben
- Das Widget erschien auf keiner Seite: der Hook verglich `tpl_id`, was auf
  einer echten Kursseite leer ist. Entschieden wird jetzt an `template_show`.
- `Administration → Plugins` stürzte ab, weil der ConfigGUI
  `@ilCtrl_IsCalledBy` fehlte.
- Doppelte `ref_id` im Abruf brach den ganzen Lauf ab.

## [1.0.0] — 2026-09-21

Erste Fassung. Zwei Plugins, die zusammen den AlphaLearn-Tutor in ILIAS 11
bringen: `AlphabeesTutor` (Steckplatz `uihk`) zeigt das Widget, 
`AlphabeesTutorSync` (`crnhk`) holt Zuordnungen und sendet Kursdaten.

### Hinzugefügt
- Kopplung über einen Einmal-Code aus dem AlphaLearn-Portal. Das Plugin
  erzeugt sich dabei ein eigenes Ed25519-Schlüsselpaar; der private Teil
  verlässt die Installation nie.
- Widget auf **jeder** Seite eines Kurses mit Zuordnung — auch auf Dateien,
  Tests und Foren darin. Kein LTI-Objekt, kein Klick auf eine Kachel.
- Drei Cron-Jobs: Zuordnungen holen (15 min), Kurse/Mitglieder/Lernstand
  senden (6 h), Warteschlange abarbeiten und Lebenszeichen (5 min).
- Lerner-Identität deckungsgleich mit einem LTI-Launch derselben Person
  (`{usr_id}@{installations-uuid}.ilias`, wie `ilCmiXapiUser::getIdent`).
- Konfigurationsseite mit Verbindungszustand, „Jetzt aktualisieren" und
  „Verbindung trennen". Trennen behält die Zuordnungen.

### Bekannte Grenzen
- Kursdateien und Testergebnisse laufen weiterhin über den SOAP/WebDAV-Weg;
  das Plugin meldet dafür keine Fähigkeit.
- Installation braucht einmalig Shell-Zugang (`composer dump-autoload`,
  `cli/setup.php build`) — ILIAS-Standard seit Version 9.
- Der ILIAS-Cron muss eingerichtet sein, sonst kommen neue Zuordnungen nur
  über „Jetzt aktualisieren" an.
