<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Support\AppointmentsWorkbook;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Import historical appointment records from an .xlsx workbook in the shared
 * admin format (see App\Support\AppointmentsWorkbook). Only the first sheet is
 * read; any Summary/Notes sheet is ignored. Missing values are left blank — the
 * command never fabricates services, categories, statuses, stylists or amounts.
 */
class ImportAppointments extends Command
{
    protected $signature = 'appointments:import
        {file : Path to the .xlsx workbook}
        {--source=offline : source to record for rows that do not specify one}
        {--truncate : delete ALL existing appointments before importing}
        {--force : skip the truncate confirmation prompt}
        {--dry-run : parse and report only, write nothing}';

    protected $description = 'Import appointment records from an .xlsx workbook (shared admin format)';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $rows = AppointmentsWorkbook::read($file);
        $this->info(count($rows).' data row(s) found.');

        if ($rows === []) {
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('truncate') && ! $dryRun) {
            $existing = Appointment::query()->count();
            if (! $this->option('force')
                && ! $this->confirm("Delete all {$existing} existing appointment record(s) first?", false)) {
                return self::FAILURE;
            }
            Appointment::query()->delete();
            $this->warn("Deleted {$existing} existing appointment(s).");
        }

        $imported = 0;
        $skipped = 0;
        $defaultSource = (string) $this->option('source');

        $bar = $this->output->createProgressBar(count($rows));

        foreach ($rows as $row) {
            $bar->advance();

            if (! $row['appointment_date']) {
                $skipped++;
                $this->warn("\n\"".$row['customer_name'].'": no appointment date in the source — skipped.');

                continue;
            }

            if ($dryRun) {
                $imported++;

                continue;
            }

            $noteParts = ['Historical import'];
            if ($row['payment_method']) {
                $noteParts[] = 'Payment Method: '.$row['payment_method'];
            }
            if ($row['client_type']) {
                $noteParts[] = 'Client Type: '.$row['client_type'];
            }

            Appointment::create([
                'customer_name' => $row['customer_name'],
                'phone' => $row['phone'] ?? '',
                'gender' => 'unisex', // not provided by the source; neutral sentinel
                'source' => $row['source'] ?: $defaultSource,
                'stylist_name' => $row['stylist_name'],
                'service_name' => $row['service_name'],
                'category_name' => $row['category_name'],
                'service_price' => $row['amount'],
                'appointment_date' => Carbon::parse($row['appointment_date']),
                'appointment_time' => $row['appointment_time'], // null when the source has no time

                'status' => $row['status'] ?? '',            // blank stays blank
                'payment_status' => $row['payment_status'] ?: Appointment::PAYMENT_UNPAID,
                'notes' => implode('; ', $noteParts).'.',
            ]);
            $imported++;
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(($dryRun ? '[dry run] ' : '')."Imported {$imported}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
