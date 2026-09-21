# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

Beide Plugins tragen **dieselbe Version** und werden gemeinsam veröffentlicht.
Eine Installation mit gemischten Ständen ist nicht vorgesehen.

## [Unveröffentlicht]

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
