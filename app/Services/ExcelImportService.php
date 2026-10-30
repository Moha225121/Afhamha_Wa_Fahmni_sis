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
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

/** Create-only imports: existing identities, credentials and school records are never updated. */
class ExcelImportService
{
    public const TYPES = ['students' => 'الطلاب', 'teachers' => 'المعلمون', 'subjects' => 'المواد', 'classes' => 'الصفوف', 'stages' => 'المراحل', 'parents' => 'أولياء الأمور'];

    public function import(UploadedFile $file, string $type): int
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        if ($file->getSize() > 10 * 1024 * 1024) {
            $this->invalid('حجم ملف الاستيراد يتجاوز 10 ميجابايت.');
        }
        $rows = $this->rows($file);
        if ($rows === []) {
            $this->invalid('الملف فارغ أو لا يحتوي بيانات بعد صف العناوين.');
        }
        try {
            return DB::transaction(function () use ($rows, $type): int {
                app(SchoolAccountService::class)->settings(true);
                $created = 0;
                foreach ($rows as $row) {
                    $created += match ($type) {
                        'students' => $this->student($row), 'teachers' => $this->teacher($row),
                        'parents' => $this->parent($row), 'subjects' => $this->subject($row),
                        'classes' => $this->schoolClass($row), 'stages' => $this->stage($row),
                    };
                }
                AuditService::record('import_created', $type);

                return $created;
            }, 3);
        } catch (QueryException) {
            $this->invalid('تعارضت بيانات الملف مع سجلات المدرسة. لم يُحفظ أي تغيير من هذا الاستيراد.');
        }
    }

    private function rows(UploadedFile $file): array
    {
        if (in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            $handle = fopen($file->getRealPath(), 'rb');
            try {
                $rows = [];
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $rows[] = $row;
                    if (count($rows) > 5001) {
                        $this->invalid('الحد الأقصى 5000 صف بيانات.');
                    }
                }
            } finally {
                fclose($handle);
            }

            return $this->normalise($rows);
        }
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $this->invalid('تعذر قراءة ملف Excel.');
        }
        try {
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $xml = $this->xml($this->entry($zip, 'xl/sharedStrings.xml'));
                foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $string) {
                    $shared[] = implode('', array_map(fn ($text) => (string) $text, $string->xpath('.//*[local-name()="t"]') ?: []));
                }
            }
            $sheet = $this->xml($this->entry($zip, 'xl/worksheets/sheet1.xml'));
            $xmlRows = $sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
            if (count($xmlRows) > 5001) {
                $this->invalid('الحد الأقصى 5000 صف بيانات.');
            }
            $rows = [];
            foreach ($xmlRows as $xmlRow) {
                $values = [];
                foreach ($xmlRow->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                    if (! preg_match('/^([A-Z]+)[1-9][0-9]*$/', (string) $cell['r'], $match)) {
                        $this->invalid('إحداثيات خلية غير صالحة.');
                    }
                    $column = 0;
                    foreach (str_split($match[1]) as $char) {
                        $column = $column * 26 + ord($char) - 64;
                    }
                    if ($column > 100) {
                        $this->invalid('الحد الأقصى 100 عمود.');
                    }
                    $v = $cell->xpath('./*[local-name()="v"]');
                    $value = (string) ($v[0] ?? '');
                    if ((string) $cell['t'] === 's') {
                        $value = $shared[(int) $value] ?? '';
                    }
                    if ((string) $cell['t'] === 'inlineStr') {
                        $value = implode('', array_map(fn ($text) => (string) $text, $cell->xpath('./*[local-name()="is"]//*[local-name()="t"]') ?: []));
                    }
                    $values[$column - 1] = $value;
                }
                // Keep missing cells in their actual columns, rather than shifting identities left.
                $row = $values ? array_fill(0, max(array_keys($values)) + 1, '') : [];
                foreach ($values as $index => $value) {
                    $row[$index] = $value;
                }
                $rows[] = $row;
            }

            return $this->normalise($rows);
        } finally {
            $zip->close();
        }
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if (! $stat || $stat['size'] > 8 * 1024 * 1024) {
            $this->invalid('ورقة Excel مفقودة أو أكبر من الحد الآمن.');
        }
        $text = $zip->getFromName($name);
        if ($text === false) {
            $this->invalid('تعذر قراءة ورقة Excel.');
        }

        return $text;
    }

    private function xml(string $text): SimpleXMLElement
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $text)) {
            $this->invalid('تعريفات XML الخارجية غير مسموحة.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $this->invalid('تعذر قراءة XML في ورقة العمل.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function key(string $text): string
    {
        return Str::of($text)->replace("\xEF\xBB\xBF", '')->trim()->lower()->replace([' ', '-', '_'], '')->toString();
    }

    private function normalise(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $headers = array_map(fn ($value) => $this->key((string) $value), array_shift($rows));
        $named = array_filter($headers, fn ($header) => $header !== '');
        if (count($headers) > 100 || count(array_unique($named)) !== count($named)) {
            $this->invalid('عناوين الأعمدة مكررة أو كثيرة.');
        }
        $out = [];
        foreach ($rows as $index => $row) {
            $item = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $item[$header] = trim((string) ($row[$i] ?? ''));
                }
            }
            if (count(array_filter($item, fn ($value) => $value !== '')) > 0) {
                $out[] = $item + ['_row' => $index + 2];
            }
        }

        return $out;
    }

    private function value(array $row, array $keys, bool $required = true): ?string
    {
        foreach ($keys as $key) {
            if (filled($row[$this->key($key)] ?? null)) {
                return $row[$this->key($key)];
            }
        }
        if ($required) {
            $this->invalid('الصف '.$row['_row'].' يفتقد حقلًا مطلوبًا.');
        }

        return null;
    }

    private function email(array $row, array $keys = ['email', 'البريد']): string
    {
        $email = strtolower($this->value($row, $keys));
        if (Validator::make(['email' => $email], ['email' => 'required|email|max:254'])->fails()) {
            $this->invalid('الصف '.$row['_row'].' يحتوي بريدًا غير صالح.');
        }

        return $email;
    }

    private function accountData(array $row, string $email): array
    {
        $name = $this->value($row, ['name', 'الاسم', 'اسم الطالب']);
        if (mb_strlen($name) > 255) {
            $this->invalid('الاسم أطول من الحد المسموح.');
        }

        return ['name' => $name, 'email' => $email, 'status' => 'active', 'password' => app(AccountPasswordService::class)->forNewAccount(null)];
    }

    private function student(array $row): int
    {
        $number = $this->value($row, ['studentnumber', 'الرقمالدراسي', 'الرقم']);
        $email = $this->email($row);
        $existing = Student::withTrashed()->with('user')->where('student_number', $number)->first();
        if ($existing) {
            if (strtolower((string) $existing->user?->email) !== $email) {
                $this->invalid('الصف '.$row['_row'].' يتعارض فيه الرقم الدراسي مع الحساب الموجود.');
            }

            return 0;
        }
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->invalid('البريد مرتبط بحساب موجود؛ لا يمكن إعادة ربطه من الاستيراد.');
        }
        $classroom = $this->classroom($row);
        $profile = ['student_number' => $number, 'classroom_id' => $classroom?->id, 'status' => 'active',
            'birth_date' => $this->value($row, ['birthdate', 'تاريخالميلاد'], false), 'gender' => $this->value($row, ['gender', 'النوع'], false), 'address' => $this->value($row, ['address', 'العنوان'], false)];
        if (Validator::make($profile, ['student_number' => 'string|max:50', 'birth_date' => 'nullable|date', 'gender' => 'nullable|in:male,female,ذكر,أنثى', 'address' => 'nullable|string|max:2000'])->fails()) {
            $this->invalid('بيانات الطالب غير صالحة في الصف '.$row['_row'].'.');
        }
        $student = app(SchoolAccountService::class)->create('student', $this->accountData($row, $email), $profile);
        $guardianEmail = $this->value($row, ['guardianemail', 'وليالأمرالبريد'], false);
        $guardianName = $this->value($row, ['guardianname', 'وليالأمر', 'اسموليالأمر'], false);
        $guardianPhone = $this->value($row, ['guardianphone', 'هاتفوليالأمر', 'هاتفالولي'], false);
        if ($guardianEmail || $guardianName || $guardianPhone) {
            if (! $guardianEmail) {
                $this->invalid('بريد ولي الأمر مطلوب لإنشاء الرابط دون تخمين هوية الحساب.');
            }
            $guardian = $this->guardian(['email' => $guardianEmail, 'name' => $guardianName ?: 'ولي أمر', 'phone' => $guardianPhone, '_row' => $row['_row']]);
            $student->guardians()->syncWithoutDetaching([$guardian->id]);
        }

        return 1;
    }

    private function teacher(array $row): int
    {
        $email = $this->email($row);
        if ($user = User::whereRaw('lower(email) = ?', [$email])->first()) {
            if ($user->role !== 'teacher' || ! Teacher::withTrashed()->where('user_id', $user->id)->exists()) {
                $this->invalid('البريد مرتبط بهوية أخرى أو بملف غير مكتمل.');
            }

            return 0;
        }
        app(SchoolAccountService::class)->create('teacher', $this->accountData($row, $email), ['specialization' => $this->value($row, ['specialization', 'التخصص'], false), 'status' => 'active']);

        return 1;
    }

    private function guardian(array $row): Guardian
    {
        $email = $this->email($row);
        if ($user = User::whereRaw('lower(email) = ?', [$email])->first()) {
            if ($user->role !== 'parent' || ! $user->guardian) {
                $this->invalid('بريد ولي الأمر مرتبط بهوية أخرى أو بملف غير مكتمل.');
            }

            return $user->guardian;
        }
        $user = User::create($this->accountData($row, $email) + ['role' => 'parent', 'phone' => $this->value($row, ['phone', 'الهاتف'], false)]);

        return Guardian::create(['user_id' => $user->id, 'relationship' => $this->value($row, ['relationship', 'صلةالقرابة'], false), 'status' => 'active']);
    }

    private function parent(array $row): int
    {
        $existed = User::whereRaw('lower(email) = ?', [$this->email($row)])->exists();
        $this->guardian($row);

        return $existed ? 0 : 1;
    }

    private function allowedStage(string $name): void
    {
        $key = TeacherAssignmentGroups::gradeKey(mb_strtolower($name));
        $allowed = ['اعدادي', 'اعدادية', 'الاعدادي', 'الاعدادية', 'المرحلةالاعدادية', 'preparatory', 'middle', 'ثانوي', 'ثانوية', 'الثانوي', 'الثانوية', 'المرحلةالثانوية', 'secondary'];
        if (! in_array($key, $allowed, true)) {
            $this->invalid('الاستيراد متاح للمرحلتين الإعدادية والثانوية فقط.');
        }
    }

    private function subject(array $row): int
    {
        $code = $this->value($row, ['code', 'الرمز']);
        if (Subject::where('code', $code)->exists()) {
            return 0;
        }
        $stage = $this->value($row, ['stage', 'المرحلة']);
        $this->allowedStage($stage);
        Subject::create(['code' => $code, 'name' => $this->value($row, ['name', 'الاسم']), 'stage' => $stage, 'description' => $this->value($row, ['description', 'الوصف'], false), 'status' => 'active']);

        return 1;
    }

    private function schoolClass(array $row): int
    {
        $year = AcademicYear::where('name', $this->value($row, ['academicyear', 'year', 'السنةالدراسية']))->first();
        if (! $year) {
            $this->invalid('أنشئ السنة الدراسية في النظام أولًا ثم أعد الاستيراد.');
        }
        $key = ['name' => $this->value($row, ['name', 'الصف']), 'section' => $this->value($row, ['section', 'الشعبة'], false), 'academic_year_id' => $year->id];
        if (Classroom::withTrashed()->where($key)->exists()) {
            return 0;
        }
        $stage = $this->value($row, ['stage', 'المرحلة']);
        $this->allowedStage($stage);
        Classroom::create($key + ['stage' => $stage]);

        return 1;
    }

    private function stage(array $row): int
    {
        $name = $this->value($row, ['name', 'stage', 'المرحلة', 'الاسم']);
        $this->allowedStage($name);
        if (Stage::where('name', $name)->exists()) {
            return 0;
        }
        Stage::create(['name' => $name, 'status' => 'active']);

        return 1;
    }

    private function classroom(array $row): ?Classroom
    {
        $name = $this->value($row, ['classroom', 'class', 'الصف'], false);
        $id = $this->value($row, ['classroomid', 'رقمالفصل'], false);
        if (! $name && ! $id) {
            return null;
        }
        $query = Classroom::query()->when($id, fn ($q) => $q->whereKey($id))->when($name, fn ($q) => $q->where('name', $name));
        if ($section = $this->value($row, ['section', 'الشعبة'], false)) {
            $query->where('section', $section);
        }
        if ($year = $this->value($row, ['academicyear', 'السنةالدراسية'], false)) {
            $query->whereHas('academicYear', fn ($q) => $q->where('name', $year));
        }
        $rooms = $query->limit(2)->get();
        if ($rooms->count() !== 1) {
            $this->invalid('حدد فصلًا واحدًا موجودًا بالشعبة والسنة الدراسية أو بمعرف الفصل.');
        }
        $this->allowedStage($rooms->first()->stage);

        return $rooms->first();
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
