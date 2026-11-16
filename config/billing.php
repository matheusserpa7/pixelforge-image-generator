<?php

declare(strict_types=1);

return [

    /*
    | Percentage added on top of the Pixelforge cost to get what the user pays,
    | as a decimal string (no floats). See docs/adr/0004-markup-and-rounding.md.
    */
    'markup_percent' => (string) env('BILLING_MARKUP_PERCENT', '25'),

];
