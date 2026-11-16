<?php

declare(strict_types=1);

/*
| Stateless routes for provider webhooks, registered in bootstrap/app.php outside
| the `web` group: no session, no CSRF, and their own `webhooks` rate limiter
| (docs/adr/0008-webhook-verification-and-dedup.md).
|
| POST /webhooks/pixelforge arrives with spec 003-webhooks-and-reconciliation.
*/
