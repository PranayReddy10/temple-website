<?php

/**
 * The languages the product speaks.
 *
 * India's pilgrims do not share one. A devotee from Kanchipuram reading a
 * Telugu-only listing is being told the product is not for them, so the
 * language set is data here rather than a constant somewhere in a controller:
 * adding Odia is a line in this file plus translation rows, not a migration.
 *
 * `native` is what the language calls itself. A language picker that offers
 * "Telugu" to someone who reads Telugu has already failed them once.
 *
 * `rtl` is carried even though none of these are right-to-left, because Urdu
 * is a plausible addition and a layout that assumes direction is a layout
 * that has to be rewritten rather than extended.
 */
return [

    'fallback' => 'en',

    'supported' => [
        'en' => ['name' => 'English', 'native' => 'English', 'rtl' => false],
        'hi' => ['name' => 'Hindi', 'native' => 'हिन्दी', 'rtl' => false],
        'te' => ['name' => 'Telugu', 'native' => 'తెలుగు', 'rtl' => false],
        'ta' => ['name' => 'Tamil', 'native' => 'தமிழ்', 'rtl' => false],
        'kn' => ['name' => 'Kannada', 'native' => 'ಕನ್ನಡ', 'rtl' => false],
        'ml' => ['name' => 'Malayalam', 'native' => 'മലയാളം', 'rtl' => false],
        'mr' => ['name' => 'Marathi', 'native' => 'मराठी', 'rtl' => false],
        'bn' => ['name' => 'Bengali', 'native' => 'বাংলা', 'rtl' => false],
        'gu' => ['name' => 'Gujarati', 'native' => 'ગુજરાતી', 'rtl' => false],
        'or' => ['name' => 'Odia', 'native' => 'ଓଡ଼ିଆ', 'rtl' => false],
        'pa' => ['name' => 'Punjabi', 'native' => 'ਪੰਜਾਬੀ', 'rtl' => false],
        'as' => ['name' => 'Assamese', 'native' => 'অসমীয়া', 'rtl' => false],
    ],

    /*
     * Which languages the app ships with today, as opposed to which the
     * schema can hold. Phase 3 slice 15 commits to three; the rest are
     * translatable in the admin and will be enabled as coverage arrives,
     * because a language offered in the picker and then mostly blank reads
     * as neglect rather than as progress.
     */
    'launch' => ['en', 'te', 'hi'],
];
