<?php

namespace App\Console\Commands;

use App\Support\WhatsApp;
use Illuminate\Console\Command;

/**
 * Quick manual test: php artisan whatsapp:test 9876543210
 * Sends the live booking_confirmed template to that number.
 */
class WhatsAppTest extends Command
{
    protected $signature = 'whatsapp:test
        {phone : Recipient mobile (10-digit Indian or full E.164 without +)}
        {--template= : Override template name (default from config)}
        {--lang= : Override language code (default from config)}';

    protected $description = 'Send a test WhatsApp booking-confirmed template message';

    public function handle(): int
    {
        if (! WhatsApp::isConfigured()) {
            $this->error('WhatsApp is not configured. Check WHATSAPP_TOKEN + WHATSAPP_PHONE_NUMBER_ID in .env.');

            return self::FAILURE;
        }

        $raw = (string) $this->argument('phone');
        $to = WhatsApp::normalisePhone($raw);
        $template = (string) ($this->option('template') ?: config('services.whatsapp.template', 'booking_confirmed'));
        $lang = (string) ($this->option('lang') ?: config('services.whatsapp.language', 'en'));

        $this->info("Sending template [{$template}] ({$lang}) to {$to} …");

        // Dummy params in the same {{1}}…{{6}} order as sendBookingConfirmed().
        $ok = WhatsApp::sendTemplate($to, $template, $lang, [
            'Test Guest',
            'Haircut',
            now()->toDateString(),
            '10:00 AM to 11:00 AM',
            number_format(199.00, 2),
            'APT-TEST123',
        ]);

        if ($ok) {
            $this->info('Sent. Check WhatsApp on that number.');

            return self::SUCCESS;
        }

        $this->error('Send failed — see storage/logs/laravel.log for Meta error body (usually: template not approved / name mismatch / param count mismatch / recipient not allowed in test mode).');

        return self::FAILURE;
    }
}
