<?php

use App\Models\ClinicalTemplate;
use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (!$app->environment('local', 'testing')) {
    exit(1);
}
config(['session.driver' => 'array', 'cache.default' => 'array']);

function check(bool $passed, string $label): void
{
    if (!$passed) {
        throw new RuntimeException($label);
    }
}

class ClinicalBrowser
{
    private array $cookies = [];
    private string $csrf = '';

    public function visit(string $method, string $path, array $data = [], int $expected = 200, bool $htmlError = false): string
    {
        $request = Request::create($path, $method, $data + ['_token' => $this->csrf], $this->cookies,
            [], ['HTTP_ACCEPT' => $expected >= 400 && !$htmlError ? 'application/json' : 'text/html']);
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        $html = (string)$response->getContent();
        check($response->getStatusCode() === $expected, "$method $path expected $expected, got ".$response->getStatusCode()."\n".strip_tags(substr($html,0,1000)));
        if (($expected === 200 || $htmlError) && !str_contains($path, '/login')) {
            check($response->headers->hasCacheControlDirective('no-store'), 'Missing no-store header');
        }
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $match)) {
            $this->csrf = $match[1];
        }
        $kernel->terminate($request, $response);
        return $html;
    }

    public function login(string $email): void
    {
        $this->visit('GET','/staff/login');
        $this->visit('POST','/staff/login',['email' => $email, 'password' => 'synthetic-test-secret'],302);
        $this->visit('GET','/staff');
    }
}

DB::beginTransaction();
try {
    [$branch, $otherBranch] = DB::table('branches')->orderBy('id')->limit(2)->pluck('id')->all();
    $accounts = [];
    foreach ([['doctor',$branch],['reception',$branch],['doctor',$otherBranch],['admin',null]] as [$role,$branchId]) {
        $email = Str::uuid().'@example.test';
        DB::table('staff_accounts')->insert(['branch_id' => $branchId, 'name' => 'QA', 'role' => $role, 'email' => $email,
            'password_hash' => Hash::make('synthetic-test-secret')]);
        $accounts[] = $email;
    }
    $doctor = new ClinicalBrowser();
    $doctor->login($accounts[0]);
    $person = Patient::create(['identity_key' => hash('sha256', Str::uuid()), 'name' => 'CRM QA '.Str::random(6), 'phone' => '+85291234567']);
    $slots = DB::table('slots')->where('branch_id',$branch)->whereNotIn('id',DB::table('appointments')->select('slot_id'))
        ->limit(3)->pluck('id');
    check(count($slots) === 3,'Three unoccupied fixture slots required.');
    $visits = [];
    foreach ($slots as $slot) {
        $visits[] = $person->appointments()->create(['slot_id' => $slot, 'reference' => (string)Str::uuid()]);
    }
    $visits[0]->update(['email' => 'synthetic-patient@example.test']);
    $crm = '/staff/patients/'.$person->id;
    $tag = 'CRM QA tag';
    $segment = 'QA segment';
    $doctor->visit('POST', "$crm/profile", compact('segment') + ['tags' => $tag],302);
    check(str_contains($doctor->visit('POST','/staff/patients',['q' => $tag, 'segment' => $segment]), $person->name), 'CRM segment filter');
    $doctor->visit('POST', "$crm/followups", ['due_on' => '2026-12-30', 'note' => 'Private follow-up'],302);
    $item = DB::table('patient_followups')->where('patient_id',$person->id)->firstOrFail();
    check(!str_contains($item->note,'Private follow-up'), 'Followup stored plaintext');
    $doctor->visit('POST',"$crm/followups/$item->id/complete",[],302);
    $threadId = DB::table('message_threads')->insertGetId(['branch_id' => $branch, 'patient_id' => $person->id, 'phone' => encrypt($person->phone),
        'thread_key' => hash('sha256', Str::uuid()), 'contact_key' => hash('sha256',Str::uuid()), 'category' => 'general']);
    DB::table('appointment_events')->insert(['appointment_id' => $visits[0]->id, 'event' => 'rescheduled',
        'from_slot_id' => $slots[1], 'to_slot_id' => $slots[0], 'created_at' => now()]);
    $profile = $doctor->visit('GET',$crm);
    check(str_contains($profile,'更改時段'), 'Reschedule history missing');
    check(str_contains($profile,'已完成') && str_contains($profile,$visits[0]->reference) && str_contains($profile,'synthetic-patient@example.test') && str_contains($profile,"/staff/inbox/$threadId"),'CRM history');
    $record = ['symptoms' => ['疼痛'], 'notes' => 'QA protected note', 'diagnosis' => 'QA A', 'prescription' => 'QA prescription', 'plan' => 'QA plan'];
    $doctor->visit('POST','/staff/encounters/templates', $record + ['name' => 'QA guidance'],302);
    $template = ClinicalTemplate::where('branch_id',$branch)->latest('id')->firstOrFail();
    check(!str_contains($template->getRawOriginal('clinical_record'),'QA prescription'),'Unencrypted template');
    $new = '/staff/encounters/new/'.$visits[0]->id;
    check(str_contains($doctor->visit('GET',$new.'?template='.$template->id), 'QA protected note'),'Template prefill');
    $doctor->visit('POST',$new,['diagnosis' => 'QA', 'symptoms' => ['invalid']],422);
    $invalid = array_replace($record, ['diagnosis' => '', 'notes' => '<script>clinical-private-marker</script>']);
    $retry = $doctor->visit('POST', $new, $invalid, 422, true);
    check(str_contains($retry, '病歷尚未儲存') && str_contains($retry, 'aria-invalid="true"'), 'Missing inline validation');
    check(str_contains($retry, '&lt;script&gt;clinical-private-marker&lt;/script&gt;') && !str_contains($retry, '<script>clinical-private-marker'), 'Clinical input must be preserved and escaped');
    check(str_contains($retry, 'QA prescription') && str_contains($retry, 'value="疼痛" checked'), 'Retry lost valid fields');
    check(!Encounter::where('appointment_id', $visits[0]->id)->exists(), 'Invalid record persisted');
    check(!str_contains(serialize(app('session')->all()), 'clinical-private-marker'), 'Clinical text leaked to session');
    $doctor->visit('POST', $new, ['diagnosis' => ['invalid'], 'symptoms' => [['invalid']]], 422, true);
    $fresh = $doctor->visit('GET', $new);
    check(!str_contains($fresh, 'clinical-private-marker'), 'Clinical text carried into a new request');
    $doctor->visit('POST',$new,$record,302);
    $doctor->visit('POST',$new,$record,409);
    $first = Encounter::where('appointment_id',$visits[0]->id)->sole();
    check(!str_contains($first->getRawOriginal('clinical_record'),'QA protected note'),'Unencrypted encounter');
    $record['diagnosis'] = 'QA B';
    $doctor->visit('POST','/staff/encounters/new/'.$visits[1]->id,$record,302);
    $second = Encounter::where('appointment_id',$visits[1]->id)->sole();
    $found = $doctor->visit('POST','/staff/encounters',['q' => 'QA prescription', 'patient' => $person->id]);
    check(str_contains($found, 'QA A') && str_contains($found,'QA B'),'Search all recorded visits');
    $comparison = $doctor->visit('GET','/staff/encounters/'.$second->id.'?compare='.$first->id);
    check(str_contains($comparison,'QA A') && str_contains($comparison,'QA B'),'Visit comparison');
    DB::table('appointments')->where('id',$visits[2]->id)->update(['cancelled_at' => now()]);
    $doctor->visit('POST','/staff/encounters/new/'.$visits[2]->id,$record,404);
    $reception = new ClinicalBrowser();
    $reception->login($accounts[1]);
    check(!str_contains($reception->visit('GET',$crm),'/staff/encounters'), 'Reception sees clinical access links');
    $reception->visit('GET','/staff/encounters/'.$first->id, [],403);
    $outside = new ClinicalBrowser();
    $outside->login($accounts[2]);
    $outside->visit('GET',$crm,[],404);
    $outside->visit('POST',"$crm/profile",['tags' => 'outside'],404);
    $outside->visit('GET','/staff/encounters/'.$first->id, [],404);
    $outside->visit('GET','/staff/patients?scope=all',[],403);
    $outside->visit('POST',"$crm/open-branch",['branch_id' => $branch],403);
    $secondPerson = Patient::create(['identity_key' => hash('sha256',Str::uuid()), 'name' => 'Other '.Str::random(6), 'phone' => '+85292345678']);
    $otherPatientSlot = DB::table('slots')->where('branch_id',$branch)
        ->whereNotIn('id',DB::table('appointments')->select('slot_id'))->value('id');
    $otherBooking = $secondPerson->appointments()->create(['slot_id' => $otherPatientSlot, 'reference' => (string)Str::uuid()]);
    $doctor->visit('POST','/staff/encounters/new/'.$otherBooking->id,$record,302);
    $otherEncounter = Encounter::where('appointment_id', $otherBooking->id)->sole();
    $doctor->visit('GET', '/staff/encounters/'.$first->id.'?compare='.$otherEncounter->id, [],404);
    $otherSlot = DB::table('slots')->where('branch_id',$otherBranch)
        ->whereNotIn('id',DB::table('appointments')->select('slot_id'))->value('id');
    $secondPerson->appointments()->create(['slot_id' => $otherSlot, 'reference' => (string)Str::uuid()]);
    $hq = new ClinicalBrowser();
    $hq->login($accounts[3]);
    $all = $hq->visit('GET','/staff/patients?scope=all');
    check(str_contains($all,$person->name) && str_contains($all,$secondPerson->name),'Consolidated patient directory missed branch');
    $sharedSlot = DB::table('slots')->where('branch_id',$otherBranch)
        ->whereNotIn('id',DB::table('appointments')->select('slot_id'))->value('id');
    $person->appointments()->create(['slot_id' => $sharedSlot, 'reference' => (string)Str::uuid()]);
    $both = $hq->visit('POST','/staff/patients',['scope' => 'all', 'q' => $person->name]);
    check(substr_count($both,"$crm/open-branch") === 2, 'Shared patient must have two branch histories');
    $hq->visit('POST','/staff/patients/'.$secondPerson->id.'/open-branch',['branch_id' => $otherBranch],302);
    check(str_contains($hq->visit('GET','/staff/patients/'.$secondPerson->id),$secondPerson->name),'HQ scoped patient detail');
    echo "PASS consolidated HQ directory, CRM filters, appointments, linked messaging, follow-up, clinical template, encrypted storage, search, comparison, duplicates, cancelled bookings and role/branch access\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    $failed = true;
} finally {
    DB::rollBack();
}
exit(isset($failed) ? 1 : 0);
