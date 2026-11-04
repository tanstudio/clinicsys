<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || DB::table('branches')->exists()) {
    fwrite(STDERR, "Requires a dedicated, migrated test database without branches.\n");
    exit(1);
}
config(['session.driver'=>'array','cache.default'=>'array']);
$cookies = [];
$token = '';
function visit(string $method, string $path, array $data, int $expected): string {
    global $cookies, $token;
    $request = Request::create($path,$method,$data+['_token'=>$token],$cookies);
    $kernel = app(Kernel::class);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== $expected) throw new RuntimeException("$method $path returned ".$response->getStatusCode());
    foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();
    $html = (string)$response->getContent();
    if (preg_match('/name="csrf-token" content="([^"]+)"/',$html,$match)) $token=$match[1];
    $kernel->terminate($request,$response);
    return $html;
}
DB::beginTransaction();
try {
    $user=['email'=>'hq@example.test','name'=>'HQ','role'=>'admin','password_hash'=>Hash::make('test-only-fixture')];
    DB::table('staff_accounts')->insert($user);
    visit('GET','/staff/login',[],200);
    visit('POST','/staff/login',['email'=>$user['email'],'password'=>'test-only-fixture'],302);
    if (!str_contains(visit('GET','/staff',[],200), '分店管理')) throw new RuntimeException('Missing headquarters entry');
    visit('GET','/staff/branches',[],200);
    visit('GET','/staff/inventory',[],302);
    foreach (['First','Second','First'] as $name) {
        visit('POST','/staff/branches',['name_zh'=>$name,'name_en'=>$name],302);
    }
    if (DB::table('branches')->count() !== 2 || DB::table('slots')->exists()) throw new RuntimeException('Incorrect branch or schedule initialization');
    if (!DB::table('staff_access_log')->whereNull('branch_id')->exists()) throw new RuntimeException('Missing HQ audit trail');
    visit('GET','/staff/inventory',[],200);
    visit('POST','/staff/logout',[],302);
    $user['email']='branch@example.test';
    $user['branch_id']=DB::table('branches')->value('id');
    DB::table('staff_accounts')->insert($user);
    visit('GET','/staff/login',[],200);
    visit('POST','/staff/login',['email'=>$user['email'],'password'=>'test-only-fixture'],302);
    visit('GET','/staff/branches',[],403);
    visit('POST','/staff/branches',['name_zh'=>'Denied','name_en'=>'Denied'],403);
    echo "PASS empty HQ dashboard, audited access, first and subsequent branches, duplicate rejection, empty schedule and branch-admin denial\n";
} finally {
    DB::rollBack();
}
