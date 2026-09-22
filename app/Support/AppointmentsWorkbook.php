<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Single reusable Excel (.xlsx) bridge for appointment records — used by the
 * admin "Export" download and the `appointments:import` command so the
 * downloadable format and the import format are always identical.
 *
 * Column headers (row 1). The first eleven mirror the historical source
 * workbook; the last three are the extra fields the appointments table needs
 * and are written blank when a value is not available (never invented).
 */
class AppointmentsWorkbook
{
    public const HEADERS = [
        'Date',
        'Appointment Time',
        'Client Name',
        'Mobile Number',
        'Hairstylist',
        'Service',
        'Category',
        'Amount',
        'Payment Method',
        'Client Type',
        'Status',
        'Source',
        'Payment Status',
        'Reference',
    ];

    /** Excel's day 0 (the 1900 date system, including its historical leap bug). */
    private const EXCEL_EPOCH = '1899-12-30';

    /**
     * Write the given appointments to an .xlsx file and return its path.
     *
     * @param  iterable<Appointment>  $appointments
     */
    public static function write(iterable $appointments, string $path): string
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADERS));

        foreach ($appointments as $a) {
            $paymentMethod = self::readNote($a->notes, 'Payment Method');
            $clientType = self::readNote($a->notes, 'Client Type');

            $writer->addRow(Row::fromValues([
                optional($a->appointment_date)->toDateString() ?? '',
                $a->appointment_time ? Carbon::parse($a->appointment_time)->format('H:i') : '',
                (string) $a->customer_name,
                (string) $a->phone,
                (string) $a->stylist_name,
                (string) $a->service_name,
                (string) $a->category_name,
                $a->service_price !== null ? (float) $a->service_price : '',
                $paymentMethod,
                $clientType,
                (string) $a->status,
                (string) $a->source,
                (string) $a->payment_status,
                (string) $a->reference,
            ]));
        }

        $writer->close();

        return $path;
    }

    /**
     * Read an .xlsx file into normalised appointment rows. Only the first sheet
     * is read; any "Summary"/"Notes" sheet is ignored so derived data is never
     * imported as records. Values absent from the file stay null — nothing is
     * fabricated.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            // Records live on the first sheet only — any Summary/Import Notes
            // sheet is skipped so derived data is never imported.
            $map = [];
            foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                $cells = $row->toArray();

                if ($rowNumber === 1) {
                    foreach ($cells as $col => $value) {
                        $map[strtolower(trim((string) $value))] = $col;
                    }

                    continue;
                }

                $get = fn (string $header) => array_key_exists($map[strtolower($header)] ?? -1, $cells)
                    ? $cells[$map[strtolower($header)]]
                    : null;

                $name = self::str($get('Client Name'));
                if ($name === null) {
                    continue; // blank line
                }

                $rows[] = [
                    'appointment_date' => self::toDate($get('Date')),
                    'appointment_time' => self::toTime($get('Appointment Time')),
                    'customer_name' => $name,
                    'phone' => self::str($get('Mobile Number')),
                    'stylist_name' => self::str($get('Hairstylist')),
                    'service_name' => self::str($get('Service')),
                    'category_name' => self::str($get('Category')),
                    'amount' => self::toDecimal($get('Amount')),
                    'payment_method' => self::str($get('Payment Method')),
                    'client_type' => self::str($get('Client Type')),
                    'status' => self::str($get('Status')),
                    'source' => self::str($get('Source')),
                    'payment_status' => self::str($get('Payment Status')),
                    'reference' => self::str($get('Reference')),
                ];
            }

            break; // first sheet only
        }

        $reader->close();

        return $rows;
    }

    private static function str(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    private static function toDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return ($clean === '' || $clean === null) ? null : number_format((float) $clean, 2, '.', '');
    }

    private static function toDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            return Carbon::parse(self::EXCEL_EPOCH)->addDays((int) floor((float) $value))->toDateString();
        }
        $value = is_string($value) ? trim($value) : $value;
        if ($value === null || $value === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($value)->toDateString(), null, false);
    }

    private static function toTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }
        if (is_numeric($value)) {
            $seconds = (int) round(((float) $value - floor((float) $value)) * 86400);

            return gmdate('H:i:s', max(0, min(86399, $seconds)));
        }
        $value = is_string($value) ? trim($value) : $value;
        if ($value === null || $value === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($value)->format('H:i:s'), null, false);
    }

    /** Pull a "Label: value" fragment back out of the free-text notes field. */
    private static function readNote(?string $notes, string $label): string
    {
        if (! $notes) {
            return '';
        }
        if (preg_match('/'.preg_quote($label, '/').':\s*([^;\n]+)/i', $notes, $m)) {
            return rtrim(trim($m[1]), '.');
        }

        return '';
    }
}
