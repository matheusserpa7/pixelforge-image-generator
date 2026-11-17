<?php

declare(strict_types=1);

return [

    /*
    | Percentage added on top of the Pixelforge cost to get what the user pays,
    | as a decimal string (no floats). See docs/adr/0004-markup-and-rounding.md.
    */
    'markup_percent' => (string) env('BILLING_MARKUP_PERCENT', '25'),

    /*
    | How long GET /v1/pricing responses stay in the application cache (seconds).
    */
    'pricing_cache_seconds' => (int) env('BILLING_PRICING_CACHE_SECONDS', 300),

    /*
    | Fake "Buy credits" packs, USD decimal strings (no floats).
    */
    'credit_packs_usd' => ['5', '10', '25'],

];
