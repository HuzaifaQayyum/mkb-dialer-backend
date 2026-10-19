<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'asterisk' => [
        'url' => env('ASTERISK_ARI_URL', 'http://127.0.0.1:8088'),
        'user' => env('ASTERISK_ARI_USER', 'mkb_user'),
        'password' => env('ASTERISK_ARI_PASSWORD', 'mkb_pass'),
        'app' => env('ASTERISK_STASIS_APP', 'mkb-dialer'),
        'trunk' => env('ASTERISK_TRUNK', 'telnyx-trunk'),
        'sip_domain' => env('TELNYX_SIP_DOMAIN', 'sip.telnyx.com'),
        // Host side of the volume Asterisk writes recordings into.
        'recording_path' => env('ASTERISK_RECORDING_PATH', base_path('../asterisk-recordings')),

        // Browser softphone (WebRTC) registration details.
        'ws_url' => env('ASTERISK_WS_URL', 'ws://127.0.0.1:8088/ws'),
        'sip_realm' => env('ASTERISK_SIP_REALM', 'mkb-dialer'),
        // Agent SIP passwords follow a fixed pattern in pjsip.conf:
        // agent<extension>pass. Change both together.
        'agent_password_prefix' => env('ASTERISK_AGENT_PW_PREFIX', 'agent'),
        'agent_password_suffix' => env('ASTERISK_AGENT_PW_SUFFIX', 'pass'),
    ],

    'dialer' => [
        // Account-specific: set DIALER_CALLER_ID in .env to a number on your
        // carrier account. Telnyx rejects calls whose From user it doesn't own.
        'caller_id' => env('DIALER_CALLER_ID'),
        // Country code used to resolve national-format numbers (leading 0, or
        // bare local numbers). Leave empty to refuse them rather than guess —
        // a wrong guess dials a real stranger.
        'default_country_code' => env('DIALER_DEFAULT_COUNTRY_CODE', ''),
        // Hard ceiling the pacer will never exceed, whatever a session asks for.
        'max_concurrency' => (int) env('DIALER_MAX_CONCURRENCY', 10),
        // Seconds to ring a lead before giving up.
        'lead_timeout' => (int) env('DIALER_LEAD_TIMEOUT', 30),
        // Don't re-dial the same contact within this many hours.
        'redial_cooldown_hours' => (int) env('DIALER_REDIAL_COOLDOWN_HOURS', 24),
    ],

];
