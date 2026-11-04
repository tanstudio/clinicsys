<?php

use App\Actions\HrAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (!$app->environment('local', 'testing')) {
    exit(1);
}
$app->instance('env', 'testing');
config(['cache.default' => 'array', 'session.driver' => 'array']);
$cookies = [];
function hit(string $method, string $url, int $expected, array $data = [], array $files = []): string
{
    global $cookies, $kernel;
    $request = Request::create($url, $method, $data, $cookies, $files, ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("$method $url expected $expected, got ".$response->getStatusCode()."\n".$response->getContent());
    }
    return $response->getContent();
}
function hrCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
DB::beginTransaction();
try {
    $keys = DB::table('branches')->limit(2)->pluck('id');
    hrCheck(count($keys) === 2, 'Two seeded branches required');
    $suffix = Str::random(10);
    $password = Str::random(24);
    $hash = Hash::make($password);
    foreach (['hr','doctor','reception'] as $role) {
        $email = "$role.$suffix@example.test";
        $ids[$role] = DB::table('staff_accounts')->insertGetId([
            'branch_id' => $keys[$role === 'reception' ? 1 : 0], 'name' => $role,
            'email' => $email, 'password_hash' => $hash, 'role' => $role,
        ]);
        $emails[$role] = $email;
    }
    $login = function ($role) use ($emails, $password) {
        hit('POST', '/staff/login', 302, ['email' => $emails[$role], 'password' => $password]);
    };
    $person = '/staff/hr/people/'.$ids['doctor'];
    $other = '/staff/hr/people/'.$ids['reception'];
    hit('GET', '/staff/hr', 401);
    $login('hr');
    hit('GET', $person, 200);
    hit('GET', $other, 404);
    hit('POST', "$person/profile", 302, ['identity' => 'QA ID', 'address' => 'QA ADDRESS', 'emergency_name' => 'QA CONTACT']);
    $profile = DB::table('hr_profiles')->where('staff_id', $ids['doctor'])->value('details');
    hrCheck(!str_contains($profile, 'QA ID') && decrypt($profile)['identity'] === 'QA ID', 'Profile encryption');
    foreach (['job','pay','training','performance'] as $kind) {
        hit('POST', "$person/history", 302, ['kind' => $kind, 'effective_on' => '2026-01-01', 'details' => 'QA '.$kind]);
    }
    $pdf = "%PDF-1.4\nQA fixture\n%%EOF";
    foreach (['contract','tax'] as $kind) {
        $file = UploadedFile::fake()->createWithContent('文件.pdf', $pdf);
        hit('POST', "$person/documents", 302, ['kind' => $kind], ['attachment' => $file]);
    }
    $doc = DB::table('hr_documents')->where('staff_id', $ids['doctor'])->first();
    hrCheck($doc->contents !== $pdf && !str_contains($doc->filename, '文件'), 'File encryption');
    hrCheck(hit('GET', "$person/documents/$doc->id", 200) === $pdf, 'Authorized download');
    $now = CarbonImmutable::now('Asia/Hong_Kong')->startOfMinute();
    $shiftData = [
        'kind' => 'flex', 'starts_at' => $now->subMinutes(30)->format('Y-m-d\TH:i'),
        'latest_start' => $now->subMinutes(10)->format('Y-m-d\TH:i'), 'required_minutes' => 60,
    ];
    hit('POST', "$person/shifts", 302, $shiftData);
    hit('POST', "$person/shifts", 422, $shiftData);
    $shift = DB::table('hr_shifts')->where('staff_id', $ids['doctor'])->value('id');
    hit('POST', "$person/shifts/$shift/clock", 403, ['action' => 'in']);
    $login('doctor');
    hit('GET', '/staff/hr/people/'.$ids['hr'], 404);
    hit('POST', "$person/history", 403, ['kind' => 'pay']);
    hit('POST', "$person/shifts/$shift/clock", 409, ['action' => 'out']);
    hit('POST', "$person/shifts/$shift/clock", 302, ['action' => 'in']);
    hit('POST', "$person/shifts/$shift/clock", 409, ['action' => 'in']);
    hit('POST', "$person/shifts/$shift/clock", 302, ['action' => 'out']);
    $summary = HrAttendance::summary(DB::table('hr_shifts')->where('id', $shift)->first());
    hrCheck($summary['late'] >= 10 && $summary['early'] >= 48 && $summary['overtime'] === 0, 'Late and early metrics');
    $fixed = (object)['starts_at'=>'2026-10-01 09:00', 'latest_start'=>'2026-10-01 09:00', 'clock_in'=>'2026-10-01 09:00', 'clock_out'=>'2026-10-01 18:00','required_minutes'=>480];
    hrCheck(HrAttendance::summary($fixed) === ['late'=>0,'early'=>0,'overtime'=>60], 'Fixed-shift overtime');
    foreach (['annual','sick','maternity'] as $index => $kind) {
        $day = '2026-12-0'.($index + 1);
        $fields = ['kind'=>$kind, 'starts_on'=>$day, 'ends_on'=>$day, 'reason'=>'QA leave'];
        hit('POST', "$person/leave", 302, $fields);
        hit('POST', "$person/leave", 422, $fields);
    }
    $leave = DB::table('hr_leave')->where('staff_id', $ids['doctor'])->orderBy('id')->pluck('id');
    hit('POST', "$person/leave/{$leave[0]}/review", 403, ['decision'=>'approved']);
    $login('hr');
    hit('POST', "$person/leave/{$leave[0]}/review", 302, ['decision'=>'approved']);
    hit('POST', "$person/leave/{$leave[0]}/review", 409, ['decision'=>'rejected']);
    hit('POST', "$person/leave/{$leave[1]}/review", 302, ['decision'=>'rejected']);
    $own = '/staff/hr/people/'.$ids['hr'];
    hit('POST', "$own/leave", 302, $fields);
    $ownLeave = DB::table('hr_leave')->where('staff_id', $ids['hr'])->value('id');
    hit('POST', "$own/leave/$ownLeave/review", 403, ['decision'=>'approved']);
    hrCheck(str_contains(hit('GET', $person, 200), 'QA training'), 'Render populated profile');
    $login('reception');
    hit('GET', "$person/documents/$doc->id", 404);
    hit('GET', "$other/documents/$doc->id", 404);
    echo "PASS HR profile encryption, four histories, two private file types, attendance, all three leave types, role and branch isolation\n";
} finally {
    DB::rollBack();
}
