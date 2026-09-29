<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Test Mode credentials only — see App\Support\Razorpay. The secret is
    // never sent to the frontend or returned in any API response.
    'razorpay' => [
        'key' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),
    ],

    // Customer "Continue with Google" sign-in (Laravel Socialite). The secret
    // is never sent to the frontend or returned in any API response. Blank
    // client_id/secret means Google sign-in is not configured — the
    // GoogleAuthController reports that cleanly instead of calling Socialite.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // WhatsApp Cloud API (Meta) — booking confirmations via App\Support\WhatsApp.
    // Token + phone_number_id come from Meta Dashboard → WhatsApp → API Setup.
    // Blank token/id disables sending silently (booking itself is unaffected).
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'waba_id' => env('WHATSAPP_WABA_ID'),
        'template' => env('WHATSAPP_TEMPLATE_BOOKING_CONFIRMED', 'booking_confirmed'),
        // Sent when staff mark an appointment "Paid in full" (name, service, total, reference).
        'template_paid' => env('WHATSAPP_TEMPLATE_PAYMENT_RECEIVED', 'payment_received'),
        // Sent when a shop order's payment is verified (name, order no, items, total) with the bill PDF.
        'template_order' => env('WHATSAPP_TEMPLATE_ORDER_PAID', 'order_paid'),
        // Only if that template's language differs from WHATSAPP_TEMPLATE_LANG (e.g. "en" vs "en_US").
        'template_order_language' => env('WHATSAPP_TEMPLATE_ORDER_PAID_LANG'),
        'language' => env('WHATSAPP_TEMPLATE_LANG', 'en'),
        'enabled' => env('WHATSAPP_ENABLED', true),
        // Owner alerts: same booking/order events, but sent to the studio's
        // own number (WHATSAPP_OWNER_PHONE, e.g. 918072427484). Templates
        // below carry customer name + phone + date/time/service (booking)
        // and name + phone + order no + items + total (order).
        'owner_phone' => env('WHATSAPP_OWNER_PHONE'),
        'template_owner_booking' => env('WHATSAPP_TEMPLATE_OWNER_BOOKING', 'owner_booking_alert_v2'),
        'template_owner_order' => env('WHATSAPP_TEMPLATE_OWNER_ORDER', 'owner_order_alert'),
        // Webhook (POST/GET /api/whatsapp/webhook): the string you type as "Verify token" in
        // Meta's Configuration screen, and the app secret (App settings → Basic) that signs events.
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

];
