<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('Sample schedules require a local or testing environment.');
        }
        $catalog = [
            'branches' => [['中環', 'Central'], ['旺角', 'Mong Kok']],
            'services' => [['中醫內科', 'Chinese medicine'], ['針灸', 'Acupuncture']],
            'practitioners' => [['陳醫師', 'Dr Chan'], ['林醫師', 'Dr Lam']],
        ];
        foreach ($catalog as $table => $entries) {
            foreach ($entries as [$zh, $en]) {
                DB::table($table)->updateOrInsert(['name_zh' => $zh], ['name_en' => $en]);
                $ids[$table][] = DB::table($table)->where('name_zh', $zh)->value('id');
            }
        }
        foreach ($ids['branches'] as $branch) {
            foreach (\App\Support\BranchDirectory::KEYS as $type=>$key) {
                foreach ($ids[$type] as $id) DB::table('branch_'.$type)->insertOrIgnore(['branch_id'=>$branch,$key=>$id]);
            }
        }
        $rows = [];
        $today = today('Asia/Hong_Kong');
        for ($day = 0; $day < 14; $day++) {
            foreach ($ids['practitioners'] as $index => $doctor) {
                foreach (['09:00','09:30','10:00','10:30','14:00','14:30','15:00','15:30'] as $time) {
                    $rows[] = [
                        'branch_id' => $ids['branches'][($index + (int) $today->copy()->addDays($day)->dayOfWeek) % 2],
                        'service_id' => $ids['services'][(int) ($time >= '14:00')],
                        'practitioner_id' => $doctor,
                        'starts_at' => $today->copy()->addDays($day)->toDateString().' '.$time,
                    ];
                }
            }
        }
        DB::table('slots')->insertOrIgnore($rows);
    }
}
