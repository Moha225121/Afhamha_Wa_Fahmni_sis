<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class ExcelImportService
{
    public const TYPES = ['students' => 'الطلاب', 'teachers' => 'المعلمون', 'subjects' => 'المواد', 'classes' => 'الصفوف', 'stages' => 'المراحل', 'parents' => 'أولياء الأمور'];

    public function import(UploadedFile $file, string $type): int
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $rows = $this->rows($file);
        if ($rows === []) throw ValidationException::withMessages(['file' => 'الملف فارغ أو لا يحتوي على صف عناوين.']);
        return DB::transaction(fn () => match ($type) {
            'students' => $this->students($rows), 'teachers' => $this->teachers($rows), 'subjects' => $this->subjects($rows),
            'classes' => $this->classes($rows), 'stages' => $this->stages($rows), 'parents' => $this->parents($rows),
        });
    }

    private function rows(UploadedFile $file): array
    {
        if (strtolower($file->getClientOriginalExtension()) === 'csv') {
            $handle = fopen($file->getRealPath(), 'rb'); $rows = [];
            while (($row = fgetcsv($handle)) !== false) $rows[] = $row;
            fclose($handle); return $this->normalise($rows);
        }
        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) throw ValidationException::withMessages(['file' => 'تعذر قراءة ملف Excel.']);
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            if ($sx !== false) {
                $ns = $sx->getDocNamespaces(true)[''] ?? null;
                $strings = $ns ? $sx->children($ns)->si : $sx->si;
                foreach ($strings as $si) $shared[] = (string) ($si->t ?? implode('', array_map(fn ($r) => (string) $r->t, $si->r ?? [])));
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
        if ($sheet === false) throw ValidationException::withMessages(['file' => 'لم يتم العثور على ورقة العمل الأولى.']);
        $sx = simplexml_load_string($sheet);
        if ($sx === false) throw ValidationException::withMessages(['file' => 'تعذر解析 ورقة العمل الأولى.']);
        $rows = [];
        $xmlRows = $sx->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
        foreach ($xmlRows as $row) { $values = []; foreach (($row->xpath('./*[local-name()="c"]') ?: []) as $cell) { $ref = (string) $cell['r']; preg_match('/[A-Z]+/', $ref, $m); $col = 0; foreach (str_split($m[0] ?? '') as $ch) $col = $col * 26 + ord($ch) - 64; $valueNodes = $cell->xpath('./*[local-name()="v"]'); $value = (string) ($valueNodes[0] ?? ''); if ((string) $cell['t'] === 's') $value = $shared[(int) $value] ?? ''; $values[$col - 1] = $value; } ksort($values); $rows[] = array_values($values); }
        return $this->normalise($rows);
    }

    private function normalise(array $rows): array
    {
        if ($rows === []) return [];
        $headers = array_map(fn ($v) => Str::of((string) $v)->replace("\xEF\xBB\xBF", '')->trim()->lower()->replace([' ', '-', '_'], '')->toString(), array_shift($rows)); $out = [];
        foreach ($rows as $index => $row) { $item = []; foreach ($headers as $i => $header) if ($header !== '') $item[$header] = trim((string) ($row[$i] ?? '')); if (count(array_filter($item, fn ($v) => $v !== '')) > 0) $out[] = $item + ['_row' => $index + 2]; }
        return $out;
    }

    private function value(array $row, array $keys, bool $required = true): ?string
    {
        foreach ($keys as $key) { $key = Str::of($key)->lower()->replace([' ', '-', '_'], '')->toString(); if (filled($row[$key] ?? null)) return $row[$key]; }
        if ($required) throw ValidationException::withMessages(['file' => 'الصف '.$row['_row'].' يفتقد حقلًا مطلوبًا ('.implode(' / ', $keys).').']); return null;
    }

    private function students(array $rows): int
    {
        foreach ($rows as $row) { $name = $this->value($row, ['name','الاسم','اسم الطالب']); $number = $this->value($row, ['studentnumber','الرقمالدراسي','الرقم']); $email = $this->value($row, ['email','البريد'], false) ?: 'student.'.Str::slug($number).'@school.local'; $class = $this->classroom($this->value($row, ['classroom','class','الصف'], false), $row); $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => app(AccountPasswordService::class)->forNewAccount(null), 'role' => 'student', 'status' => 'active']); $student = Student::updateOrCreate(['student_number' => $number], ['user_id' => $user->id, 'classroom_id' => $class?->id, 'birth_date' => $this->value($row, ['birthdate','تاريخالميلاد'], false), 'gender' => $this->value($row, ['gender','النوع'], false), 'address' => $this->value($row, ['address','العنوان'], false), 'status' => 'active']); $guardianName = $this->value($row, ['guardianname','وليالأمر','اسموليالأمر'], false); $guardianPhone = $this->value($row, ['guardianphone','هاتفوليالأمر','هاتفالولي','الهاتف'], false); $guardianEmail = $this->value($row, ['guardianemail','وليالأمرالبريد'], false); if ($guardianEmail || $guardianName || $guardianPhone) { $guardianEmail ??= 'parent.'.Str::slug($guardianPhone ?: $number).'@school.local'; $gUser = User::updateOrCreate(['email' => $guardianEmail], ['name' => $guardianName ?: 'ولي أمر', 'phone' => $guardianPhone, 'password' => app(AccountPasswordService::class)->forNewAccount(null), 'role' => 'parent', 'status' => 'active']); $guardian = Guardian::updateOrCreate(['user_id' => $gUser->id], ['relationship' => $this->value($row, ['relationship','صلةالقرابة'], false), 'status' => 'active']); $student->guardians()->syncWithoutDetaching([$guardian->id]); } }
        return count($rows);
    }

    private function teachers(array $rows): int
    { foreach ($rows as $row) { $email = $this->value($row, ['email','البريد']); $user = User::updateOrCreate(['email' => $email], ['name' => $this->value($row, ['name','الاسم']), 'password' => app(AccountPasswordService::class)->forNewAccount(null), 'role' => 'teacher', 'status' => 'active']); Teacher::updateOrCreate(['user_id' => $user->id], ['specialization' => $this->value($row, ['specialization','التخصص'], false), 'status' => 'active']); } return count($rows); }
    private function subjects(array $rows): int
    { foreach ($rows as $row) Subject::updateOrCreate(['code' => $this->value($row, ['code','الرمز'])], ['name' => $this->value($row, ['name','الاسم']), 'stage' => $this->value($row, ['stage','المرحلة'], false), 'description' => $this->value($row, ['description','الوصف'], false), 'status' => 'active']); return count($rows); }
    private function classes(array $rows): int
    { foreach ($rows as $row) { $year = AcademicYear::firstOrCreate(['name' => $this->value($row, ['academicyear','year','السنةالدراسية'])], ['starts_at' => now()->startOfYear(), 'ends_at' => now()->endOfYear()]); Classroom::updateOrCreate(['name' => $this->value($row, ['name','الصف']), 'section' => $this->value($row, ['section','الشعبة'], false), 'academic_year_id' => $year->id], ['stage' => $this->value($row, ['stage','المرحلة']),]); } return count($rows); }
    private function stages(array $rows): int
    { foreach ($rows as $row) Stage::updateOrCreate(['name' => $this->value($row, ['name','stage','المرحلة','الاسم'])], ['status' => 'active']); return count($rows); }
    private function parents(array $rows): int
    { foreach ($rows as $row) { $email = $this->value($row, ['email','البريد']); $u = User::updateOrCreate(['email' => $email], ['name' => $this->value($row, ['name','الاسم']), 'phone' => $this->value($row, ['phone','الهاتف'], false), 'password' => app(AccountPasswordService::class)->forNewAccount(null), 'role' => 'parent', 'status' => 'active']); Guardian::updateOrCreate(['user_id' => $u->id], ['relationship' => $this->value($row, ['relationship','صلةالقرابة'], false), 'status' => 'active']); } return count($rows); }
    private function classroom(?string $name, array $row): ?Classroom
    { return $name ? Classroom::where('name', $name)->when($row['academicyear'] ?? null, fn ($q, $v) => $q->whereHas('academicYear', fn ($y) => $y->where('name', $v)))->first() : null; }
}
