<?php

declare(strict_types=1);

it('sends the security headers on pages', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('only allows scripts that carry the request nonce', function () {
    $response = $this->get(route('home'));

    $policy = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $policy, $matches);

    expect($policy)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->and($matches[1] ?? null)->not->toBeNull();

    $response->assertSee('nonce="'.$matches[1].'"', escape: false);
});

it('enables HSTS in production only', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->get(route('login'))
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
