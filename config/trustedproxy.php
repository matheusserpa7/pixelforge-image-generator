<?php

declare(strict_types=1);

return [
    // Comma-separated IPs or CIDRs allowed to set X-Forwarded-* headers. Empty: trust nobody.
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),
];
