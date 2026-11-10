<?php

// Private pre-deployment database dump. Runs on the server inside the new release directory.
// Credentials come from the shared .env through Laravel config and stay in a 0600 file removed after use.

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$c = config('database.connections.'.config('database.default'));
$quote = static fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';
$options = "[client]\nuser=".$quote($c['username'])."\npassword=".$quote($c['password'])."\nhost=".$quote($c['host'])."\n";
if (!empty($c['port'])) {
    $options .= 'port='.(int) $c['port']."\n";
}
$file = tempnam(__DIR__, 'mysql');
chmod($file, 0600);
file_put_contents($file, $options);
$out = __DIR__.'/pre-deploy-backup.sql';
// The server's mysqldump rejects --set-gtid-purged, so this release does not pass it.
$process = proc_open(['mysqldump', '--defaults-extra-file='.$file, '--single-transaction', '--no-tablespaces',
    $c['database']], [1 => ['file', $out, 'w'], 2 => ['pipe', 'w']], $pipes);
$error = stream_get_contents($pipes[2]);
$code = proc_close($process);
unlink($file);
if ($code !== 0 || !is_file($out) || filesize($out) < 1000 || !str_contains(file_get_contents($out, false, null, max(0, filesize($out) - 200)), 'Dump completed')) {
    fwrite(STDERR, 'FAIL private database backup: '.substr($error, 0, 300)."\n");
    exit(1);
}
chmod($out, 0600);
echo 'PASS private database backup ('.filesize($out)." bytes)\n";
