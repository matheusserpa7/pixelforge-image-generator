<?php

declare(strict_types=1);

it('serves the public pages', function (string $route) {
    $this->get(route($route))->assertOk();
})->with(['login', 'register', 'health']);

it('sends the home page to the generations page', function () {
    $this->get(route('home'))->assertRedirect('/generations');
});

it('sends guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
