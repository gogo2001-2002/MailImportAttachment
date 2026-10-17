# MailImportAttachment

IP-Symcon-Modul zum regelmäßigen Abrufen eines IMAP-Postfachs. Es verarbeitet ungelesene E-Mails des konfigurierten Absenders, speichert Anhänge in einem Zielverzeichnis und ruft nach erfolgreichem Speichern aller Anhänge das konfigurierte IP-Symcon-Skript auf.

## Voraussetzungen
- IP-Symcon 9
- Netzwerkzugriff vom Symcon-Container auf den IMAP-Server
- Der Symcon-Dienst benötigt Schreibrechte auf das Zielverzeichnis
- Für die MIME-Kopfzeilen-Dekodierung wird nach Möglichkeit die PHP-Funktion `iconv_mime_decode` verwendet; sie ist nicht Voraussetzung für den IMAP-Zugriff

**Die PHP-IMAP-Erweiterung ist nicht erforderlich.** Das Modul spricht IMAP direkt über PHP-Sockets und nutzt TLS bei entsprechender Konfiguration.

## Installation
1. Repository als Modul-Repository in IP-Symcon einbinden oder den Ordner `MailImportAttachment` in die Modulbibliothek kopieren.
2. Modulverwaltung aktualisieren und eine Instanz **MailImportAttachment** anlegen.
3. IMAP-Server, Port, Verschlüsselung, Zugangsdaten, erlaubten Absender, Zielverzeichnis und Skript-ID eintragen.
4. Mit **Jetzt prüfen** einen manuellen Abruf auslösen und das Symcon-Log kontrollieren.

## Verarbeitung
- Es werden ungelesene Nachrichten gesucht.
- Die Absenderadresse wird exakt mit der konfigurierten Adresse verglichen.
- Anhänge werden aus MIME-Nachrichten dekodiert und gespeichert.
- Bei Dateinamenskollisionen werden Zeitstempel und bei Bedarf laufende Nummern ergänzt.
- Nach erfolgreichem Speichern aller Anhänge wird das ausgewählte Skript einmal pro E-Mail aufgerufen.
- Erfolgreich verarbeitete Nachrichten können als gelesen markiert werden.
- E-Mails ohne Anhänge werden nicht als erfolgreich importiert markiert und lösen keinen Skriptaufruf aus.
- Relative Zielpfade werden relativ zum IP-Symcon-Kernelverzeichnis aufgelöst.

## Sicherheit und Hinweise
Verwende nach Möglichkeit SSL/TLS oder STARTTLS. Das Zielverzeichnis muss für den Symcon-Dienst beschreibbar sein. Vor produktivem Einsatz ist ein Test mit dem konkreten Mailserver empfohlen. Die aktuelle Implementierung verarbeitet das Postfach INBOX und sucht dort nach ungelesenen Nachrichten.
