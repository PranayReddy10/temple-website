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

    /*
     * The temple research agent (temples:agent). "free" (the default) uses
     * only free open sources: Wikidata, Wikipedia, OpenStreetMap and each
     * temple's own website, with no API key and no cost. "claude" uses
     * Claude with web search instead, which is paid API usage and needs
     * ANTHROPIC_API_KEY.
     */
    'temple_agent' => env('TEMPLE_AGENT', 'free'),

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('TEMPLE_AGENT_MODEL', 'claude-opus-5-5'),
        'effort' => env('TEMPLE_AGENT_EFFORT', 'high'),
    ],

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

];
