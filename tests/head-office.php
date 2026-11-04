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
    $first=DB::table('branches')->where('name_zh','First')->value('id');
    $second=DB::table('branches')->where('name_zh','Second')->value('id');
    foreach (['practitioners','services'] as $type) {
        visit('GET','/staff/'.$type,[],200);
        visit('POST','/staff/'.$type,['expected_branch'=>$second,'name_zh'=>'Second only','name_en'=>'Second only'],302);
        $foreign[$type]=DB::table($type)->value('id');
    }
    $attrs=['name'=>'Reception','email'=>'reception@example.test','role'=>'reception','active'=>1,'password'=>'initial-passphrase'];
    visit('POST','/staff/accounts',$attrs+['expected_branch'=>$second],302);
    $foreignAccount=DB::table('staff_accounts')->where('email',$attrs['email'])->value('id');
    visit('POST','/staff/logout',[],302);
    $user['email']='branch@example.test';
    $user['branch_id']=DB::table('branches')->value('id');
    DB::table('staff_accounts')->insert($user);
    visit('GET','/staff/login',[],200);
    visit('POST','/staff/login',['email'=>$user['email'],'password'=>'test-only-fixture'],302);
    visit('GET','/staff/branches',[],403);
    visit('POST','/staff/branches',['name_zh'=>'Denied','name_en'=>'Denied'],403);
    foreach (['practitioners','services'] as $type) {
        $base='/staff/'.$type;
        if (str_contains(visit('GET',$base,[],200),'Second only')) throw new RuntimeException('Foreign listing visible');
        visit('POST',$base.'/'.$foreign[$type],['expected_branch'=>$first,'active'=>0],404);
        visit('POST',$base,['expected_branch'=>$second,'name_zh'=>'Wrong','name_en'=>'Wrong'],409);
        visit('POST',$base,['expected_branch'=>$first,'name_zh'=>'Local','name_en'=>'Local'],302);
        $own[$type]=DB::table($type)->where('name_zh','Local')->value('id');
        visit('POST',$base.'/'.$own[$type],['expected_branch'=>$first,'name_zh'=>'Updated','name_en'=>'Updated'],302);
        visit('POST',$base.'/'.$own[$type],['expected_branch'=>$first,'active'=>0],302);
        visit('POST',$base.'/'.$own[$type],['expected_branch'=>$first,'active'=>1],302);
    }
    $slot=['date'=>today('Asia/Hong_Kong')->addDay()->toDateString(),'time'=>'11:00','practitioner_id'=>$own['practitioners'],'service_id'=>$own['services']];
    visit('POST','/staff/schedule/slots',array_replace($slot,['service_id'=>$foreign['services']]),422);
    visit('POST','/staff/schedule/slots',$slot,302);
    visit('POST','/staff/services/'.$own['services'],['expected_branch'=>$first,'active'=>0],302);
    if (!DB::table('branch_services')->where('branch_id',$first)->value('active')) throw new RuntimeException('Active schedule lost');
    visit('POST','/staff/accounts/'.$foreignAccount,$attrs+['expected_branch'=>$first],404);
    $attrs['email']='own@example.test';
    $attrs['expected_branch']=$first;
    visit('POST','/staff/accounts',array_replace($attrs,['role'=>'admin']),302);
    if (DB::table('staff_accounts')->where('email',$attrs['email'])->exists()) throw new RuntimeException('Privilege escalation');
    visit('POST','/staff/accounts',$attrs,302);
    $id=DB::table('staff_accounts')->where('email',$attrs['email'])->value('id');
    $adminCookies=$cookies;
    $adminToken=$token;
    $cookies=[];
    visit('GET','/staff/login',[],200);
    visit('POST','/staff/login',['email'=>$attrs['email'],'password'=>$attrs['password']],302);
    visit('GET','/staff/accounts',[],403);
    $receptionCookies=$cookies;
    $cookies=$adminCookies;
    $token=$adminToken;
    visit('POST','/staff/accounts/'.$id,array_replace($attrs,['password'=>'replacement-passphrase']),302);
    $cookies=$receptionCookies;
    visit('GET','/staff',[],302);
    echo "PASS branch-scoped listing and updates, stale branch, foreign schedule rejection, future slot protection, role escalation denial and password reset revocation\n";
    echo "PASS empty HQ dashboard, audited access, first and subsequent branches, duplicate rejection, empty schedule and branch-admin denial\n";
} finally {
    DB::rollBack();
}
