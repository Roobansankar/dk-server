<?php

namespace App\Console\Commands;

use App\Support\WhatsApp;
use Illuminate\Console\Command;

/**
 * Quick manual test:
 *   php artisan whatsapp:test 9876543210
 *   php artisan whatsapp:test owner --kind=booking   # to WHATSAPP_OWNER_PHONE
 *   php artisan whatsapp:test owner --kind=order
 * Sends the live template to that number.
 */
class WhatsAppTest extends Command
{
    protected $signature = 'whatsapp:test
        {phone : Recipient mobile (10-digit Indian or full E.164 without +), or "owner" for WHATSAPP_OWNER_PHONE}
        {--template= : Override template name (default from config)}
        {--lang= : Override language code (default from config)}
        {--kind=booking : Which payload shape to send: booking|order}';

    protected $description = 'Send a test WhatsApp template message (customer or owner alert)';

    public function handle(): int
    {
        if (! WhatsApp::isConfigured()) {
            $this->error('WhatsApp is not configured. Check WHATSAPP_TOKEN + WHATSAPP_PHONE_NUMBER_ID in .env.');

            return self::FAILURE;
        }

        $raw = (string) $this->argument('phone');
        if (strtolower($raw) === 'owner') {
            $to = WhatsApp::ownerPhone();
            if ($to === null) {
                $this->error('WHATSAPP_OWNER_PHONE missing/invalid in .env.');

                return self::FAILURE;
            }
        } else {
            $to = WhatsApp::normalisePhone($raw);
        }

        $kind = strtolower((string) $this->option('kind'));
        $lang = (string) ($this->option('lang') ?: config('services.whatsapp.language', 'en'));

        if ($kind === 'order') {
            $template = (string) ($this->option('template') ?: config('services.whatsapp.template_owner_order', 'owner_order_alert'));
            // {{1}} name {{2}} phone {{3}} order no {{4}} items {{5}} total
            $params = ['Test Guest', '9876543210', 'ORD-TEST123', 'Pro-1 x2, Hair Pack x1', number_format(499.00, 2)];
        } else {
            $template = (string) ($this->option('template') ?: config('services.whatsapp.template', 'booking_confirmed'));
            // Dummy params in the same {{1}}…{{6}} order as sendBookingConfirmed().
            // For owner test use: --template=owner_booking_alert_v2 (adds customer phone + stylist → 7 params).
            if ($template === config('services.whatsapp.template_owner_booking', 'owner_booking_alert_v2')) {
                $params = ['Test Guest', '9876543210', 'Haircut', now()->toDateString(), '10:00 AM to 11:00 AM', 'APT-TEST123', 'Devi K'];
            } else {
                $params = [
                    'Test Guest',
                    'Haircut',
                    now()->toDateString(),
                    '10:00 AM to 11:00 AM',
                    number_format(199.00, 2),
                    'APT-TEST123',
                ];
            }
        }

        $this->info("Sending template [{$template}] ({$lang}) kind={$kind} to {$to} …");

        $ok = WhatsApp::sendTemplate($to, $template, $lang, $params);

        if ($ok) {
            $this->info('Sent. Check WhatsApp on that number.');

            return self::SUCCESS;
        }

        $this->error('Send failed — see storage/logs/laravel.log for Meta error body (usually: template not approved / name mismatch / param count mismatch / recipient not allowed in test mode).');

        return self::FAILURE;
    }
}
