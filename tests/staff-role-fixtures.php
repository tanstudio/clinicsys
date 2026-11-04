<?php

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $mode, $run] = $argv;
if (!$app->environment('local', 'testing') || !Str::isUuid($run)) exit(1);
$prefix = 'QA-Staff-'.$run;
$roles = ['doctor','reception','admin'];
$emails = array_map(fn ($role) => "$role.$run@example.test", $roles);
$names = ["$prefix-A", "$prefix-B"];
if ($mode === 'create') {
    $fixture = DB::transaction(function () use ($roles, $emails, $names, $prefix) {
        $branches = [];
        foreach ($names as $name) {
            $branches[] = DB::table('branches')->insertGetId(['name_zh'=>$name, 'name_en'=>$name]);
        }
        $password = Str::random(36);
        $hash = Hash::make($password);
        $users = [];
        foreach ($roles as $i => $role) {
            $name = "$prefix-$role";
            $users[$role] = ['id' => DB::table('staff_accounts')->insertGetId([
                'branch_id' => $branches[$i] ?? null, 'role'=>$role, 'name'=>$name, 'email'=>$emails[$i], 'password_hash'=>$hash,
            ]), 'email'=>$emails[$i], 'name'=>$name];
        }
        return compact('users','branches','password') + ['session_cookie'=>config('session.cookie')];
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
} elseif ($mode === 'check-session') {
    $cookie = rawurldecode(trim(stream_get_contents(STDIN)));
    $sessionId = CookieValuePrefix::remove(Crypt::decryptString($cookie));
    $session = app('session')->driver();
    $session->setId($sessionId);
    $session->start();
    $owner = DB::table('staff_accounts')->where('id', $session->get('staff_id'))->value('email');
    if ($owner !== $emails[0] || !$session->has('errors')) {
        throw new RuntimeException('Cannot verify validation flash for the synthetic doctor');
    }
    $sensitive = ['identity','address','phone','emergency_name','emergency_phone'];
    foreach ($sensitive as $field) {
        if ($session->hasOldInput($field)) throw new RuntimeException("Validation flashed $field");
    }
    echo 'Sensitive values excluded';
} elseif ($mode === 'cleanup') {
    DB::transaction(function () use ($emails, $names) {
        $ids = DB::table('staff_accounts')->whereIn('email', $emails)->pluck('id');
        DB::table('staff_access_log')->whereIn('staff_id', $ids)->delete();
        DB::table('staff_accounts')->whereIn('id', $ids)->delete();
        DB::table('branches')->whereIn('name_en', $names)->delete();
    });
} else {
    exit(1);
}
