<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SchoolAccountService
{
    public function settings(bool $lock = false): array
    {
        // SQLite has no SELECT FOR UPDATE. Acquire its write reservation before reading counters.
        if ($lock && DB::connection()->getDriverName() === 'sqlite') {
            DB::table('account_number_settings')->where('id', 1)->update(['id' => 1]);
        }
        $query = DB::table('account_number_settings')->where('id', 1);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        $settings = array_replace(config('school_accounts'), json_decode($row?->configuration ?? '{}', true));
        $settings['school_name'] = DB::table('settings')->where('key', 'school_name')->value('value') ?: $settings['school_name'];

        return $settings;
    }

    public function context(?Classroom $classroom = null): array
    {
        $year = $classroom?->academicYear ?? AcademicYear::where('is_current', true)->first();

        return ['YEAR' => (string) ($year?->starts_at?->year ?? now()->year), 'GRADE' => $classroom?->name ?? 'NA', 'CLASS' => $classroom?->section ?? 'NA'];
    }

    public function number(array $settings, string $role, int $sequence, array $context): string
    {
        $pattern = $settings[$role.'_number_pattern'];
        $tokens = $context + ['BRANCH' => $settings['school_code'], 'SEQ' => str_pad((string) $sequence, (int) $settings[$role.'_digits'], '0', STR_PAD_LEFT)];
        $value = preg_replace_callback('/\{(YEAR|GRADE|BRANCH|CLASS|SEQ|0+1)\}/', fn ($m) => preg_match('/^0+1$/', $m[1]) ? str_pad((string) $sequence, strlen($m[1]), '0', STR_PAD_LEFT) : $tokens[$m[1]], $pattern);
        if (preg_match('/[{}\x00-\x1f]/u', $value) || mb_strlen($value) > 100 || trim($value) === '') {
            throw ValidationException::withMessages([$role.'_number_pattern' => 'النمط ينتج رقمًا غير صالح أو طويلًا جدًا.']);
        }

        return $value;
    }

    public function email(array $settings, string $role, string $number, array $names, array $context): string
    {
        $clean = fn ($s) => strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $s));
        $first = $clean($names['first_name_en'] ?? '');
        $school = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $settings['school_english_name']));
        if ($first === '' || $school === '') {
            throw ValidationException::withMessages(['first_name_en' => 'أكمل الاسم الأول الإنجليزي للمستخدم واسم المدرسة الإنجليزي قبل توليد البريد.']);
        }
        $tokens = ['FIRST_NAME' => $first, 'LAST_NAME' => $clean($names['last_name_en'] ?? ''), 'ACADEMIC_NUMBER' => $clean($number), 'TEACHER_NUMBER' => $clean($number), 'YEAR' => $context['YEAR'], 'SCHOOL_NAME' => $school];
        $value = strtolower(preg_replace_callback('/\{([A-Z_]+)\}/', fn ($m) => $tokens[$m[1]] ?? $m[0], $settings[$role.'_email_pattern']));
        // School identifiers intentionally support the requested awf_school domain convention.
        if (! preg_match('/^[a-z0-9][a-z0-9._+-]*@[a-z0-9][a-z0-9_.-]*\.[a-z]{2,}$/D', $value) || strlen($value) > 254 || strlen(explode('@', $value)[0]) > 64 || str_contains($value, '..')) {
            throw ValidationException::withMessages([$role.'_email_pattern' => 'نمط البريد غير صالح أو يتجاوز الطول المسموح.']);
        }

        return $value;
    }

    private function scope(array $settings, string $role, array $context): string
    {
        return $role.':'.($settings[$role.'_reset_yearly'] ? $context['YEAR'] : 'all');
    }

    private function uniqueEmail(string $email, array &$reserved): string
    {
        [$local, $domain] = explode('@', $email);
        $candidate = $email;
        for ($suffix = 2; isset($reserved[strtolower($candidate)]); $suffix++) {
            $tail = '_'.$suffix;
            $candidate = substr($local, 0, min(64 - strlen($tail), 253 - strlen($domain) - strlen($tail))).$tail.'@'.$domain;
        }
        $reserved[strtolower($candidate)] = true;

        return $candidate;
    }

    public function create(string $role, array $userData, array $profileData): Student|Teacher
    {
        return DB::transaction(function () use ($role, $userData, $profileData) {
            $settings = $this->settings(true);
            $classroom = isset($profileData['classroom_id']) ? Classroom::find($profileData['classroom_id']) : null;
            $context = $this->context($classroom);
            $scope = $this->scope($settings, $role, $context);
            $column = $role === 'student' ? 'student_number' : 'teacher_number';
            $model = $role === 'student' ? Student::class : Teacher::class;
            $sequence = max((int) $settings[$role.'_start'], (int) DB::table('number_sequences')->where('scope', $scope)->value('next_value'));
            $number = $profileData[$column] ?? null;
            if (! filled($number)) {
                do {
                    $number = $this->number($settings, $role, $sequence++, $context);
                } while ($model::withTrashed()->where($column, $number)->exists());
                DB::table('number_sequences')->updateOrInsert(['scope' => $scope], ['next_value' => $sequence]);
            }
            if ($settings[$role.'_auto_email'] && filled($userData['first_name_en'] ?? null)) {
                $reserved = array_fill_keys(User::pluck('email')->map(fn ($e) => strtolower($e))->all(), true);
                $userData['email'] = $this->uniqueEmail($this->email($settings, $role, $number, $userData, $context), $reserved);
                $userData['school_email_generated'] = true;
            }
            if (! filled($userData['email'] ?? null)) {
                throw ValidationException::withMessages(['email' => 'أدخل الاسم الأول الإنجليزي لتوليد البريد، أو أدخل بريدًا عند تعطيل التوليد.']);
            }
            $user = User::create($userData + ['role' => $role]);
            $record = $model::create(array_replace($profileData, ['user_id' => $user->id, $column => $number]));
            $this->audit('account_generated', $role.'s', $record->id, [], [$column => $number, 'email' => $user->email]);

            return $record;
        }, 3);
    }

    public function audit(string $action, string $module, ?int $id, array $old, array $new): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => $action, 'module' => $module, 'record_id' => $id, 'old_values' => $old ?: null, 'new_values' => $new ?: null, 'ip_address' => request()->ip()]);
    }

    public function preview(array $settings, array $options): array
    {
        $rows = [];
        $counters = [];
        $targets = [];
        foreach (['student' => Student::class, 'teacher' => Teacher::class] as $role => $model) {
            if (($options['renumber_'.$role] ?? false) || ($options['update_'.$role.'_emails'] ?? false)) {
                $targets[$role] = $model::withTrashed()->with($role === 'student' ? ['user', 'classroom.academicYear'] : ['user'])->orderBy('id')->get();
            }
        }
        $emailUserIds = collect($targets)->flatMap(fn ($records, $role) => ($options['update_'.$role.'_emails'] ?? false) ? $records->pluck('user_id') : []);
        $reservedEmails = array_fill_keys(User::whereNotIn('id', $emailUserIds)->pluck('email')->map(fn ($e) => strtolower($e))->all(), true);
        foreach ($targets as $role => $records) {
            $column = $role === 'student' ? 'student_number' : 'teacher_number';
            $reservedNumbers = [];
            foreach ($records as $record) {
                $context = $this->context($role === 'student' ? $record->classroom : null);
                $number = $record->$column;
                if ($options['renumber_'.$role] ?? false) {
                    $scope = $this->scope($settings, $role, $context);
                    $counters[$scope] ??= (int) $settings[$role.'_start'];
                    do {
                        $number = $this->number($settings, $role, $counters[$scope]++, $context);
                    } while (isset($reservedNumbers[strtolower($number)]));
                    $reservedNumbers[strtolower($number)] = true;
                }
                $email = $record->user->email;
                if ($options['update_'.$role.'_emails'] ?? false) {
                    if (! filled($record->user->first_name_en)) {
                        throw ValidationException::withMessages(['first_name_en' => 'أكمل الاسم الأول الإنجليزي للحساب: '.$record->user->name.' قبل إعادة توليد البريد.']);
                    }
                    if (! $number) {
                        throw ValidationException::withMessages(['options' => 'فعّل إعادة ترقيم المعلمين أولًا لتوليد بريدهم.']);
                    }
                    $email = $this->uniqueEmail($this->email($settings, $role, $number, $record->user->getAttributes(), $context), $reservedEmails);
                }
                $rows[] = ['role' => $role, 'id' => $record->id, 'user_id' => $record->user_id, 'name' => $record->user->name, 'old_number' => $record->$column, 'number' => $number, 'old_email' => $record->user->email, 'email' => $email, 'updated_at' => (string) $record->updated_at, 'user_updated_at' => (string) $record->user->updated_at];
            }
        }
        $revision = hash('sha256', json_encode([$this->settings(), $settings, $options, $rows, User::count(), Student::withTrashed()->count(), Teacher::withTrashed()->count()]));

        return compact('rows', 'counters', 'revision');
    }

    public function save(array $settings, array $options, string $revision): void
    {
        DB::transaction(function () use ($settings, $options, $revision): void {
            $old = $this->settings(true);
            $plan = $this->preview($settings, $options);
            if (! hash_equals($plan['revision'], $revision)) {
                throw ValidationException::withMessages(['preview' => 'تغيرت البيانات منذ المعاينة. أعد المعاينة قبل الحفظ.']);
            }
            // Free all changing unique values first, so number/email swaps remain valid.
            $batch = Str::uuid()->toString();
            foreach ($plan['rows'] as $row) {
                $column = $row['role'] === 'student' ? 'student_number' : 'teacher_number';
                if ($row['number'] !== $row['old_number']) {
                    DB::table($row['role'].'s')->where('id', $row['id'])->update([$column => '~'.$batch.'-'.$row['id']]);
                }
                if ($row['email'] !== $row['old_email']) {
                    DB::table('users')->where('id', $row['user_id'])->update(['email' => $batch.'-'.$row['user_id'].'@renumber.invalid']);
                }
            }
            foreach ($plan['rows'] as $row) {
                $column = $row['role'] === 'student' ? 'student_number' : 'teacher_number';
                DB::table($row['role'].'s')->where('id', $row['id'])->update([$column => $row['number'], 'updated_at' => now()]);
                if ($options['update_'.$row['role'].'_emails'] ?? false) {
                    DB::table('users')->where('id', $row['user_id'])->update(['email' => $row['email'], 'school_email_generated' => true, 'updated_at' => now()]);
                }
                $this->audit('account_regenerated', $row['role'].'s', $row['id'], [$column => $row['old_number'], 'email' => $row['old_email']], [$column => $row['number'], 'email' => $row['email']]);
            }
            foreach ($plan['counters'] as $scope => $next) {
                DB::table('number_sequences')->updateOrInsert(['scope' => $scope], ['next_value' => $next]);
            }
            DB::table('account_number_settings')->where('id', 1)->update(['configuration' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            DB::table('settings')->updateOrInsert(['key' => 'school_name'], ['value' => $settings['school_name'], 'group' => 'school', 'updated_at' => now()]);
            $this->audit('account_settings_updated', 'account_settings', 1, $old, $settings);
        }, 3);
    }
}
