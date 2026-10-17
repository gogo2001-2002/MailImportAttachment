<?php

class MailImportAttachment extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Server', '');
        $this->RegisterPropertyInteger('Port', 993);
        $this->RegisterPropertyString('Encryption', 'ssl');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyString('AllowedSender', '');
        $this->RegisterPropertyString('TargetDirectory', '');
        $this->RegisterPropertyInteger('ScriptID', 0);
        $this->RegisterPropertyInteger('PollInterval', 60);
        $this->RegisterPropertyBoolean('MarkSeen', true);
        $this->RegisterAttributeString('LastStatus', 'Noch kein Abruf durchgeführt.');
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

    public function MIA_CheckNow()
    {
        $this->CheckNow();
    }

    public function CheckNow()
    {
        $server = trim($this->ReadPropertyString('Server'));
        $username = trim($this->ReadPropertyString('Username'));
        $password = $this->ReadPropertyString('Password');
        $allowedSender = strtolower(trim($this->ReadPropertyString('AllowedSender')));
        $directory = $this->ResolveTargetDirectory(trim($this->ReadPropertyString('TargetDirectory')));
        $scriptID = $this->ReadPropertyInteger('ScriptID');

        if ($server === '' || $username === '' || $allowedSender === '' || $directory === '') {
            $this->SetStatusText('Bitte IMAP-Server, Benutzername, erlaubten Absender und Zielverzeichnis konfigurieren.');
            return;
        }
        if (!function_exists('imap_open')) {
            $this->SetStatusText('Fehler: Die PHP-IMAP-Erweiterung ist nicht verfügbar.');
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

        $port = $this->ReadPropertyInteger('Port');
        $encryption = $this->ReadPropertyString('Encryption');
        $mailbox = '{' . $server . ':' . $port . '/imap/' .
            ($encryption === 'ssl' ? 'ssl' : ($encryption === 'tls' ? 'tls' : 'notls')) . '}INBOX';

        $connection = @imap_open($mailbox, $username, $password, 0, 1);
        if (!$connection) {
            $this->SetStatusText('IMAP-Verbindung fehlgeschlagen: ' . (imap_last_error() ?: 'Unbekannter Fehler'));
            return;
        }

        $processed = 0;
        $savedCount = 0;
        $errors = 0;
        try {
            $uids = @imap_search($connection, 'UNSEEN', SE_UID);
            if (!is_array($uids)) {
                $uids = [];
            }
            foreach ($uids as $uid) {
                $overview = @imap_fetch_overview($connection, (string)$uid, FT_UID);
                if (!$overview || !isset($overview[0])) {
                    continue;
                }
                $from = $this->ExtractSender((string)($overview[0]->from ?? ''));
                if ($from === '' || strtolower($from) !== $allowedSender) {
                    $this->SendDebug('Absender ignoriert', $from, 0);
                    continue;
                }

                $structure = @imap_fetchstructure($connection, (int)$uid, FT_UID);
                if (!$structure) {
                    $errors++;
                    continue;
                }
                $attachments = [];
                $this->CollectAttachments($connection, (int)$uid, $structure, '', $attachments);
                if (!$attachments) {
                    continue;
                }

                $paths = [];
                $messageOk = true;
                foreach ($attachments as $attachment) {
                    $filename = $this->SafeFilename($attachment['name']);
                    $path = $this->UniquePath($directory, $filename);
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
                    if (!IPS_ScriptExists($scriptID)) {
                        $this->SendDebug('Skriptfehler', 'Skript-ID existiert nicht: ' . $scriptID, 0);
                        $errors++;
                        continue;
                    }
                    IPS_RunScript($scriptID);
                }
                if ($this->ReadPropertyBoolean('MarkSeen')) {
                    @imap_setflag_full($connection, (string)$uid, '\\Seen', ST_UID);
                }
                $processed++;
                $this->SendDebug('Import erfolgreich', 'UID ' . $uid . ': ' . implode(', ', $paths), 0);
            }
        } catch (Throwable $e) {
            $errors++;
            $this->SendDebug('Ausnahme', $e->getMessage(), 0);
        } finally {
            @imap_close($connection);
        }

        $this->SetStatusText(date('d.m.Y H:i:s') . ' – verarbeitet: ' . $processed .
            ', Dateien gespeichert: ' . $savedCount . ', Fehler: ' . $errors . '.');
    }


    /**
     * Relative Zielpfade werden relativ zum IP-Symcon-Kernel-/Datenverzeichnis aufgelöst.
     * Absolute Pfade bleiben unverändert.
     */
    private function ResolveTargetDirectory(string $directory): string
    {
        $directory = trim($directory);
        if ($directory === '') {
            return '';
        }

        // Unix absolute paths and Windows drive/UNC paths are treated as absolute.
        $isAbsolute = str_starts_with($directory, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $directory) === 1
            || str_starts_with($directory, '\\\\\\\\');

        if (!$isAbsolute) {
            $kernelDirectory = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR);
            $directory = $kernelDirectory . DIRECTORY_SEPARATOR . ltrim($directory, '/\\\\');
        }

        return rtrim($directory, DIRECTORY_SEPARATOR);
    }

    private function CollectAttachments($connection, int $uid, object $part, string $partNumber, array &$attachments): void
    {
        $name = '';
        if (!empty($part->ifdparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $parameter) {
                if (strtolower((string)$parameter->attribute) === 'filename') {
                    $name = (string)$parameter->value;
                }
            }
        }
        if ($name === '' && !empty($part->ifparameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $parameter) {
                if (strtolower((string)$parameter->attribute) === 'name') {
                    $name = (string)$parameter->value;
                }
            }
        }

        if ($name !== '') {
            $section = $partNumber === '' ? '1' : $partNumber;
            $body = @imap_fetchbody($connection, (string)$uid, $section, FT_UID);
            if ($body !== false) {
                if ((int)$part->encoding === 3) {
                    $decoded = base64_decode($body, true);
                    $body = $decoded === false ? '' : $decoded;
                } elseif ((int)$part->encoding === 4) {
                    $body = quoted_printable_decode($body);
                }
                $attachments[] = ['name' => $this->DecodeMimeText($name), 'data' => $body];
            }
            return;
        }

        if (!empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $index => $subPart) {
                $subNumber = $partNumber === '' ? (string)($index + 1) : $partNumber . '.' . ($index + 1);
                $this->CollectAttachments($connection, $uid, $subPart, $subNumber, $attachments);
            }
        }
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
        $decoded = @imap_mime_header_decode($value);
        if (!is_array($decoded)) {
            return $value;
        }
        $result = '';
        foreach ($decoded as $piece) {
            $charset = $piece->charset ?? 'UTF-8';
            $text = (string)($piece->text ?? '');
            if ($charset !== 'default' && strtoupper($charset) !== 'UTF-8' && function_exists('iconv')) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }
            $result .= $text;
        }
        return $result;
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
        $candidate = $directory . DIRECTORY_SEPARATOR . $stem . '_' . $suffix .
            ($extension !== '' ? '.' . $extension : '');
        $counter = 1;
        while (file_exists($candidate)) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $stem . '_' . $suffix . '_' . $counter .
                ($extension !== '' ? '.' . $extension : '');
            $counter++;
        }
        return $candidate;
    }

    private function SetStatusText(string $status): void
    {
        $this->WriteAttributeString('LastStatus', $status);
        $this->UpdateFormField('Status', 'caption', $status);
        $this->SendDebug('Status', $status, 0);
    }
}
