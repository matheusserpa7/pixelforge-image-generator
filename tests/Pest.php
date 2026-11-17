<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests boot Laravel and run against the MySQL `testing` database
| (docs/adr/0017-tests-run-on-mysql.md). Unit and architecture tests are plain PHP.
*/
require_once __DIR__.'/Support/Webhooks.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
