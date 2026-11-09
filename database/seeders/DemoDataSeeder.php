<?php

namespace Database\Seeders;

use App\Actions\CancelAppointment;
use App\Actions\CreateAppointment;
use App\Actions\HrAttendance;
use App\Actions\RescheduleAppointment;
use App\Messaging\Delivery;
use App\Messaging\Inbox;
use App\Messaging\NotificationQueue;
use App\Models\ClinicalTemplate;
use App\Models\Encounter;
use App\Models\Patient;
use App\Support\PatientPhone;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demonstration customers, bookings and clinical history stored as real database rows.
 * Future bookings go through the application's own booking actions.
 * Past visits are inserted directly because CreateAppointment only accepts future slots.
 * Every CRM profile carries the "示範數據" tag so DemoDataPurgeSeeder can find and remove the data set.
 * Bookings carry no email and no WhatsApp consent, so the outbox suppresses every notification.
 */
class DemoDataSeeder extends Seeder
{
    public const TAG = '示範數據';
    public const PATIENTS = 50;
    public const HISTORY_DAYS = 60;

    private const BRANCHES = [['中環', 'Central'], ['旺角', 'Mong Kok'], ['銅鑼灣', 'Causeway Bay']];
    private const SERVICES = [['中醫內科', 'Chinese medicine'], ['針灸', 'Acupuncture'], ['推拿', 'Tui na'], ['拔罐', 'Cupping']];
    // Practitioner => [home branch index, service indexes].
    private const PRACTITIONERS = [
        ['陳醫師', 'Dr Chan', 0, [0, 1]], ['林醫師', 'Dr Lam', 1, [0, 1]], ['黃醫師', 'Dr Wong', 2, [0, 2]],
        ['何醫師', 'Dr Ho', 0, [2, 3]], ['張醫師', 'Dr Cheung', 1, [1, 3]],
    ];
    // Past schedule: practitioner index => [[weekday (0 = Sunday), branch index, half day]]. Branches get 3, 3 and 4 sessions a week.
    private const PAST_SESSIONS = [
        [[1, 0, 'am'], [3, 0, 'pm']], [[1, 1, 'pm'], [4, 1, 'am']], [[2, 2, 'pm'], [5, 2, 'pm']],
        [[2, 0, 'am'], [6, 2, 'am']], [[5, 1, 'am'], [3, 2, 'am']],
    ];
    private const SESSION_TIMES = ['am' => ['09:30', '10:00', '10:30', '11:00'], 'pm' => ['14:30', '15:00', '15:30', '16:00']];
    private const TIMES = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30', '17:00', '17:30'];
    private const SURNAMES = ['陳', '李', '張', '黃', '何', '林', '吳', '劉', '梁', '鄭', '謝', '蔡', '馮', '曾', '郭', '羅', '葉', '潘', '彭', '許'];
    private const GIVEN = ['嘉欣', '志明', '詠詩', '浩然', '美玲', '家豪', '慧敏', '俊傑', '淑儀', '偉強', '凱琳', '子軒', '曉彤', '國華', '佩珊', '文輝', '雅婷', '永康', '綺雯', '建邦'];
    private const SEGMENTS = ['新客', '回頭客', '長期調理', '企業客戶'];
    private const CONCERNS = ['失眠', '腰背痛', '頸肩痛', '產後調理', '濕疹', '胃脹', '月經不調', '疲勞', '鼻敏感', '運動勞損'];

    // Concern => [symptom categories, description, diagnosis, prescription, plan]. Symptoms use ClinicalController::SYMPTOMS.
    private const RECORDS = [
        '失眠' => [['失眠', '疲倦'], '入睡困難，夜醒兩至三次，多夢，日間精神欠佳。舌淡紅，苔薄白，脈細。', '不寐（心脾兩虛）',
            '酸棗仁 15g、茯神 12g、遠志 6g、當歸 9g、黨參 12g、炙甘草 6g', '睡前避免使用電話；每週針灸一次，連續四週。'],
        '腰背痛' => [['疼痛'], '腰部酸痛三週，久坐後加重，轉身受限。舌暗，苔薄，脈弦。', '腰痛（氣滯血瘀）',
            '杜仲 12g、續斷 12g、牛膝 9g、當歸 9g、川芎 6g、甘草 6g', '針灸腎俞、委中；避免提重物；兩週後覆診。'],
        '頸肩痛' => [['疼痛', '頭暈'], '頸肩僵硬，低頭工作後加重，偶有頭暈。舌淡，苔白，脈弦緊。', '項痹（風寒阻絡）',
            '葛根 15g、羌活 9g、桂枝 6g、白芍 12g、川芎 6g、甘草 6g', '推拿放鬆斜方肌；每小時伸展頸部；一週後覆診。'],
        '產後調理' => [['疲倦'], '產後三個月，容易疲倦，出汗較多，腰膝酸軟。舌淡，苔薄白，脈細弱。', '產後虛損（氣血兩虛）',
            '黃芪 15g、黨參 12g、當歸 9g、熟地 12g、白朮 9g、炙甘草 6g', '飲食宜溫補；每兩週覆診一次，連續三次。'],
        '濕疹' => [['其他'], '手肘及膝窩皮疹瘙癢，夜間加重，皮膚乾燥脫屑。舌紅，苔薄黃，脈滑。', '濕瘡（血虛風燥）',
            '生地 15g、防風 9g、白鮮皮 12g、地膚子 12g、當歸 9g、甘草 6g', '避免熱水洗澡；忌辛辣海鮮；一週後覆診。'],
        '胃脹' => [['胃腸不適'], '飯後胃脹噯氣，食慾一般，大便偏稀。舌淡胖，苔白膩，脈濡。', '痞滿（脾虛氣滯）',
            '黨參 12g、白朮 9g、茯苓 12g、陳皮 6g、半夏 9g、甘草 6g', '少食多餐，避免生冷；兩週後覆診。'],
        '月經不調' => [['疼痛', '疲倦'], '月經延後約一週，經量偏少，經前腹痛。舌暗淡，苔薄，脈細澀。', '月經後期（血虛寒凝）',
            '當歸 9g、白芍 12g、川芎 6g、熟地 12g、香附 9g、益母草 12g', '經前一週服藥；記錄週期；下次月經後覆診。'],
        '疲勞' => [['疲倦', '頭暈'], '持續疲倦一個月，工作壓力大，午後尤甚，偶有頭暈。舌淡，苔薄白，脈弱。', '虛勞（脾氣虧虛）',
            '黃芪 15g、黨參 12g、白朮 9g、茯苓 12g、陳皮 6g、炙甘草 6g', '規律作息，每晚十一時前入睡；兩週後覆診。'],
        '鼻敏感' => [['咳嗽', '其他'], '晨起噴嚏、流清涕，遇冷加重，偶有咳嗽。舌淡，苔薄白，脈浮緩。', '鼻鼽（肺氣虛寒）',
            '黃芪 15g、白朮 9g、防風 9g、辛夷 6g、蒼耳子 6g、甘草 6g', '外出佩戴口罩；三伏天灸；兩週後覆診。'],
        '運動勞損' => [['疼痛'], '跑步後右膝疼痛，上落樓梯加重，無明顯腫脹。舌淡紅，苔薄，脈弦。', '筋傷（氣血瘀滯）',
            '當歸 9g、川芎 6g、牛膝 9g、續斷 12g、白芍 12g、甘草 6g', '暫停跑步兩週；針灸配合拔罐；一週後覆診。'],
    ];
    public const STAFF_DOMAIN = 'clinic-demo.invalid';
    // [name, email local part, role, branch index, practitioner index or null]. Doctors match the practitioners by surname.
    private const STAFF = [
        ['陳志遠', 'chan.doctor', 'doctor', 0, 0], ['林曉晴', 'lam.doctor', 'doctor', 1, 1], ['黃家樂', 'wong.doctor', 'doctor', 2, 2],
        ['何穎欣', 'ho.doctor', 'doctor', 0, 3], ['張浩賢', 'cheung.doctor', 'doctor', 1, 4],
        ['周美寶', 'central.reception', 'reception', 0, null], ['孔慧儀', 'mongkok.reception', 'reception', 1, null],
        ['麥家欣', 'causeway.reception', 'reception', 2, null], ['蘇俊明', 'mongkok.manager', 'admin', 1, null],
        ['區詠恩', 'hr.officer', 'hr', 0, null],
    ];
    // Role => [job title, monthly pay in HKD, first training].
    private const ROLE_HR = [
        'doctor' => ['註冊中醫師', 52000, '完成針灸感染控制進修課程（6 個持續進修學分）'],
        'reception' => ['診所接待員', 19000, '完成《個人資料（私隱）條例》前線培訓'],
        'admin' => ['分店經理', 32000, '完成急救證書課程'],
        'hr' => ['人事主任', 27000, '完成《僱傭條例》最新修訂講座'],
    ];
    // Role => [kind, start time, flexible minutes, required minutes, weekdays (0 = Sunday)].
    private const SHIFTS = [
        'doctor' => ['flex', '09:00', 60, 360, [1, 2, 3, 4, 5, 6]],
        'reception' => ['fixed', '09:00', 0, 540, [1, 2, 3, 4, 5, 6]],
        'admin' => ['fixed', '09:30', 0, 480, [1, 2, 3, 4, 5]],
        'hr' => ['flex', '08:30', 60, 480, [1, 2, 3, 4, 5]],
    ];
    // [staff indexes, kind, first day offset, last day offset, status, reason]. Offsets are days from today.
    private const LEAVE = [
        [[0, 2, 5, 8], 'annual', -20, -18, 'approved', '家庭旅行'],
        [[1, 6], 'sick', -9, -9, 'approved', '發燒，已提交醫生證明'],
        [[3, 7, 9], 'annual', 6, 7, 'pending', '處理私人事務'],
        [[4], 'annual', 2, 4, 'rejected', '探親（與同事已批准假期撞期）'],
        [[7], 'maternity', 20, 117, 'approved', '產假 14 星期'],
    ];
    private const DISTRICTS = ['香港島中環', '九龍旺角', '香港島銅鑼灣', '新界沙田', '九龍觀塘', '新界荃灣', '香港島北角', '九龍深水埗', '新界將軍澳', '九龍九龍城'];
    // Branch index => STAFF index of the branch receptionist.
    private const RECEPTION = [5, 6, 7];
    // [keywords, category, assign to the branch receptionist, reply]. Keywords are matched as lowercase substrings.
    private const RULES = [
        ['營業時間|開放時間|幾點|opening hours', 'faq', false, '營業時間：星期一至六 09:00–18:00，星期日及公眾假期休息。Opening hours: Mon–Sat 09:00–18:00; closed on Sundays and public holidays.'],
        ['收費|價錢|幾錢|price|fee', 'pricing', false, '診金：中醫內科 HK$280、針灸 HK$450、推拿 HK$400、拔罐 HK$300；中藥每劑 HK$48。Fees: consultation from HK$280; herbs HK$48 per dose.'],
        ['泊車|停車|parking', 'faq', false, '分店附近設有收費停車場，建議乘搭港鐵前來。Paid parking is nearby; we recommend the MTR.'],
        ['保險|insurance|收據', 'billing', true, '已轉交接待處跟進保險及收據查詢。Your insurance or receipt enquiry has been passed to reception.'],
    ];
    /*
     * [contact, branch index, days ago, Hong Kong start time, steps]. Contact is ['patient', demo patient index]
     * or ['new', enquiry number]. Steps: ['in', minutes, text], ['assign', minutes, to receptionist, status,
     * category, pause automation], ['reply', minutes, text] and ['link', minutes]. Patient i belongs to branch i % 3.
     */
    private const CONVERSATIONS = [
        [['new', 1], 0, 6, '10:05', [['in', 0, '請問營業時間？']]],
        [['patient', 0], 0, 5, '14:20', [['in', 0, '你好'], ['assign', 6, true, 'open', 'handoff', true],
            ['reply', 6, '陳小姐你好，請問有什麼可以幫到你？'], ['in', 4, '想問針灸後可以做運動嗎？'],
            ['reply', 9, '針灸後建議休息兩小時，當日避免劇烈運動，多喝溫水。'], ['in', 3, '明白，多謝！'],
            ['link', 2], ['assign', 1, true, 'closed', 'clinical_question', true]]],
        [['new', 2], 0, 3, '11:00', [['in', 0, 'STOP']]],
        [['patient', 3], 0, 1, '20:10', [['in', 0, '預約'], ['in', 3, '預約 {slot} {name}']]],
        [['new', 3], 0, 1, '18:30', [['in', 0, '請問收費幾錢？'], ['in', 5, '真人'],
            ['assign', 900, true, 'open', 'handoff', true], ['reply', 4, '你好，初診診金 HK$280，中藥另計。需要幫你預約嗎？']]],
        [['patient', 1], 1, 4, '09:40', [['in', 0, 'HELP'], ['in', 2, '分店']]],
        [['new', 4], 1, 2, '15:15', [['in', 0, '想問有冇保險收據？'], ['reply', 18, '有的，到診後我們會提供正式收據，請帶同保險公司表格。'],
            ['in', 7, '好，多謝']]],
        [['patient', 4], 1, 1, '12:00', [['in', 0, 'START']]],
        [['patient', 7], 1, 0, '08:45', [['in', 0, '今日可唔可以改時間？']]],
        [['new', 5], 1, 6, '17:20', [['in', 0, '停車場喺邊？']]],
        [['patient', 2], 2, 2, '10:30', [['in', 0, '預約'], ['in', 4, '預約 {slot} {name}']]],
        [['new', 6], 2, 5, '11:45', [['in', 0, '你哋有冇針灸？'], ['assign', 10, true, 'open', 'handoff', true],
            ['reply', 3, '有的，針灸療程每次 HK$450，可經網站或回覆「預約」查詢時段。'],
            ['assign', 2, false, 'open', 'general', false], ['in', 30, '營業時間呢？']]],
        [['patient', 5], 2, 2, '19:05', [['in', 0, '多謝醫師，食完藥好咗好多'], ['assign', 840, true, 'open', 'feedback', true],
            ['reply', 2, '多謝你的回饋！如有需要歡迎再預約覆診。'], ['link', 1], ['assign', 1, true, 'closed', 'feedback', true]]],
        [['new', 7], 2, 0, '07:50', [['in', 0, '請問星期日開唔開？']]],
    ];
    public const MOVEMENT_MARK = ' · 示範數據';
    // Stocked but not prescribed, so the stock list also shows slow-moving herbs.
    private const EXTRA_HERBS = ['枸杞子', '菊花'];
    private const OPENING_GRAMS = 800;
    private const RESTOCK_GRAMS = 1000;
    private const REORDER_GRAMS = 200;
    // Consultation fee in HKD by service name.
    private const FEES = ['中醫內科' => 280, '針灸' => 450, '推拿' => 400, '拔罐' => 300];
    private const TEMPLATES = ['失眠調理' => '失眠', '腰背痛針灸' => '腰背痛', '鼻敏感調理' => '鼻敏感', '產後調理' => '產後調理'];

    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demonstration data currently requires a local or testing environment.');
        }
        // The head-office administrator reviews the HR officer's own leave and records anything without a demo doctor.
        $admin = DB::table('staff_accounts')->where('active', true)->where('role', 'admin')->whereNull('branch_id')->orderBy('id')->value('id');
        if (!$admin) {
            throw new \RuntimeException('Create an active head-office administrator before seeding demonstration data.');
        }
        $catalog = $this->catalog();
        $this->slots($catalog);
        $summary = array_fill_keys(['staff_accounts', 'patients', 'booked', 'rescheduled', 'cancelled', 'past_visits', 'encounters',
            'past_cancelled', 'past_without_record', 'templates', 'followups', 'receipts', 'awaiting_payment'], 0);
        $staff = $this->staff($catalog, $summary);
        $summary['templates'] = $this->templates($catalog, $staff['branch_doctors'], $admin);
        $patients = [];
        for ($i = 0; $i < self::PATIENTS; $i++) {
            [$name, $phone] = $this->person($i);
            // Matches the identity rule in CreateAppointment, so both paths share one patient row.
            $key = hash_hmac('sha256', Str::lower($name).'|'.PatientPhone::identity($phone), config('app.key'));
            $patient = Patient::firstOrCreate(['identity_key' => $key], ['name' => $name, 'phone' => PatientPhone::international($phone)]);
            $summary['patients']++;
            $branch = $catalog['branches'][$i % count(self::BRANCHES)];
            if (!$this->hasBooking($patient, '<=')) {
                $this->history($patient, $branch, $i, $staff['doctors'], $admin, $summary);
            }
            if (!$this->hasBooking($patient, '>')) {
                $this->future($name, $phone, $branch, $i, $summary);
            }
            $this->profile($patient, $branch, $i);
            $summary['followups'] += $this->followup($patient, $branch, $i);
            $this->receipts($patient, $summary);
            $patients[] = $patient->id;
        }
        $summary['recorders_assigned'] = $this->assignRecorders($patients, $staff['doctors']);
        $summary += $this->inventory($catalog, $patients);
        $summary += $this->hr($catalog, $staff, $admin);
        $summary += $this->conversations($catalog, $staff);
        $summary['outbox_batches'] = $this->drainProjections();
        $this->command?->info(json_encode($summary, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Runs the same steps as the clinic:messages scheduler tick until the demo backlog is gone.
     * NotificationQueue projects 250 rows per source per call and Delivery handles 100 due entries per call.
     * Without this, about 200 demo confirmations and feedback messages delay the next real booking's
     * confirmation by several scheduler runs. Demo entries have no consent, so Delivery suppresses them
     * before any provider request.
     */
    private function drainProjections(): int
    {
        $pending = function () {
            foreach (['appointment_events', 'encounters', 'receipts'] as $source) {
                if (DB::table("$source as s")->whereNotExists(fn ($q) => $q->selectRaw('1')->from('message_projections as m')
                    ->where('m.source', $source)->whereColumn('m.source_id', 's.id'))->exists()) {
                    return true;
                }
            }

            return DB::table('message_entries')->where('status', 'queued')->where('due_at', '<=', now())->exists();
        };
        for ($batches = 0; $batches < 20 && $pending(); $batches++) {
            app(NotificationQueue::class)->collect();
            app(Delivery::class)->consume();
        }

        return $batches;
    }

    /** Runs $work with the application clock set to $moment, as if it happened then. */
    private function at(CarbonImmutable $moment, callable $work): mixed
    {
        Carbon::setTestNow($moment->utc());
        try {
            return $work();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Projects new booking events, encounters and receipts into the outbox at $moment, as the every-minute
     * scheduler would have done then. Thread and message times then follow the business events instead of the seeding run.
     */
    private function project(CarbonImmutable $moment): void
    {
        $this->at($moment, fn () => app(NotificationQueue::class)->collect());
    }

    /** @return array{branches: int[], services: int[], practitioners: int[]} */
    private function catalog(): array
    {
        $ids = [];
        foreach (['branches' => self::BRANCHES, 'services' => self::SERVICES] as $table => $entries) {
            foreach ($entries as [$zh, $en]) {
                $ids[$table][] = DB::table($table)->where('name_zh', $zh)->value('id')
                    ?? DB::table($table)->insertGetId(['name_zh' => $zh, 'name_en' => $en]);
            }
        }
        foreach (self::PRACTITIONERS as [$zh, $en]) {
            $ids['practitioners'][] = DB::table('practitioners')->where('name_zh', $zh)->value('id')
                ?? DB::table('practitioners')->insertGetId(['name_zh' => $zh, 'name_en' => $en]);
        }
        foreach ($ids['branches'] as $branch) {
            foreach ($ids['services'] as $service) {
                DB::table('branch_services')->insertOrIgnore(['branch_id' => $branch, 'service_id' => $service]);
            }
            foreach ($ids['practitioners'] as $practitioner) {
                DB::table('branch_practitioners')->insertOrIgnore(['branch_id' => $branch, 'practitioner_id' => $practitioner]);
            }
        }

        return $ids;
    }

    /**
     * Future days use a full bookable timetable: a home branch on most days and a second branch on Tuesday and Thursday.
     * Past days use 2 two-hour sessions per practitioner per week, so 50 patients produce realistic booking rates.
     */
    private function slots(array $catalog): void
    {
        $rows = [];
        $today = today('Asia/Hong_Kong');
        foreach (self::PRACTITIONERS as $p => [, , $home, $services]) {
            for ($day = 0; $day < 14; $day++) {
                $date = $today->copy()->addDays($day);
                if ($date->dayOfWeek === 0) {
                    continue;
                }
                $branch = in_array($date->dayOfWeek, [2, 4], true) ? ($home + 1) % count(self::BRANCHES) : $home;
                foreach (self::TIMES as $t => $time) {
                    $rows[] = ['branch_id' => $catalog['branches'][$branch], 'practitioner_id' => $catalog['practitioners'][$p],
                        'service_id' => $catalog['services'][$services[($t + $day) % count($services)]],
                        'starts_at' => $date->toDateString().' '.$time];
                }
            }
        }
        $planned = [];
        for ($day = -self::HISTORY_DAYS; $day < 0; $day++) {
            $date = $today->copy()->addDays($day);
            foreach (self::PAST_SESSIONS as $p => $sessions) {
                foreach ($sessions as [$weekday, $branch, $half]) {
                    if ($date->dayOfWeek !== $weekday) {
                        continue;
                    }
                    $services = self::PRACTITIONERS[$p][3];
                    foreach (self::SESSION_TIMES[$half] as $t => $time) {
                        $row = ['branch_id' => $catalog['branches'][$branch], 'practitioner_id' => $catalog['practitioners'][$p],
                            'service_id' => $catalog['services'][$services[($t + $day + self::HISTORY_DAYS) % count($services)]],
                            'starts_at' => $date->toDateString().' '.$time.':00'];
                        $rows[] = $row;
                        // The key matches the 'Y-m-d H:i:s' text that MySQL returns for slots.starts_at.
                        $planned[$row['branch_id'].'|'.$row['practitioner_id'].'|'.$row['starts_at']] = true;
                    }
                }
            }
        }
        // Remove stale slots first: an old slot at the same practitioner and time would block a planned insert.
        $this->removeUnplannedPastSlots($catalog, $planned);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('slots')->insertOrIgnore($chunk);
        }
    }

    /**
     * Earlier local runs created a full past timetable. This removes only the past demo-practitioner slots that
     * no booking or booking event references and that the session plan does not contain.
     * It runs only in local and testing environments, because production schedules may share practitioner names.
     */
    private function removeUnplannedPastSlots(array $catalog, array $planned): void
    {
        if (!app()->environment('local', 'testing')) {
            return;
        }
        $today = today('Asia/Hong_Kong');
        $stale = DB::table('slots as s')->whereIn('s.practitioner_id', $catalog['practitioners'])
            ->whereIn('s.branch_id', $catalog['branches'])
            ->where('s.starts_at', '>=', $today->copy()->subDays(self::HISTORY_DAYS)->toDateTimeString())
            ->where('s.starts_at', '<', $today->toDateTimeString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments as a')->whereColumn('a.slot_id', 's.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointment_events as e')
                ->where(fn ($w) => $w->whereColumn('e.from_slot_id', 's.id')->orWhereColumn('e.to_slot_id', 's.id')))
            ->get(['s.id', 's.branch_id', 's.practitioner_id', 's.starts_at'])
            ->reject(fn ($slot) => isset($planned[$slot->branch_id.'|'.$slot->practitioner_id.'|'.$slot->starts_at]))
            ->pluck('id');
        foreach ($stale->chunk(500) as $chunk) {
            DB::table('slots')->whereIn('id', $chunk)->delete();
        }
    }

    /** Deterministic synthetic identity: 40 Hong Kong and 10 mainland China numbers. */
    private function person(int $i): array
    {
        $name = self::SURNAMES[$i % 20].self::GIVEN[($i * 7 + intdiv($i, 20)) % 20];
        $phone = $i % 5 === 4
            ? '+86139'.str_pad((string) (20260000 + $i * 37), 8, '0', STR_PAD_LEFT)
            : ($i % 2 ? '6' : '9').str_pad((string) (3000000 + $i * 4157), 7, '0', STR_PAD_LEFT);

        return [$name, $phone];
    }

    private function hasBooking(Patient $patient, string $operator): bool
    {
        return DB::table('appointments as a')->join('slots as s', 's.id', '=', 'a.slot_id')->where('a.patient_id', $patient->id)
            ->where('s.starts_at', $operator, now('Asia/Hong_Kong')->toDateTimeString())->exists();
    }

    private function freeSlots(int $branch)
    {
        return DB::table('slots as s')->where('s.branch_id', $branch)->whereNull('s.closed_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments as a')->whereColumn('a.slot_id', 's.id')->whereNull('a.cancelled_at'))
            ->orderBy('s.starts_at')->orderBy('s.id');
    }

    private function freeSlot(int $branch, int $offset, ?CarbonImmutable $before = null): ?int
    {
        $before ??= CarbonImmutable::today('Asia/Hong_Kong')->addDays(14);
        $free = $this->freeSlots($branch)->where('s.starts_at', '>', now('Asia/Hong_Kong')->addHours(2)->toDateTimeString())
            ->where('s.starts_at', '<', $before->toDateTimeString())->pluck('s.id');

        return $free->isEmpty() ? null : $free[$offset % $free->count()];
    }

    /**
     * 1 to 3 future bookings at the customer's preferred branch, through the booking actions.
     * Each booking happens on one of the last 7 days, never later than shortly before now, with the
     * application clock set to that moment. Reschedules and cancellations happen halfway to now.
     * CreateAppointment only accepts slots within 14 days of the booking day, and both limits keep to that.
     */
    private function future(string $name, string $phone, int $branch, int $i, array &$summary): void
    {
        $now = CarbonImmutable::now('Asia/Hong_Kong');
        // The count uses intdiv so it does not repeat the branch rotation.
        for ($n = 0; $n < 1 + intdiv($i, 3) % 3; $n++) {
            $slot = $this->freeSlot($branch, $i * 7 + $n * 13);
            if (!$slot) {
                break;
            }
            $starts = CarbonImmutable::parse(DB::table('slots')->where('id', $slot)->value('starts_at'), 'Asia/Hong_Kong');
            // Up to 6 days ago, but no earlier than 13 days before the slot day, which the 14-day booking window requires.
            $back = min(6, 13 - (int) $now->startOfDay()->diffInDays($starts->startOfDay()));
            $days = ($i * 5 + $n * 3) % (max(0, $back) + 1);
            $bookedAt = $now->startOfDay()->subDays($days)->setTime(9 + ($i * 3 + $n) % 12, ($i * 7) % 60)->min($now->subMinutes(5 + $i));
            $appointment = $this->at($bookedAt, fn () => app(CreateAppointment::class)->book(['slot_id' => $slot,
                'name' => $name, 'phone' => $phone, 'whatsapp_opt_in' => false, 'email' => null]));
            if (!$appointment) {
                continue;
            }
            $this->project($bookedAt);
            $summary['booked']++;
            $changedAt = $bookedAt->addSeconds(intdiv($now->getTimestamp() - $bookedAt->getTimestamp(), 2));
            // Modulo 5 and 7 are coprime with the 3-branch rotation, so changes reach every branch.
            if ($n === 0 && $i % 5 === 1
                && ($target = $this->freeSlot($branch, $i * 11 + 29, $changedAt->startOfDay()->addDays(14)))) {
                $summary['rescheduled'] += (int) $this->at($changedAt,
                    fn () => app(RescheduleAppointment::class)->move($appointment, $slot, $target, $branch));
                $this->project($changedAt);
            } elseif ($n === 1 && $i % 7 === 2) {
                $summary['cancelled'] += (int) $this->at($changedAt,
                    fn () => app(CancelAppointment::class)->cancel($appointment->fresh(), $slot, $branch));
                $this->project($changedAt);
            }
        }
    }

    /**
     * Past visits over the last HISTORY_DAYS days. The visit count follows the CRM segment.
     * Long-term patients keep the practitioner of their first visit, so visit comparison is meaningful.
     */
    private function history(Patient $patient, int $branch, int $i, array $doctors, int $admin, array &$summary): void
    {
        $round = intdiv($i, 4);
        $visits = [$round % 2, 2 + $round % 2, 4 + $round % 3, 1 + $round % 2][$i % 4];
        $concern = self::CONCERNS[$i % 10];
        $practitioner = null;
        $today = CarbonImmutable::today('Asia/Hong_Kong');
        // Visits are spaced 6 days or more apart and the first visit spreads across the whole window.
        $spacing = $visits ? intdiv(self::HISTORY_DAYS - 3, $visits) : 0;
        $start = $visits ? ($i * 7) % max(1, self::HISTORY_DAYS - 3 - ($visits - 1) * $spacing) : 0;
        for ($k = 0; $k < $visits; $k++) {
            $from = $today->subDays(self::HISTORY_DAYS - 2)->addDays($start + $k * $spacing);
            // Pick among the free slots of a 7-day window, which holds every weekly session of the practitioner.
            $choices = $this->freeSlots($branch)->where('s.starts_at', '>=', $from->toDateTimeString())
                ->where('s.starts_at', '<', min($from->addDays(7),CarbonImmutable::now('Asia/Hong_Kong')->subHour())->toDateTimeString())
                ->when($practitioner, fn ($q) => $q->where('s.practitioner_id', $practitioner))
                ->get(['s.id', 's.starts_at', 's.practitioner_id']);
            if ($choices->isEmpty()) {
                continue;
            }
            $slot = $choices[($i * 5 + $k * 3) % $choices->count()];
            if ($i % 4 === 2) {
                $practitioner ??= $slot->practitioner_id;
            }
            // Slots hold Hong Kong wall-clock times. Timestamps are stored in UTC, the application timezone.
            $starts = CarbonImmutable::parse($slot->starts_at, 'Asia/Hong_Kong');
            $booked = $starts->subDays(1 + ($i + $k) % 6)->setTime(10 + $k % 8, 15)->utc();
            $cancelled = ($i * 7 + $k) % 10 === 3;
            $cancelledAt = $starts->subDay()->setTime(18, 0)->utc();
            $appointment = DB::table('appointments')->insertGetId(['reference' => (string) Str::uuid(), 'slot_id' => $slot->id,
                'patient_id' => $patient->id, 'email' => null, 'whatsapp_opt_in' => false,
                'cancelled_at' => $cancelled ? $cancelledAt : null,
                'created_at' => $booked, 'updated_at' => $cancelled ? $cancelledAt : $booked]);
            DB::table('appointment_events')->insert(['appointment_id' => $appointment, 'event' => 'confirmed',
                'from_slot_id' => null, 'to_slot_id' => $slot->id, 'created_at' => $booked]);
            $summary['past_visits']++;
            if ($cancelled) {
                DB::table('appointment_events')->insert(['appointment_id' => $appointment, 'event' => 'cancelled',
                    'from_slot_id' => $slot->id, 'to_slot_id' => null, 'created_at' => $cancelledAt]);
                $summary['past_cancelled']++;
                $this->project($starts->addMinutes(40));
                continue;
            }
            // A few past bookings stay without a record, as when a patient does not attend.
            if (($i * 3 + $k) % 13 === 5) {
                $summary['past_without_record']++;
                $this->project($starts->addMinutes(40));
                continue;
            }
            $encounter = new Encounter(['appointment_id' => $appointment, 'patient_id' => $patient->id, 'branch_id' => $branch,
                'recorded_by' => $doctors[$slot->practitioner_id] ?? $admin, 'clinical_record' => $this->record($concern, $k)]);
            $encounter->created_at = $encounter->updated_at = $starts->addMinutes(40)->utc();
            $encounter->save();
            $summary['encounters']++;
            // The slot has started by then, so only the post-visit feedback message is projected.
            $this->project($starts->addMinutes(40));
        }
    }

    private function record(string $concern, int $visit): array
    {
        [$symptoms, $notes, $diagnosis, $prescription, $plan] = self::RECORDS[$concern];
        if ($visit > 0) {
            $notes = "覆診第 {$visit} 次：".['症狀較前減輕，', '症狀明顯改善，', '症狀基本穩定，'][min($visit - 1, 2)].$notes;
        } else {
            $notes = '初診：'.$notes;
        }

        return ['symptoms' => $symptoms, 'notes' => $notes, 'diagnosis' => $diagnosis,
            'prescription' => $prescription.'，'.($visit > 2 ? '五' : '七').'劑，每日一劑，水煎分兩次溫服。', 'plan' => $plan];
    }

    /**
     * One receipt per encounter, paid 5 to 15 minutes after the record, as reception does at POS.
     * About half of the encounters from the last 3 days stay unpaid, so POS shows a realistic queue.
     * The amount is the service fee plus HK$48 per herbal dose in the prescription.
     */
    private function receipts(Patient $patient, array &$summary): void
    {
        $encounters = DB::table('encounters as e')->join('appointments as a', 'a.id', '=', 'e.appointment_id')
            ->join('slots as s', 's.id', '=', 'a.slot_id')->join('services as v', 'v.id', '=', 's.service_id')
            ->where('e.patient_id', $patient->id)->whereNull('a.cancelled_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('receipts as r')->whereColumn('r.encounter_id', 'e.id'))
            ->orderBy('e.id')->get(['e.id', 'e.appointment_id', 'e.branch_id', 'e.created_at', 'v.name_zh as service']);
        $recent = CarbonImmutable::now('UTC')->subDays(3);
        foreach ($encounters as $encounter) {
            $recorded = CarbonImmutable::parse($encounter->created_at, 'UTC');
            if ($recorded->greaterThan($recent) && ($encounter->id + $patient->id) % 2 === 0) {
                $summary['awaiting_payment']++;
                continue;
            }
            $record = Encounter::findOrFail($encounter->id)->clinical_record;
            $doses = str_contains($record['prescription'] ?? '', '七劑') ? 7 : (str_contains($record['prescription'] ?? '', '五劑') ? 5 : 0);
            $fee = self::FEES[$encounter->service] ?? 300;
            $slot = ($encounter->id * 7 + $patient->id) % 20;
            DB::table('receipts')->insert(['reference' => (string) Str::uuid(), 'branch_id' => $encounter->branch_id,
                'patient_id' => $patient->id, 'appointment_id' => $encounter->appointment_id, 'encounter_id' => $encounter->id,
                'description' => $encounter->service.'診金 HK$'.$fee.($doses ? '、中藥 '.$doses.' 劑' : ''),
                'amount_cents' => ($fee + $doses * 48) * 100,
                'method' => $slot < 6 ? 'cash' : ($slot < 11 ? 'card' : ($slot < 18 ? 'fps' : 'other')),
                'created_at' => $recorded->addMinutes(5 + $encounter->id % 11)]);
            $summary['receipts']++;
            $this->project($recorded->addMinutes(5 + $encounter->id % 11));
        }
    }

    /**
     * Herbal stock per branch, replayed in time order so the balance never goes negative.
     * Each branch opens with 800 g of every herb at the start of the window.
     * Every encounter dispenses its prescription: grams per dose times the number of doses, linked to the encounter.
     * When a herb would fall below 200 g, a 1 kg supplier delivery is received at 08:30 that morning.
     * Each branch also records one 50 g stock-count loss of 陳皮.
     * Movements without an encounter carry the MOVEMENT_MARK suffix so the purge can find them.
     */
    private function inventory(array $catalog, array $patients): array
    {
        $summary = ['herbs' => 0, 'opening_receipts' => 0, 'restocks' => 0, 'dispenses' => 0, 'stock_losses' => 0];
        $herbs = [];
        foreach (self::RECORDS as [, , , $prescription]) {
            foreach ($this->doses($prescription) as $herb => $grams) {
                $herbs[$herb] = true;
            }
        }
        $herbs = array_merge(array_keys($herbs), self::EXTRA_HERBS);
        $summary['herbs'] = count($herbs);
        $start = CarbonImmutable::today('Asia/Hong_Kong')->subDays(self::HISTORY_DAYS);
        foreach ($catalog['branches'] as $branch) {
            foreach ($herbs as $herb) {
                DB::table('herbal_stocks')->insertOrIgnore(['branch_id' => $branch, 'herb' => $herb, 'milligrams' => 0]);
            }
            $stocks = DB::table('herbal_stocks')->where('branch_id', $branch)->get()->keyBy('herb');
            $balance = $stocks->map(fn ($stock) => (int) $stock->milligrams)->all();
            $events = [];
            $opened = DB::table('herbal_movements')->whereIn('stock_id', $stocks->pluck('id'))
                ->where('note', '期初入庫'.self::MOVEMENT_MARK)->exists();
            if (!$opened) {
                foreach ($herbs as $herb) {
                    $events[] = [$start->setTime(8, 30)->utc(), $herb, self::OPENING_GRAMS * 1000, 'receive', null, '期初入庫'.self::MOVEMENT_MARK];
                }
                $events[] = [$start->addDays(40)->setTime(18, 0)->utc(), '陳皮', 50 * 1000, 'dispense', null, '盤點損耗'.self::MOVEMENT_MARK];
            }
            $encounters = Encounter::where('branch_id', $branch)->whereIn('patient_id', $patients)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('herbal_movements as m')->whereColumn('m.encounter_id', 'encounters.id'))
                ->orderBy('id')->get();
            foreach ($encounters as $encounter) {
                $prescription = $encounter->clinical_record['prescription'] ?? '';
                $doses = str_contains($prescription, '七劑') ? 7 : (str_contains($prescription, '五劑') ? 5 : 0);
                foreach ($this->doses($prescription) as $herb => $grams) {
                    $events[] = [CarbonImmutable::parse($encounter->created_at, 'UTC')->addMinutes(3), $herb, $grams * $doses * 1000,
                        'dispense', $encounter->id, "處方配藥 {$doses} 劑"];
                }
            }
            // Receipts sort before dispenses at the same moment.
            usort($events, fn ($a, $b) => [$a[0]->getTimestamp(), $a[3] === 'receive' ? 0 : 1] <=> [$b[0]->getTimestamp(), $b[3] === 'receive' ? 0 : 1]);
            $rows = [];
            $row = fn ($at, $herb, $mg, $kind, $encounter, $note) => ['reference' => (string) Str::uuid(),
                'stock_id' => $stocks[$herb]->id, 'encounter_id' => $encounter, 'kind' => $kind, 'milligrams' => $mg,
                'note' => $note, 'created_at' => $at];
            foreach ($events as [$at, $herb, $mg, $kind, $encounter, $note]) {
                if ($kind === 'dispense' && $balance[$herb] - $mg < self::REORDER_GRAMS * 1000) {
                    $delivery = CarbonImmutable::parse($at, 'UTC')->setTimezone('Asia/Hong_Kong')->setTime(8, 30)->utc();
                    $rows[] = $row($delivery, $herb, self::RESTOCK_GRAMS * 1000, 'receive', null, '供應商補貨'.self::MOVEMENT_MARK);
                    $balance[$herb] += self::RESTOCK_GRAMS * 1000;
                    $summary['restocks']++;
                }
                $rows[] = $row($at, $herb, $mg, $kind, $encounter, $note);
                $balance[$herb] += $kind === 'receive' ? $mg : -$mg;
                $summary[$kind === 'receive' ? 'opening_receipts' : ($encounter ? 'dispenses' : 'stock_losses')]++;
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('herbal_movements')->insert($chunk);
            }
            foreach ($balance as $herb => $mg) {
                DB::table('herbal_stocks')->where('id', $stocks[$herb]->id)->update(['milligrams' => $mg]);
            }
        }

        return $summary;
    }

    /** @return array<string, int> herb name => grams per dose, parsed from "酸棗仁 15g、茯神 12g". */
    private function doses(string $prescription): array
    {
        // \p{Han} also matches the ideographic comma through Script_Extensions, so split on explicit separators.
        preg_match_all('/([^\s、，,0-9]+)\s*(\d+)g/u', $prescription, $matches, PREG_SET_ORDER);

        return array_column(array_map(fn ($m) => [$m[1], (int) $m[2]], $matches), 1, 0);
    }

    /**
     * Demo staff accounts. Each password hash comes from 32 random bytes that are never stored or shown,
     * so nobody can sign in with these accounts. The .invalid domain guarantees that no email can be delivered.
     *
     * @return array{ids: int[], doctors: array<int, int>, branch_doctors: array<int, int>, hr: int}
     */
    private function staff(array $catalog, array &$summary): array
    {
        $result = ['ids' => [], 'doctors' => [], 'branch_doctors' => [], 'hr' => 0];
        foreach (self::STAFF as $k => [$name, $local, $role, $branchIndex, $practitioner]) {
            $email = $local.'@'.self::STAFF_DOMAIN;
            $id = DB::table('staff_accounts')->where('email', $email)->value('id');
            if (!$id) {
                $joined = CarbonImmutable::now('UTC')->subMonths(6 + $k * 5);
                $id = DB::table('staff_accounts')->insertGetId(['branch_id' => $catalog['branches'][$branchIndex], 'name' => $name,
                    'email' => $email, 'password_hash' => Hash::make(bin2hex(random_bytes(32))), 'role' => $role,
                    'active' => true, 'created_at' => $joined, 'updated_at' => $joined]);
                $summary['staff_accounts']++;
            }
            $result['ids'][$k] = $id;
            if ($practitioner !== null) {
                $result['doctors'][$catalog['practitioners'][$practitioner]] = $id;
                $result['branch_doctors'][$catalog['branches'][$branchIndex]] ??= $id;
            }
            if ($role === 'hr') {
                $result['hr'] = $id;
            }
        }

        return $result;
    }

    /** Points demo encounters at the doctor account of the slot's practitioner. Earlier runs used the head-office administrator. */
    private function assignRecorders(array $patients, array $doctors): int
    {
        $updated = 0;
        foreach ($doctors as $practitioner => $staff) {
            $updated += DB::table('encounters as e')->join('appointments as a', 'a.id', '=', 'e.appointment_id')
                ->join('slots as s', 's.id', '=', 'a.slot_id')->whereIn('e.patient_id', $patients)
                ->where('s.practitioner_id', $practitioner)->where('e.recorded_by', '<>', $staff)
                ->update(['e.recorded_by' => $staff]);
        }

        return $updated;
    }

    /**
     * HR records for every demo staff account that has no HR profile yet: contact details, employment history,
     * a contract and some tax documents, 4 weeks of attended shifts plus 2 weeks ahead, and leave in every state.
     * Shift and leave dates use Hong Kong wall-clock values, as HrController stores them.
     */
    private function hr(array $catalog, array $staff, int $admin): array
    {
        $summary = ['hr_profiles' => 0, 'hr_history' => 0, 'hr_documents' => 0, 'hr_shifts' => 0, 'hr_attended' => 0,
            'hr_late' => 0, 'hr_early' => 0, 'hr_overtime' => 0, 'hr_leave' => 0];
        $today = CarbonImmutable::today('Asia/Hong_Kong');
        $hrOfficer = $staff['hr'];
        foreach (self::STAFF as $k => [$name, $local, $role, $branchIndex]) {
            $id = $staff['ids'][$k];
            if (DB::table('hr_profiles')->where('staff_id', $id)->exists()) {
                continue;
            }
            $branchName = self::BRANCHES[$branchIndex][0];
            $joined = $today->subMonths(6 + $k * 5);
            $reviewer = $id === $hrOfficer ? $admin : $hrOfficer;
            DB::table('hr_profiles')->insert(['staff_id' => $id, 'details' => encrypt([
                'identity' => sprintf('示範證件 %04d', $k + 1), 'phone' => '+852 '.(5100 + $k * 37).' '.(2000 + $k * 113),
                'address' => self::DISTRICTS[$k % count(self::DISTRICTS)].'（示範地址）',
                'emergency_name' => self::SURNAMES[($k + 3) % 20].self::GIVEN[($k * 3) % 20],
                'emergency_phone' => '+852 '.(6200 + $k * 41).' '.(3000 + $k * 97)])]);
            $summary['hr_profiles']++;

            [$label, $basePay, $training] = self::ROLE_HR[$role];
            $entries = [['job', $joined, "入職：{$label}，{$branchName}分店"],
                ['training', $joined->addMonths(2), $training]];
            if ($joined->lt($today->subYear())) {
                $entries[] = ['pay', $joined->addYear(), '年度薪酬調整：月薪 HK$'.number_format($basePay * 1.04 + $k * 500)];
                $entries[] = ['performance', $joined->addYear()->subWeeks(2), ['年度評核：表現良好，病人回饋正面。', '年度評核：達標，建議加強排班溝通。'][$k % 2]];
            }
            foreach ($entries as [$kind, $on, $details]) {
                DB::table('hr_history')->insert(['staff_id' => $id, 'kind' => $kind, 'effective_on' => $on->toDateString(),
                    'details' => Crypt::encryptString($details), 'author_id' => $reviewer]);
                $summary['hr_history']++;
            }

            $documents = [['contract', "僱傭合約-{$name}.pdf", "Employment contract (synthetic demo) - staff {$id}"]];
            if ($joined->lt($today->subMonths(8))) {
                $documents[] = ['tax', "IR56B-2025-26-{$name}.pdf", "IR56B 2025-26 (synthetic demo) - staff {$id}"];
            }
            foreach ($documents as [$kind, $filename, $text]) {
                DB::table('hr_documents')->insert(['staff_id' => $id, 'kind' => $kind, 'filename' => Crypt::encryptString($filename),
                    'contents' => Crypt::encryptString(self::pdf($text)), 'uploaded_at' => $joined->setTime(11, 0)->utc()]);
                $summary['hr_documents']++;
            }

            // Leave: [kind, first day offset, last day offset, status, reason].
            $leave = array_values(array_filter(self::LEAVE, fn ($entry) => in_array($k, $entry[0], true)));
            $blocked = [];
            foreach ($leave as [, $kind, $from, $to, $status, $reason]) {
                $start = $today->addDays($from);
                // Sick leave is approved on the day. Other decisions fall 5 days ahead, but never later than yesterday.
                $reviewed = $status === 'pending' ? null : ($kind === 'sick' ? $start->setTime(10, 0)
                    : $start->subDays(5)->min($today->subDay())->setTime(15, 0));
                DB::table('hr_leave')->insert(['staff_id' => $id, 'kind' => $kind, 'starts_on' => $start->toDateString(),
                    'ends_on' => $today->addDays($to)->toDateString(), 'reason' => Crypt::encryptString($reason), 'status' => $status,
                    'reviewer_id' => $status === 'pending' ? null : $reviewer, 'reviewed_at' => $reviewed?->utc()]);
                $summary['hr_leave']++;
                if ($status !== 'rejected') {
                    for ($d = $from; $d <= $to; $d++) {
                        $blocked[$d] = true;
                    }
                }
            }

            [$kind, $startTime, $flexMinutes, $required, $days] = self::SHIFTS[$role];
            $off = ($k + 2) % 6 + 1;
            for ($d = -28; $d < 14; $d++) {
                $date = $today->addDays($d);
                if (!in_array($date->dayOfWeek, $days, true) || ($role !== 'admin' && $role !== 'hr' && $date->dayOfWeek === $off) || isset($blocked[$d])) {
                    continue;
                }
                $start = CarbonImmutable::parse($date->toDateString().' '.$startTime, 'Asia/Hong_Kong');
                $latest = $start->addMinutes($flexMinutes);
                $row = ['staff_id' => $id, 'kind' => $kind, 'starts_at' => $start->format('Y-m-d H:i:s'),
                    'latest_start' => $latest->format('Y-m-d H:i:s'), 'required_minutes' => $required, 'clock_in' => null, 'clock_out' => null];
                // Only days before today carry attendance, so no shift stays open without a clock-out.
                if ($d < 0) {
                    $h = $k * 31 + ($d + 28) * 7;
                    $arrival = $h % 11 === 3 ? $latest->addMinutes(5 + $h % 20)
                        : $start->addMinutes($flexMinutes ? ($h * 13) % ($flexMinutes + 1) : -($h % 10));
                    $expectedEnd = $arrival->max($start)->min($latest)->addMinutes($required);
                    $leaving = match (true) {
                        $h % 7 === 2 => $expectedEnd->addMinutes(30 + $h % 61),
                        $h % 17 === 5 => $expectedEnd->subMinutes(20 + $h % 26),
                        default => $expectedEnd->addMinutes($h % 11),
                    };
                    $row['clock_in'] = $arrival->format('Y-m-d H:i:s');
                    $row['clock_out'] = $leaving->format('Y-m-d H:i:s');
                    $attendance = HrAttendance::summary((object) $row);
                    $summary['hr_attended']++;
                    $summary['hr_late'] += (int) ($attendance['late'] > 0);
                    $summary['hr_early'] += (int) ($attendance['early'] > 0);
                    $summary['hr_overtime'] += (int) ($attendance['overtime'] >= 30);
                }
                DB::table('hr_shifts')->insert($row);
                $summary['hr_shifts']++;
            }
        }

        return $summary;
    }

    /**
     * WhatsApp conversations replayed through Inbox::receive, the same path the Meta webhook uses.
     * Each message runs at its own simulated time through Carbon::setTestNow, so timestamps and the
     * 24-hour service window match the conversation. Staff steps repeat what InboxController does.
     * Every outbound reply then receives a simulated Meta receipt, so no demo reply stays queued for delivery.
     */
    private function conversations(array $catalog, array $staff): array
    {
        $summary = ['message_rules' => 0, 'conversations' => 0, 'inbound_messages' => 0, 'staff_replies' => 0,
            'receipts_simulated' => 0, 'future_steps_skipped' => 0];
        foreach ($catalog['branches'] as $b => $branch) {
            foreach (self::RULES as [$keyword, $category, $assign, $reply]) {
                $assignee = $assign ? $staff['ids'][self::RECEPTION[$b]] : null;
                if (!DB::table('message_rules')->where(['branch_id' => $branch, 'keyword' => $keyword])->exists()) {
                    DB::table('message_rules')->insert(['branch_id' => $branch, 'keyword' => $keyword, 'category' => $category,
                        'assignee_id' => $assignee, 'reply' => $reply]);
                    $summary['message_rules']++;
                }
            }
        }
        $inbox = app(Inbox::class);
        $today = CarbonImmutable::today('Asia/Hong_Kong');
        // WhatsApp bookings take a slot at least a day after the real clock, so they stay in the future.
        $realNow = CarbonImmutable::now('Asia/Hong_Kong');
        $bookFrom = $realNow->addDay()->toDateTimeString();
        try {
            foreach (self::CONVERSATIONS as $c => [$who, $b, $daysAgo, $time, $steps]) {
                $branch = $catalog['branches'][$b];
                [$name, $phone] = $who[0] === 'patient' ? $this->person($who[1]) : [null, sprintf('+8529%03d%04d', 870 + $who[1], 1000 + $who[1] * 37)];
                $phone = PatientPhone::international($phone);
                if (DB::table('message_entries')->where('event_key', 'incoming:'.hash('sha256', self::messageId($c, 0)))->exists()) {
                    continue;
                }
                $summary['conversations']++;
                $at = CarbonImmutable::parse($today->subDays($daysAgo)->toDateString().' '.$time, 'Asia/Hong_Kong');
                $receptionist = $staff['ids'][self::RECEPTION[$b]];
                $threadId = null;
                foreach ($steps as $s => $step) {
                    $at = $at->addMinutes($step[1]);
                    // A run early in the day stops a conversation before any step that would lie in the future.
                    if ($at->greaterThan($realNow)) {
                        $summary['future_steps_skipped'] += count($steps) - $s;
                        break;
                    }
                    // The scheduler delivers queued replies every minute, so earlier replies reach Meta before the next step.
                    if ($s > 0 && $step[1] >= 1) {
                        $summary['receipts_simulated'] += $this->simulateReceipts($phone, $c);
                    }
                    Carbon::setTestNow($at->utc());
                    if ($step[0] === 'in') {
                        $slot = str_contains($step[2], '{slot}') ? $this->freeSlots($branch)->where('s.starts_at', '>', $bookFrom)
                            ->where('s.starts_at', '<', $today->addDays(12)->toDateTimeString())->skip($c)->value('s.id') : null;
                        $text = strtr($step[2], ['{name}' => (string) $name, '{slot}' => (string) $slot]);
                        $inbox->receive(['branch_id' => $branch, 'phone' => $phone, 'message_id' => self::messageId($c, $s),
                            'text' => $text, 'received_at' => $at->utc()]);
                        $threadId = DB::table('message_entries')->where('event_key', 'incoming:'.hash('sha256', self::messageId($c, $s)))->value('thread_id');
                        $contact = hash_hmac('sha256', $phone, config('app.key'));
                        DB::table('whatsapp_routes')->upsert([['contact_key' => $contact, 'branch_id' => $branch, 'last_received_at' => $at->utc()]],
                            ['contact_key'], ['branch_id', 'last_received_at']);
                        $summary['inbound_messages']++;
                    } elseif ($step[0] === 'assign') {
                        // Same effect as InboxController::assign: an assignee always pauses automation.
                        $assignee = $step[2] ? $receptionist : null;
                        $paused = $assignee !== null || $step[5];
                        DB::table('message_threads')->where('id', $threadId)->update(['assignee_id' => $assignee, 'status' => $step[3],
                            'category' => $step[4], 'automation_paused' => $paused, 'updated_at' => now()]);
                        if ($paused) {
                            Inbox::pauseAutomatedReplies($threadId);
                        }
                    } elseif ($step[0] === 'reply') {
                        // Same effect as InboxController::reply.
                        DB::table('message_threads')->where('id', $threadId)->update(['automation_paused' => true, 'updated_at' => now()]);
                        Inbox::pauseAutomatedReplies($threadId);
                        Inbox::enqueue(DB::table('message_threads')->find($threadId), "staff:$threadId:".Str::uuid(), 'reply', $step[2]);
                        $summary['staff_replies']++;
                    } elseif ($step[0] === 'link' && $who[0] === 'patient') {
                        // Same effect as InboxController::link after reception verifies the booking reference.
                        $patient = Patient::where('identity_key', hash_hmac('sha256', Str::lower($name).'|'.PatientPhone::identity($phone), config('app.key')))->value('id');
                        DB::table('message_threads')->where('id', $threadId)->whereNull('patient_id')->update(['patient_id' => $patient, 'updated_at' => now()]);
                    }
                    // A WhatsApp booking is projected at the moment it happens, under the simulated clock.
                    app(NotificationQueue::class)->collect();
                }
                $summary['receipts_simulated'] += $this->simulateReceipts($phone, $c);
            }
        } finally {
            Carbon::setTestNow();
        }

        return $summary;
    }

    /**
     * Gives every queued outbound reply of a demo contact a terminal Meta status.
     * Replies older than 10 minutes are read, except every fifth delivered; conversation 9 shows one failed delivery.
     */
    private function simulateReceipts(string $phone, int $conversation): int
    {
        $threads = DB::table('message_threads')->where('contact_key', hash_hmac('sha256', $phone, config('app.key')))->pluck('id');
        $entries = DB::table('message_entries')->whereIn('thread_id', $threads)->where('direction', 'outbound')
            ->where('kind', 'reply')->where('status', 'queued')->orderBy('id')->get();
        foreach ($entries as $n => $entry) {
            $failed = $conversation === 9 && $n === 0;
            $status = $failed ? 'failed' : (($entry->id % 5 === 0) ? 'delivered' : 'read');
            DB::table('message_entries')->where('id', $entry->id)->update(['status' => $status, 'attempts' => 1,
                'error' => $failed ? 'META_131026' : null, 'provider_message_id' => 'wamid.DEMO.'.hash('sha256', 'receipt:'.$entry->event_key),
                'claimed_at' => $entry->created_at, 'updated_at' => CarbonImmutable::parse($entry->created_at, 'UTC')->addMinutes(2)]);
        }

        return $entries->count();
    }

    public static function messageId(int $conversation, int $step): string
    {
        return "wamid.DEMO.$conversation.$step";
    }

    /** @return string[] event keys of every scripted inbound message, for the purge. */
    public static function demoMessageKeys(): array
    {
        $keys = [];
        foreach (self::CONVERSATIONS as $c => [, , , , $steps]) {
            foreach ($steps as $s => $step) {
                $keys[] = 'incoming:'.hash('sha256', self::messageId($c, $s));
            }
        }

        return $keys;
    }

    /** @return string[] phone numbers of the scripted contacts who are not demo patients. */
    public static function demoEnquiryPhones(): array
    {
        $phones = [];
        foreach (self::CONVERSATIONS as [$who]) {
            if ($who[0] === 'new') {
                $phones[] = sprintf('+8529%03d%04d', 870 + $who[1], 1000 + $who[1] * 37);
            }
        }

        return $phones;
    }

    public static function demoRules(): array
    {
        return self::RULES;
    }

    /** A minimal one-page PDF holding ASCII text, so HR document downloads open in a viewer. */
    private static function pdf(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 14 Tf 72 720 Td ($text) Tj ET";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($n + 1)." 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** Four reusable templates per branch. Existing templates with the same name are kept. */
    private function templates(array $catalog, array $branchDoctors, int $admin): int
    {
        $created = 0;
        foreach ($catalog['branches'] as $branch) {
            $names = ClinicalTemplate::where('branch_id', $branch)->get()->pluck('name');
            foreach (self::TEMPLATES as $name => $concern) {
                if ($names->contains($name)) {
                    continue;
                }
                ClinicalTemplate::create(['branch_id' => $branch, 'created_by' => $branchDoctors[$branch] ?? $admin, 'name' => $name,
                    'clinical_record' => self::templateRecord($concern)]);
                $created++;
            }
        }

        return $created;
    }

    /** The purge compares templates against this exact content, so real templates are never removed. */
    public static function templateRecord(string $concern): array
    {
        [$symptoms, $notes, $diagnosis, $prescription, $plan] = self::RECORDS[$concern];

        return ['symptoms' => $symptoms, 'notes' => $notes, 'diagnosis' => $diagnosis, 'prescription' => $prescription, 'plan' => $plan];
    }

    public static function templateNames(): array
    {
        return self::TEMPLATES;
    }

    private function profile(Patient $patient, int $branch, int $i): void
    {
        $tags = [self::TAG, self::CONCERNS[$i % 10]];
        if ($i % 4 === 0) {
            $tags[] = self::CONCERNS[($i + 3) % 10];
        }
        DB::table('crm_profiles')->upsert([['branch_id' => $branch, 'patient_id' => $patient->id,
            'tags' => Crypt::encryptString(implode(', ', $tags)),
            'segment' => Crypt::encryptString(self::SEGMENTS[$i % 4])]],
            ['branch_id', 'patient_id'], ['tags', 'segment']);
    }

    /** Returning and long-term patients get one follow-up: overdue, due soon or completed. */
    private function followup(Patient $patient, int $branch, int $i): int
    {
        if (!in_array($i % 4, [1, 2], true) || DB::table('patient_followups')->where('patient_id', $patient->id)->exists()) {
            return 0;
        }
        $due = CarbonImmutable::today('Asia/Hong_Kong')->addDays(intdiv($i, 4) % 10 - 4);
        $note = ['致電了解'.self::CONCERNS[$i % 10].'調理進度', '提醒預約下次覆診', '確認處方服用情況及有否不適'][intdiv($i, 4) % 3];
        DB::table('patient_followups')->insert(['branch_id' => $branch, 'patient_id' => $patient->id,
            'due_on' => $due->toDateString(), 'note' => Crypt::encryptString($note),
            'completed_at' => $due->isPast() && $i % 3 === 0 ? $due->setTime(16, 0)->utc() : null,
            'created_at' => $due->subDays(7)->utc(), 'updated_at' => $due->subDays(7)->utc()]);

        return 1;
    }
}
