<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) exit(1);
$original = $app->environment();
try {
    foreach (['testing', 'production'] as $environment) {
        $app->instance('env', $environment);
        $privacy = view('privacy')->render();
        $booking = view('booking')->render();
        $sample = $environment === 'testing';
        if (str_contains($privacy,'本機示範只供測試') !== $sample
            || str_contains($privacy,'local demonstration') !== $sample
            || str_contains($booking,'class="sample"') !== $sample
            || !str_contains($privacy,'+86') || !str_contains($privacy,'+852')) {
            throw new RuntimeException('Patient notice environment or phone-region mismatch');
        }
    }
    echo "PASS privacy rendering: demo notices only in local/testing; production omits demo claims; both phone regions disclosed. This is not legal acceptance.\n";
} finally { $app->instance('env', $original); }
