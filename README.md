# MailImportAttachment

IP-Symcon-Modul zum regelmäßigen Abrufen eines IMAP-Postfachs. Es verarbeitet ungelesene E-Mails des konfigurierten Absenders und optional eines Betreff-Teils, speichert Anhänge in einem Zielverzeichnis und ruft nach erfolgreichem Speichern das ausgewählte IP-Symcon-Skript auf.

## Voraussetzungen
- IP-Symcon 9
- Netzwerkzugriff vom Symcon-Container auf den IMAP-Server
- Der Symcon-Dienst benötigt Schreibrechte auf das Zielverzeichnis
- Die PHP-IMAP-Erweiterung ist nicht erforderlich; das Modul nutzt PHP-Sockets

## Konfiguration
- IMAP-Server, Port und Verschlüsselung (SSL/TLS, STARTTLS oder unverschlüsselt)
- Benutzername und Passwort
- Exakte erlaubte Absenderadresse
- Optionaler Betreff-Filter: Es genügt ein Teil des Betreffs; der Vergleich ist nicht groß-/kleinschreibungssensitiv
- Zielverzeichnis; relative Pfade werden relativ zum IP-Symcon-Kernelverzeichnis aufgelöst
- Optionales Folgeskript, auswählbar über den Symcon-Skriptauswahldialog
- Prüfintervall und optionales Markieren erfolgreich verarbeiteter Nachrichten als gelesen

## Statusvariablen
Unterhalb der Modulinstanz werden folgende Variablen angelegt:
- **Status**: aktueller Verarbeitungsschritt bzw. Ergebnis
- **Letzter Abruf**: Zeitpunkt des letzten abgeschlossenen oder fehlgeschlagenen Abrufs
- **Verarbeitete E-Mails**: Anzahl erfolgreich verarbeiteter E-Mails beim letzten Durchlauf
- **Gespeicherte Dateien**: Anzahl gespeicherter Anhänge beim letzten Durchlauf
- **Fehler**: Anzahl erkannter Fehler beim letzten Durchlauf

## Verarbeitung
- Es werden ungelesene Nachrichten in INBOX gesucht.
- Absender und optionaler Betreff-Teil werden geprüft.
- Anhänge werden aus MIME-Nachrichten dekodiert und gespeichert.
- Bei Dateinamenskollisionen werden Zeitstempel und bei Bedarf laufende Nummern ergänzt.
- Nach erfolgreichem Speichern aller Anhänge wird das ausgewählte Skript einmal pro E-Mail aufgerufen.
- E-Mails ohne Anhänge lösen keinen Skriptaufruf aus.

Vor produktivem Einsatz wird ein Test mit dem konkreten Mailserver empfohlen.
