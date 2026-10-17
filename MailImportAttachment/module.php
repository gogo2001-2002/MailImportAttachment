<?php

class MailImportAttachment extends IPSModule
{
    private $imapSocket = null;
    private int $imapTag = 0;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Server', '');
        $this->RegisterPropertyInteger('Port', 993);
        $this->RegisterPropertyString('Encryption', 'ssl');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('AllowedSender', '');
        $this->RegisterPropertyString('SubjectContains', '');
        $this->RegisterPropertyString('TargetDirectory', '');
        $this->RegisterPropertyInteger('ScriptID', 0);
        $this->RegisterPropertyInteger('PollInterval', 60);
        $this->RegisterPropertyBoolean('MarkSeen', true);
        $this->RegisterAttributeString('LastStatus', 'Noch kein Abruf durchgeführt.');
        $this->RegisterVariableString('Status', 'Status', '', 10);
        $this->RegisterVariableString('LastCheck', 'Letzter Abruf', '', 20);
        $this->RegisterVariableInteger('ProcessedCount', 'Verarbeitete E-Mails', '', 30);
        $this->RegisterVariableInteger('SavedCount', 'Gespeicherte Dateien', '', 40);
        $this->RegisterVariableInteger('ErrorCount', 'Fehler', '', 50);
        $this->EnableAction('Status');
        $this->RegisterTimer('PollTimer', 0, 'MIA_CheckNow($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = max(30, $this->ReadPropertyInteger('PollInterval'));
        $configured = $this->ReadPropertyString('Server') !== ''
            && $this->ReadPropertyString('Username') !== ''
            && $this->ReadPropertyString('AllowedSender') !== ''
            && $this->ReadPropertyString('TargetDirectory') !== '';
        $this->SetTimerInterval('PollTimer', $configured ? $interval * 1000 : 0);
        $this->UpdateFormField('Status', 'caption', $this->ReadAttributeString('LastStatus'));
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'MIA_CheckNow') {
            throw new Exception('Invalid Ident');
        }
        $this->CheckNow();
    }

    public function CheckNow()
    {
        $server = trim($this->ReadPropertyString('Server'));
        $username = trim($this->ReadPropertyString('Username'));
        $password = $this->ReadPropertyString('Password');
        $allowedSender = strtolower(trim($this->ReadPropertyString('AllowedSender')));
        $subjectContains = trim($this->ReadPropertyString('SubjectContains'));
        $directory = $this->ResolveTargetDirectory(trim($this->ReadPropertyString('TargetDirectory')));
        $scriptID = $this->ReadPropertyInteger('ScriptID');

        if ($server === '' || $username === '' || $allowedSender === '' || $directory === '') {
            $this->SetStatusText('Bitte IMAP-Server, Benutzername, erlaubten Absender und Zielverzeichnis konfigurieren.');
            return;
        }
        if ($scriptID > 0 && !IPS_ScriptExists($scriptID)) {
            $this->SetStatusText('Fehler: Die konfigurierte Skript-ID existiert nicht: ' . $scriptID);
            return;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
            $this->SetStatusText('Fehler: Zielverzeichnis kann nicht erstellt werden: ' . $directory);
            return;
        }
        if (!is_writable($directory)) {
            $this->SetStatusText('Fehler: Keine Schreibrechte für das Zielverzeichnis: ' . $directory);
            return;
        }

        $processed = 0;
        $savedCount = 0;
        $errors = 0;
        $this->SetStatusText('Verbinde mit dem IMAP-Server …');
        try {
            $this->ConnectImap($server, $this->ReadPropertyInteger('Port'), $this->ReadPropertyString('Encryption'));
            $this->ImapCommand('LOGIN ' . $this->QuoteImap($username) . ' ' . $this->QuoteImap($password));
            $this->ImapCommand('SELECT INBOX');
            $search = $this->ImapCommand('UID SEARCH UNSEEN');
            preg_match('/\* SEARCH([^\r\n]*)/i', $search, $searchMatch);
            $uids = isset($searchMatch[1]) ? preg_split('/\s+/', trim($searchMatch[1]), -1, PREG_SPLIT_NO_EMPTY) : [];

            foreach ($uids as $uid) {
                if (!ctype_digit($uid)) {
                    continue;
                }
                $fetch = $this->ImapCommand('UID FETCH ' . $uid . ' (BODY.PEEK[])');
                $rawMessage = $this->ExtractLiteral($fetch);
                if ($rawMessage === null) {
                    $errors++;
                    $this->SendDebug('IMAP', 'Keine Nachrichten-Literal-Daten für UID ' . $uid, 0);
                    continue;
                }

                $message = $this->ParseMimeMessage($rawMessage);
                $from = $this->ExtractSender($message['headers']['from'] ?? '');
                $subject = $this->DecodeMimeText($message['headers']['subject'] ?? '');
                if ($from === '' || strtolower($from) !== $allowedSender) {
                    $this->SendDebug('Absender ignoriert', $from, 0);
                    continue;
                }
                if ($subjectContains !== '' && stripos($subject, $subjectContains) === false) {
                    $this->SendDebug('Betreff ignoriert', 'UID ' . $uid . ', Betreff: ' . $subject, 0);
                    continue;
                }
                $this->SetStatusText('Prüfe E-Mail UID ' . $uid . ' (Betreff: ' . ($subject !== '' ? $subject : '(ohne Betreff)') . ') …');
                if (empty($message['attachments'])) {
                    $this->SendDebug('Keine Anhänge', 'UID ' . $uid, 0);
                    continue;
                }

                $paths = [];
                $messageOk = true;
                foreach ($message['attachments'] as $attachment) {
                    $path = $this->UniquePath($directory, $this->SafeFilename($attachment['name']));
                    if (@file_put_contents($path, $attachment['data'], LOCK_EX) === false) {
                        $messageOk = false;
                        $this->SendDebug('Dateifehler', 'Konnte nicht schreiben: ' . $path, 0);
                        break;
                    }
                    $savedCount++;
                    $paths[] = $path;
                }
                if (!$messageOk) {
                    $errors++;
                    continue;
                }

                if ($scriptID > 0) {
                    $this->SetStatusText('Rufe Skript ' . $scriptID . ' für E-Mail UID ' . $uid . ' auf …');
                    IPS_RunScript($scriptID);
                }
                if ($this->ReadPropertyBoolean('MarkSeen')) {
                    $this->ImapCommand('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Seen)');
                }
                $processed++;
                $this->SendDebug('Import erfolgreich', 'UID ' . $uid . ': ' . implode(', ', $paths), 0);
            }
        } catch (Throwable $e) {
            $errors++;
            $this->SendDebug('Ausnahme', $e->getMessage(), 0);
            $this->UpdateRunCounters($processed, $savedCount, $errors, true);
            $this->SetStatusText('Fehler: ' . $e->getMessage());
            return;
        } finally {
            $this->DisconnectImap();
        }

        $this->UpdateRunCounters($processed, $savedCount, $errors, true);
        $this->SetStatusText(date('d.m.Y H:i:s') . ' – abgeschlossen: ' . $processed .
            ' E-Mails verarbeitet, ' . $savedCount . ' Dateien gespeichert, ' . $errors . ' Fehler.');
    }

    private function ConnectImap(string $server, int $port, string $encryption): void
    {
        $timeout = 20;
        $host = ($encryption === 'ssl' ? 'ssl://' : '') . $server;
        $errno = 0;
        $error = '';
        $this->imapSocket = @stream_socket_client($host . ':' . $port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($this->imapSocket)) {
            throw new Exception('IMAP-Verbindung fehlgeschlagen (' . $errno . '): ' . $error);
        }
        stream_set_timeout($this->imapSocket, $timeout);
        $greeting = fgets($this->imapSocket);
        if ($greeting === false || stripos($greeting, '* OK') !== 0) {
            throw new Exception('Ungültige IMAP-Begrüßung: ' . trim((string)$greeting));
        }
        if ($encryption === 'tls') {
            $this->ImapCommand('STARTTLS');
            $crypto = @stream_socket_enable_crypto($this->imapSocket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new Exception('STARTTLS konnte nicht aktiviert werden.');
            }
        }
    }

    private function DisconnectImap(): void
    {
        if (is_resource($this->imapSocket)) {
            try {
                $this->ImapCommand('LOGOUT');
            } catch (Throwable $e) {
                // Verbindung wird anschließend geschlossen.
            }
            fclose($this->imapSocket);
        }
        $this->imapSocket = null;
    }

    private function ImapCommand(string $command): string
    {
        if (!is_resource($this->imapSocket)) {
            throw new Exception('Keine IMAP-Verbindung vorhanden.');
        }
        $tag = 'MIA' . str_pad((string)++$this->imapTag, 4, '0', STR_PAD_LEFT);
        if (fwrite($this->imapSocket, $tag . ' ' . $command . "\r\n") === false) {
            throw new Exception('IMAP-Befehl konnte nicht gesendet werden.');
        }
        $response = '';
        $taggedDone = false;
        while (!feof($this->imapSocket)) {
            $line = fgets($this->imapSocket);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $literalMatch)) {
                $length = (int)$literalMatch[1];
                $remaining = $length;
                while ($remaining > 0) {
                    $chunk = fread($this->imapSocket, min(8192, $remaining));
                    if ($chunk === false || $chunk === '') {
                        throw new Exception('IMAP-Literal unvollständig gelesen.');
                    }
                    $response .= $chunk;
                    $remaining -= strlen($chunk);
                }
                continue;
            }
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)\b/i', $line, $statusMatch)) {
                $taggedDone = true;
                if (strtoupper($statusMatch[1]) !== 'OK') {
                    throw new Exception('IMAP-Befehl fehlgeschlagen: ' . trim($line));
                }
                break;
            }
        }
        if (!$taggedDone) {
            throw new Exception('Keine vollständige Antwort auf IMAP-Befehl ' . $tag . '.');
        }
        return $response;
    }

    private function QuoteImap(string $value): string
    {
        return '"' . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', ''], $value) . '"';
    }

    private function ExtractLiteral(string $response): ?string
    {
        if (!preg_match('/\{(\d+)\}\r?\n/', $response, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $length = (int)$matches[1][0];
        $start = $matches[0][1] + strlen($matches[0][0]);
        if (strlen($response) - $start < $length) {
            return null;
        }
        return substr($response, $start, $length);
    }

    private function ParseMimeMessage(string $raw): array
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $headerText = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $headers = [];
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $headerText);
        foreach (preg_split("/\r?\n/", (string)$unfolded) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $key = strtolower(trim(substr($line, 0, $colon)));
                $headers[$key] = trim(substr($line, $colon + 1));
            }
        }
        $attachments = [];
        $this->ParseMimePart($headers, $body, $attachments, false);
        return ['headers' => $headers, 'attachments' => $attachments];
    }

    private function ParseMimePart(array $headers, string $body, array &$attachments, bool $forceAttachment): void
    {
        $contentType = $headers['content-type'] ?? 'text/plain';
        $disposition = $headers['content-disposition'] ?? '';
        $filename = $this->MimeParameter($disposition, 'filename');
        if ($filename === '') {
            $filename = $this->MimeParameter($contentType, 'name');
        }
        $isAttachment = $forceAttachment || $filename !== '' || stripos($disposition, 'attachment') !== false;
        if (preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $contentType, $boundaryMatch)) {
            $boundary = $boundaryMatch[1] !== '' ? $boundaryMatch[1] : $boundaryMatch[2];
            $delimiter = '--' . $boundary;
            $chunks = explode($delimiter, $body);
            foreach ($chunks as $index => $chunk) {
                if ($index === 0 || str_starts_with(ltrim($chunk), '--')) {
                    continue;
                }
                $chunk = preg_replace('/^\r?\n/', '', $chunk);
                $chunk = preg_replace('/\r?\n$/', '', $chunk);
                $sub = preg_split("/\r?\n\r?\n/", $chunk, 2);
                if (count($sub) !== 2) {
                    continue;
                }
                $subHeaders = $this->ParseHeaders($sub[0]);
                $this->ParseMimePart($subHeaders, $sub[1], $attachments, false);
            }
            return;
        }
        if ($filename !== '' || stripos($disposition, 'attachment') !== false) {
            $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
            if ($encoding === 'base64') {
                $decoded = base64_decode(preg_replace('/\s+/', '', $body), true);
                $body = $decoded === false ? '' : $decoded;
            } elseif ($encoding === 'quoted-printable') {
                $body = quoted_printable_decode($body);
            }
            if ($filename === '') {
                $filename = 'attachment.bin';
            }
            $attachments[] = ['name' => $this->DecodeMimeText($filename), 'data' => $body];
        }
    }

    private function ParseHeaders(string $headerText): array
    {
        $headers = [];
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $headerText);
        foreach (preg_split("/\r?\n/", (string)$unfolded) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }
        return $headers;
    }

    private function MimeParameter(string $header, string $parameter): string
    {
        $quoted = preg_quote($parameter, '/');
        if (preg_match('/(?:^|;)\s*' . $quoted . '\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $header, $matches)) {
            return stripcslashes($matches[1]);
        }
        if (preg_match('/(?:^|;)\s*' . $quoted . '\s*=\s*([^;\s]+)/i', $header, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    private function ExtractSender(string $from): string
    {
        if (preg_match('/<\s*([^>]+)\s*>/', $from, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $from, $matches)) {
            return trim($matches[0]);
        }
        return '';
    }

    private function DecodeMimeText(string $value): string
    {
        if (!function_exists('iconv_mime_decode')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded === false ? $value : $decoded;
    }

    private function ResolveTargetDirectory(string $directory): string
    {
        if ($directory === '') {
            return '';
        }
        $isAbsolute = str_starts_with($directory, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $directory) === 1
            || str_starts_with($directory, '\\\\');
        if (!$isAbsolute) {
            $directory = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($directory, '/\\');
        }
        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    private function SafeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
        $filename = trim((string)$filename, ". \t\n\r\0\x0B");
        return $filename !== '' ? $filename : 'attachment.bin';
    }

    private function UniquePath(string $directory, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $candidate = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($candidate)) {
            return $candidate;
        }
        $suffix = date('Ymd_His');
        $candidate = $directory . DIRECTORY_SEPARATOR . $stem . '_' . $suffix . ($extension !== '' ? '.' . $extension : '');
        $counter = 1;
        while (file_exists($candidate)) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $stem . '_' . $suffix . '_' . $counter . ($extension !== '' ? '.' . $extension : '');
            $counter++;
        }
        return $candidate;
    }

    private function UpdateRunCounters(int $processed, int $savedCount, int $errors, bool $setTime): void
    {
        $this->SetValue('ProcessedCount', $processed);
        $this->SetValue('SavedCount', $savedCount);
        $this->SetValue('ErrorCount', $errors);
        if ($setTime) {
            $this->SetValue('LastCheck', date('d.m.Y H:i:s'));
        }
    }

    private function SetStatusText(string $status): void
    {
        $this->WriteAttributeString('LastStatus', $status);
        $this->SetValue('Status', $status);
        $this->UpdateFormField('Status', 'caption', $status);
        $this->SendDebug('Status', $status, 0);
    }
}
