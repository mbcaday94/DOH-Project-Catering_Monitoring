<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class PurchaseOrderScan
{
    public const WINDOWS_ENVIRONMENT = [
        'SystemRoot', 'SystemDrive', 'windir', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA',
        'HOMEDRIVE', 'HOMEPATH', 'TEMP', 'TMP', 'ProgramData', 'ProgramFiles', 'ProgramFiles(x86)',
        'ComSpec', 'ALLUSERSPROFILE', 'CommonProgramFiles', 'COMPUTERNAME', 'USERNAME',
    ];

    public function extract(UploadedFile $file): array
    {
        try {
            $environment = [];
            foreach (self::WINDOWS_ENVIRONMENT as $key) {
                $value = getenv($key, true);
                if ($value !== false) {
                    $environment[$key] = $value;
                }
            }
            $result = Process::timeout(45)->env($environment)->run([
                'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', resource_path('read-purchase-order.ps1'),
                '-ScanPath', $file->getPathname(), '-Format', $file->getMimeType() === 'application/pdf' ? 'pdf' : 'image',
            ]);
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['scan' => 'The scan took too long to read. Try a smaller file or enter the details manually.']);
        }

        if ($result->failed()) {
            if ($result->exitCode() === -65536) {
                throw ValidationException::withMessages(['scan' => 'The local scan service could not start. Restart the portal server and try again.']);
            }
            throw ValidationException::withMessages(['scan' => 'Could not read this scan. Use a clear JPG, PNG, or an unlocked PDF of up to eight pages.']);
        }

        return $this->parse($result->output());
    }

    private function parse(string $text): array
    {
        $fields = [];
        $pending = null;
        $labels = array_map(fn (array $definition): string => $definition[3], config('purchase-order.fields'));
        $labels['description'] = 'description';
        $labels['grand_total'] = 'grand\h+total';
        $keys = array_keys($labels);
        $alternatives = array_map(fn (string $field, string $label): string => "(?<$field>$label)", $keys, $labels);
        $labelPattern = '~^\h*(?:'.implode('|', $alternatives).')(?=\h|[.:–—-]|$)\h*[.:–—-]?\h*(?<value>.*)$~iu';

        $lines = array_values(array_filter(array_map(trim(...), preg_split('/\R/u', $text) ?: []), fn (string $line): bool => $line !== ''));
        foreach ($lines as $index => $line) {
            if (preg_match($labelPattern, $line, $match, PREG_UNMATCHED_AS_NULL)) {
                foreach ($keys as $field) {
                    if ($match[$field] !== null) {
                        $pending = $field;
                        break;
                    }
                }
                $line = trim($match['value']);
                if ($line === '') {
                    continue;
                }
            } elseif (preg_match('/^\p{L}[^:]{0,50}:/u', $line)) {
                $pending = null;
            } elseif ($pending === 'grand_total' && $this->normalize('grand_total', $lines[$index + 1] ?? '') !== null) {
                $pending = null;
            }

            if ($pending !== null && ! isset($fields[$pending])) {
                $value = $this->normalize($pending, $line);
                if ($value !== null) {
                    $fields[$pending] = $value;
                }
            }
            $pending = null;
        }

        foreach (['description' => 'activity', 'grand_total' => 'po_amount'] as $label => $field) {
            if (! isset($fields[$field]) && isset($fields[$label])) {
                $fields[$field] = $fields[$label];
            }
            unset($fields[$label]);
        }

        return $fields;
    }

    private function normalize(string $field, string $value): ?string
    {
        if (in_array($field, ['reference', 'ors_number'], true)) {
            $value = preg_replace('/\h*[-–—]\h*/u', '-', $value);

            return preg_match('~^(?=.*\d)[\p{L}\d][\p{L}\d./ -]{0,254}$~u', $value) ? $value : null;
        }

        if (in_array($field, ['po_amount', 'grand_total'], true)) {
            if (! preg_match('/^(?:₱|PHP\.?|P)?\h*(\d{1,3}(?:,\d{3})+|\d+)(\.\d{1,2})?$/iu', $value, $match)) {
                return null;
            }
            $amount = str_replace(',', '', $match[1]).($match[2] ?? '');

            return (float) $amount <= 9999999999999.99 ? $amount : null;
        }

        if (in_array($field, ['date_conformed', 'activity_date'], true)) {
            foreach (['Y-m-d', 'm/d/Y', 'F j, Y', 'M j, Y', 'j F Y'] as $format) {
                $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
                if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                    return $date->format('Y-m-d');
                }
            }

            return null;
        }

        return mb_substr($value, 0, in_array($field, ['activity', 'description'], true) ? 4000 : 255);
    }
}
