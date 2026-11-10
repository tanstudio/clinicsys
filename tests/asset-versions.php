<?php

// Stylesheets and scripts must carry a content version, because Cloudflare caches them for 4 hours.

use App\Support\Asset;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) exit(1);

function checkAsset(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL $message\n");
        exit(1);
    }
}

$expected = fn (string $path) => '/'.$path.'?v='.substr(hash_file('sha256', public_path($path)), 0, 12);
foreach (['booking.css', 'staff.css', 'staff-schedule.css', 'assets/booking.js', 'assets/enhance-selects.js'] as $path) {
    checkAsset(is_file(public_path($path)), "$path is missing; run npm run build");
    checkAsset(Asset::url($path) === $expected($path), "$path version does not match its content");
}
checkAsset(Asset::url('missing-asset.css') === '/missing-asset.css', 'A missing file must keep its plain URL');

$booking = view('booking')->render();
foreach (['booking.css', 'assets/booking.js', 'assets/enhance-selects.js'] as $path) {
    checkAsset(str_contains($booking, $expected($path)), "Booking page does not load the versioned $path");
}
checkAsset(str_contains(view('privacy')->render(), $expected('staff.css')), 'Privacy page does not load the versioned staff.css');

// A changed file must get a new URL. The cache is per process, so a fresh process sees the change.
$probe = public_path('asset-version-probe.css');
file_put_contents($probe, 'a{}');
$first = trim(shell_exec(PHP_BINARY.' -r '.escapeshellarg('require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Support\Asset::url("asset-version-probe.css");')));
file_put_contents($probe, 'b{}');
$second = trim(shell_exec(PHP_BINARY.' -r '.escapeshellarg('require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Support\Asset::url("asset-version-probe.css");')));
unlink($probe);
checkAsset(str_starts_with($first, '/asset-version-probe.css?v=') && $first !== $second, 'A changed file kept the same URL');

$plain = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
    if ($file->isFile() && preg_match_all('#(?:href|src)="/[a-z/.-]+\.(?:css|js)"#', file_get_contents($file), $m)) {
        $plain[] = $file->getFilename().': '.implode(', ', $m[0]);
    }
}
checkAsset($plain === [], 'Unversioned asset references: '.implode('; ', $plain));

echo "PASS asset versions: 5 of 5 public files carry content hashes, pages load them, a changed file gets a new URL, and no view uses a plain asset URL.\n";
