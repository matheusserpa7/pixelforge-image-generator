<?php

declare(strict_types=1);

it('serves the public pages', function (string $route) {
    $this->get(route($route))->assertOk();
})->with(['home', 'login', 'register', 'health']);

it('sends guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
