<?php

declare(strict_types=1);

/*
| Rules from CLAUDE.md that a reviewer should never have to check by hand.
*/

arch('every PHP file declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('classes are final by default')
    ->expect([
        'App\Actions',
        'App\Data',
        'App\Http\Controllers',
        'App\Http\Middleware',
        'App\Jobs',
        'App\Policies',
        'App\Providers',
        'App\Services',
        'App\Support',
    ])
    ->classes()
    ->toBeFinal()
    ->ignoring('App\Http\Controllers\Controller');

arch('controllers only orchestrate: no direct HTTP client calls')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client',
        'GuzzleHttp',
        'App\Services\Pixelforge\HttpPixelforgeClient',
    ]);

arch('money code never calls float functions')
    ->expect('App\Support\Money')
    ->not->toUse(['floatval', 'round', 'floor', 'ceil', 'fdiv', 'number_format', 'intdiv']);

arch('no debugging leftovers or unsafe functions')
    ->preset()->php();

arch('no insecure functions')
    ->preset()->security();

/**
 * @param  list<string>  $paths
 */
function expectNoFloatTokensInPhpPaths(array $paths): void
{
    $files = [];

    foreach ($paths as $path) {
        $matches = glob($path) ?: [];
        $files = [...$files, ...$matches];
    }

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $floats = collect(token_get_all((string) file_get_contents($file)))
            ->filter(fn (mixed $token): bool => is_array($token) && (
                $token[0] === T_DOUBLE_CAST
                || $token[0] === T_DNUMBER
                || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['float', 'floatval'], true))
            ));

        expect($floats)->toBeEmpty("Float found in {$file}");
    }
}

test('money code has no float types, casts or literals', function () {
    expectNoFloatTokensInPhpPaths([__DIR__.'/../../app/Support/Money/*.php']);
});

test('billing and pixelforge code has no float types, casts or literals', function () {
    expectNoFloatTokensInPhpPaths([
        __DIR__.'/../../app/Services/Billing/*.php',
        __DIR__.'/../../app/Services/Pixelforge/*.php',
    ]);
});
