# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

Beide Plugins tragen **dieselbe Version** und werden gemeinsam veröffentlicht.
Eine Installation mit gemischten Ständen ist nicht vorgesehen.

## [Unveröffentlicht]

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
