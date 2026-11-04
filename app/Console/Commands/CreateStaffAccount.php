<?php

namespace App\Console\Commands;

use App\Support\StaffAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateStaffAccount extends Command
{
    protected $signature = 'clinic:staff {email} {name} {role} {--branch= : Branch ID. Omit for a head-office admin.}';
    protected $description = 'Create a staff account and prompt privately for its password';

    public function handle(): int
    {
        $data = [
            'email' => strtolower($this->argument('email')), 'name' => $this->argument('name'),
            'role' => $this->argument('role'), 'branch_id' => $this->option('branch'),
            'password' => $this->secret('Password (at least 12 characters)'),
        ];
        $validator = Validator::make($data, [
            'email' => ['required','email','max:255','unique:staff_accounts,email'],
            'name' => ['required','string','max:255'],
            'role' => ['required', Rule::in(StaffAccess::ROLES)],
            'branch_id' => ['required_unless:role,admin','nullable','integer','exists:branches,id'],
            'password' => ['required','string','min:12','max:72'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);
        DB::table('staff_accounts')->insert($data + ['created_at'=>now(),'updated_at'=>now()]);
        $this->info('Staff account created.');
        return self::SUCCESS;
    }
}
