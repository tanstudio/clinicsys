<?php

namespace App\Http\Controllers;

use App\Support\Loyalty;
use App\Support\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsReportController
{
    public function __invoke(Request $request)
    {
        StaffAccess::allow('admin');
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'branch' => 'nullable|integer|exists:branches,id',
            'metric' => 'nullable|in:revenue,utilization,repeat,usage,usage_rate,efficiency',
            'format' => 'nullable|in:csv',
        ]);
        $today = CarbonImmutable::today('Asia/Hong_Kong');
        $from = CarbonImmutable::parse($data['from'] ?? $today->startOfMonth()->toDateString(), 'Asia/Hong_Kong');
        $to = CarbonImmutable::parse($data['to'] ?? $today->toDateString(), 'Asia/Hong_Kong');
        if ($to < $from || $to > $from->addYear()) {
            throw ValidationException::withMessages(['from' => '請選擇先後相符且不超過一年的日期。']);
        }
        $end = $to->addDay();
        $branch = StaffAccess::hq() ? ($data['branch'] ?? null) : StaffAccess::branch();
        abort_if(!StaffAccess::hq() && isset($data['branch']) && (int) $data['branch'] !== $branch, 403);
        $branches = DB::table('branches')->when(!StaffAccess::hq(), fn ($q) => $q->where('id', $branch))
            ->orderBy('name_zh')->pluck('name_zh', 'id');
        $scoped = $branch ? [$branch] : $branches->keys()->all();
        $slots = DB::table('slots as s')->leftJoin('appointments as a', function ($join) {
            $join->on('a.slot_id', '=', 's.id')->whereNull('a.cancelled_at');
        })->leftJoin('encounters as e', 'e.appointment_id', '=', 'a.id')
            ->whereIn('s.branch_id', $scoped)->whereNull('s.closed_at')
            ->where('s.starts_at', '>=', $from->toDateTimeString())->where('s.starts_at', '<', $end->toDateTimeString());
        $counts = (clone $slots)->groupBy('s.branch_id')
            ->selectRaw('s.branch_id, COUNT(*) AS capacity, COUNT(a.id) AS booked, COUNT(e.id) AS completed')
            ->selectRaw('COUNT(DISTINCT e.patient_id) AS patients, COUNT(DISTINCT CASE WHEN e.id IS NOT NULL AND EXISTS (
                SELECT 1 FROM encounters AS prev WHERE prev.patient_id = e.patient_id AND prev.branch_id = e.branch_id
                AND (prev.created_at < e.created_at OR (prev.created_at = e.created_at AND prev.id < e.id))
            ) THEN e.patient_id END) AS repeats')->get()->keyBy('branch_id');
        $doctors = (clone $slots)->join('practitioners as p', 'p.id', '=', 's.practitioner_id')
            ->groupBy('s.branch_id', 'p.id', 'p.name_zh')->orderBy('s.branch_id')->orderBy('p.name_zh')
            ->selectRaw('s.branch_id, p.name_zh, COUNT(*) AS capacity, COUNT(e.id) AS completed')->get();
        $utcFrom = $from->utc()->toDateTimeString();
        $utcEnd = $end->utc()->toDateTimeString();
        $sales = DB::table('receipts')->whereIn('branch_id', $scoped)
            ->where('created_at', '>=', $utcFrom)->where('created_at', '<', $utcEnd)
            ->groupBy('branch_id')->selectRaw('branch_id, SUM(amount_cents) AS total')->pluck('total', 'branch_id');
        $usage = DB::table('herbal_movements as m')->join('herbal_stocks as s', 's.id', '=', 'm.stock_id')
            ->whereIn('s.branch_id', $scoped)->where('m.kind', 'dispense')
            ->where('m.created_at', '>=', $utcFrom)->where('m.created_at', '<', $utcEnd)
            ->groupBy('s.branch_id', 's.herb')->orderBy('s.branch_id')->orderBy('s.herb')
            ->selectRaw('s.branch_id, s.herb, SUM(m.milligrams) AS mg')->get();
        $usageTotals = $usage->groupBy('branch_id')->map(fn ($rows) => $rows->sum('mg'));
        $supply = DB::table('herbal_movements as m')->join('herbal_stocks as s', 's.id', '=', 'm.stock_id')
            ->whereIn('s.branch_id', $scoped)->where('m.created_at', '<', $utcEnd)
            ->groupBy('s.branch_id')->selectRaw("s.branch_id, SUM(CASE
                WHEN m.kind = 'receive' THEN m.milligrams
                WHEN m.created_at < ? THEN -CAST(m.milligrams AS SIGNED)
                ELSE 0 END) AS available_mg", [$utcFrom])->pluck('available_mg', 'branch_id');
        $metrics = [
            'revenue' => ['實收收入', 'HK$'], 'utilization' => ['預約率', '%'], 'repeat' => ['覆診率', '%'],
            'usage' => ['藥材用量', 'g'], 'usage_rate' => ['庫存使用率', '%'], 'efficiency' => ['醫師效率', '診次／小時'],
        ];
        $metric = $data['metric'] ?? 'revenue';
        $summary = [];
        foreach ($scoped as $id) {
            $count = $counts->get($id);
            $capacity = (int) ($count->capacity ?? 0);
            $completed = (int) ($count->completed ?? 0);
            $summary[$id] = [
                'revenue' => ($sales[$id] ?? 0) / 100, 'usage' => ($usageTotals[$id] ?? 0) / 1000,
                'utilization' => $capacity ? $count->booked * 100 / $capacity : null,
                'repeat' => ($count->patients ?? 0) ? $count->repeats * 100 / $count->patients : null,
                'usage_rate' => ($supply[$id] ?? 0) > 0 ? ($usageTotals[$id] ?? 0) * 100 / $supply[$id] : null,
                'efficiency' => $capacity ? $completed / ($capacity * 0.5) : null,
            ];
        }
        // Packages: sales, refunds and net use in the period, balances left today in open unexpired packages,
        // and package income recognized under the head-office revenue basis.
        $period = fn ($q, $column = 'created_at') => $q->where($column, '>=', $utcFrom)->where($column, '<', $utcEnd);
        $sold = $period(DB::table('receipts')->whereIn('branch_id', $scoped)->where('kind', 'package'))->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) AS packages, SUM(amount_cents) AS cents')->get()->keyBy('branch_id');
        $refunds = $period(DB::table('package_refunds')->whereIn('branch_id', $scoped))->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) AS refunds, SUM(amount_cents) AS cents')->get()->keyBy('branch_id');
        $size = "NULLIF(CASE WHEN p.kind = 'stored_value' THEN p.total_value_cents ELSE p.total_sessions END, 0)";
        $used = $period(DB::table('package_ledger as l')->join('patient_packages as p', 'p.id', '=', 'l.package_id')
            ->whereIn('l.branch_id', $scoped)->whereIn('l.kind', ['redeem', 'void']), 'l.created_at')->groupBy('l.branch_id')
            ->selectRaw("l.branch_id, -SUM(l.sessions) AS sessions, -SUM(l.value_cents) AS value_cents,
                -SUM((l.sessions + l.value_cents) * CAST(p.price_cents AS SIGNED) / $size) AS earned")->get()->keyBy('branch_id');
        $left = DB::table('package_ledger as l')->join('patient_packages as p', 'p.id', '=', 'l.package_id')
            ->whereIn('p.sold_branch_id', $scoped)->where('p.status', 'active')->where('p.expires_on', '>=', $today->toDateString())
            ->groupBy('p.sold_branch_id')->selectRaw('p.sold_branch_id, SUM(l.sessions) AS sessions, SUM(l.value_cents) AS value_cents')
            ->get()->keyBy('sold_branch_id');
        $revenueBasis = Loyalty::settings()->revenue_basis;
        $packageStats = [];
        foreach ($scoped as $id) {
            $sales = (int) ($sold[$id]->cents ?? 0);
            $refunded = (int) ($refunds[$id]->cents ?? 0);
            $packageStats[$id] = ['packages' => (int) ($sold[$id]->packages ?? 0), 'revenue' => $sales / 100,
                'refunds' => (int) ($refunds[$id]->refunds ?? 0), 'refunded' => $refunded / 100,
                'redeemed' => (int) ($used[$id]->sessions ?? 0), 'value_used' => (int) ($used[$id]->value_cents ?? 0) / 100,
                'left' => (int) ($left[$id]->sessions ?? 0), 'value_left' => (int) ($left[$id]->value_cents ?? 0) / 100,
                'recognized' => ($revenueBasis === 'usage' ? (int) round($used[$id]->earned ?? 0) : $sales - $refunded) / 100];
        }
        if (($data['format'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($from, $to, $branches, $summary, $metrics) {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                $write = static function (array $row) use ($stream) {
                    // Prevent user-controlled branch names from becoming spreadsheet formulas.
                    $row = array_map(static fn ($value) => is_string($value) && preg_match('/^(?:\s*[=+@-]|[\t\r\n])/u', $value)
                        ? "'".$value : $value, $row);
                    fputcsv($stream, $row, ',', '"', '');
                };
                $write(['開始日期（香港）', '結束日期（香港）', '分店', ...array_map(fn ($m) => $m[0].'（'.$m[1].'）', $metrics)]);
                foreach ($summary as $id => $row) {
                    $values = [];
                    foreach ($metrics as $key => $label) {
                        $values[] = $row[$key] === null ? '' : number_format($row[$key], $key === 'usage' ? 3 : 2, '.', '');
                    }
                    $write([$from->toDateString(), $to->toDateString(), $branches[$id], ...$values]);
                }
                fclose($stream);
            }, 'clinic-report-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        return view('commerce.reports', compact('from', 'to', 'branch', 'branches', 'summary', 'counts', 'metrics', 'metric', 'doctors', 'usage', 'packageStats', 'revenueBasis'));
    }
}
