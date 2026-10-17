# MailImportAttachment

IP-Symcon-Modul zum regelmäßigen Abrufen eines IMAP-Postfachs. Es verarbeitet ausschließlich ungelesene E-Mails des konfigurierten Absenders, speichert Anhänge in einem Zielverzeichnis und ruft nach erfolgreichem Speichern aller Anhänge das konfigurierte IP-Symcon-Skript auf.

## Voraussetzungen
- IP-Symcon 9
- PHP-IMAP-Erweiterung (`imap_open`, `imap_search`, `imap_fetchstructure`, `imap_fetchbody`)
- Der Symcon-Dienst benötigt Schreibrechte auf das Zielverzeichnis.

## Installation
1. Repository als Modul-Repository in IP-Symcon einbinden oder den Ordner `MailImportAttachment` in die Modulbibliothek kopieren.
2. Modulverwaltung aktualisieren und eine Instanz **MailImportAttachment** anlegen.
3. IMAP-Server, Port, Verschlüsselung, Zugangsdaten, erlaubten Absender, Zielverzeichnis und Skript-ID eintragen.
4. Mit **Jetzt prüfen** einen manuellen Abruf auslösen und das Symcon-Log kontrollieren.

## Verarbeitung
- Es werden ungelesene Nachrichten gesucht.
- Die Absenderadresse wird exakt mit der konfigurierten Adresse verglichen.
- Bei Dateinamenskollisionen werden Zeitstempel und bei Bedarf laufende Nummern ergänzt.
- Nach erfolgreichem Speichern aller Anhänge wird das ausgewählte Skript einmal pro E-Mail aufgerufen.
- Erfolgreich verarbeitete Nachrichten können als gelesen markiert werden.
- E-Mails ohne Anhänge werden nicht als erfolgreich importiert markiert und lösen keinen Skriptaufruf aus.

## Sicherheit und Grenzen
Verwende nach Möglichkeit TLS/SSL. Das Zielverzeichnis muss für den Symcon-Dienst beschreibbar sein. Das Modul benötigt die PHP-IMAP-Erweiterung; deren Verfügbarkeit muss in der jeweiligen Symcon-Installation geprüft werden. Vor produktivem Einsatz ist ein Test mit dem konkreten Mailserver empfohlen.
