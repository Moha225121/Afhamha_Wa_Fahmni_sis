<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class NisbiyaSafeImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $text, string $type): int
    {
        return app(ExcelImportService::class)->import(UploadedFile::fake()->createWithContent('qa.csv', $text), $type);
    }

    private function room(string $section = 'A'): Classroom
    {
        $year = AcademicYear::firstOrCreate(['name' => 'QA-2026'], ['starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);

        return Classroom::create(['name' => 'الصف السابع', 'section' => $section, 'stage' => 'إعدادي', 'academic_year_id' => $year->id]);
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Conflicting or unsafe imports must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_import_creates_new_school_records_without_replacing_existing_ids(): void
    {
        $room = $this->room();
        $this->assertSame(1, $this->csv("name\nالمرحلة الإعدادية", 'stages'));
        $this->assertSame(1, $this->csv("name,section,stage,academicyear\nالصف الثامن,B,إعدادي,QA-2026", 'classes'));
        $this->assertSame(1, $this->csv("code,name,stage\nQA-AR,اللغة العربية,إعدادي", 'subjects'));
        $this->assertSame(1, $this->csv("name,email\nQA Teacher,qa-teacher@example.test", 'teachers'));
        $this->assertSame(1, $this->csv("name,email,phone\nQA Parent,qa-parent@example.test,00000000", 'parents'));
        $this->assertSame(1, $this->csv("name,studentnumber,email,classroom,section,academicyear,guardianemail\nQA Student,007,qa-student@example.test,الصف السابع,A,QA-2026,qa-parent@example.test", 'students'));
        $student = Student::where('student_number', '007')->firstOrFail();
        $this->assertSame($room->id, $student->classroom_id);
        $this->assertSame('qa-student@example.test', $student->user->email);
        $this->assertSame('qa-parent@example.test', $student->guardians()->sole()->user->email);
        $this->assertNotEmpty(Teacher::firstOrFail()->teacher_number);
    }

    public function test_reimport_preserves_passwords_names_roles_status_ids_and_parent_links(): void
    {
        $room = $this->room();
        $user = User::factory()->create(['name' => 'Existing student', 'email' => 'old@example.test', 'role' => 'student', 'status' => 'inactive']);
        $student = Student::create(['user_id' => $user->id, 'student_number' => '007', 'classroom_id' => $room->id, 'status' => 'inactive']);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'inactive', 'email' => 'parent@example.test']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'inactive']);
        $student->guardians()->attach($guardian);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => 'teacher@example.test', 'status' => 'inactive']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'inactive']);
        $before = [$user->fresh()->getAttributes(), $student->fresh()->getAttributes(), $parent->fresh()->getAttributes(), $guardian->fresh()->getAttributes(), $teacherUser->fresh()->getAttributes(), $teacher->fresh()->getAttributes()];
        $this->assertSame(0, $this->csv("name,studentnumber,email\nReplacement,007,old@example.test", 'students'));
        $this->assertSame(0, $this->csv("name,email\nReplacement,parent@example.test", 'parents'));
        $this->assertSame(0, $this->csv("name,email\nReplacement,teacher@example.test", 'teachers'));
        $this->assertSame($before, [$user->fresh()->getAttributes(), $student->fresh()->getAttributes(), $parent->fresh()->getAttributes(), $guardian->fresh()->getAttributes(), $teacherUser->fresh()->getAttributes(), $teacher->fresh()->getAttributes()]);
        $this->assertSame($guardian->id, $student->guardians()->sole()->id);
    }

    public function test_identity_conflicts_roll_back_the_entire_import_without_changing_an_admin_account(): void
    {
        $admin = User::factory()->create(['email' => 'protected@example.test', 'role' => 'admin']);
        $before = $admin->fresh()->getAttributes();
        $this->rejected(fn () => $this->csv("name,email\nNew Teacher,new@example.test\nWrong Role,protected@example.test", 'teachers'));
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertDatabaseCount('teachers', 0);
    }

    public function test_a_student_number_cannot_be_rebound_to_another_email(): void
    {
        $user = User::factory()->create(['email' => 'original@example.test', 'role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'student_number' => '001', 'status' => 'active']);
        $this->rejected(fn () => $this->csv("name,studentnumber,email\nWrong,001,other@example.test", 'students'));
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertDatabaseMissing('users', ['email' => 'other@example.test']);
    }

    public function test_a_class_name_with_multiple_sections_is_never_guessed(): void
    {
        $this->room('A');
        $second = $this->room('B');
        $this->rejected(fn () => $this->csv("name,studentnumber,email,classroom\nQA,101,ambiguous@example.test,الصف السابع", 'students'));
        $this->assertDatabaseCount('students', 0);
        $this->csv("name,studentnumber,email,classroom,section\nQA,101,valid@example.test,الصف السابع,B", 'students');
        $this->assertSame($second->id, Student::where('student_number', '101')->sole()->classroom_id);
    }

    public function test_primary_stages_cannot_be_imported_and_existing_years_are_preserved(): void
    {
        $room = $this->room();
        $this->rejected(fn () => $this->csv("name\nالمرحلة الابتدائية", 'stages'));
        $this->rejected(fn () => $this->csv("name,section,stage,academicyear\nPrimary,A,ابتدائي,QA-2026", 'classes'));
        $this->rejected(fn () => $this->csv("code,name,stage\nP1,Primary,primary", 'subjects'));
        $this->assertDatabaseCount('stages', 0);
        $this->assertDatabaseCount('classrooms', 1);
        $this->assertSame($room->id, Classroom::sole()->id);
        $this->assertDatabaseCount('academic_years', 1);
    }

    private function workbook(string $xml): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nisbia-import-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return new UploadedFile($path, 'qa.xlsx', null, null, true);
    }

    public function test_xlsx_inline_strings_and_missing_cells_keep_email_in_its_original_column(): void
    {
        $cells = fn (string $ref, string $value) => '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'
            .$cells('A1', 'name').$cells('B1', 'phone').$cells('C1', 'email').'</row><row r="2">'
            .$cells('A2', 'QA Parent').$cells('C2', 'xlsx@example.test').'</row></sheetData></worksheet>';
        $file = $this->workbook($xml);
        try {
            $this->assertSame(1, app(ExcelImportService::class)->import($file, 'parents'));
            $this->assertDatabaseHas('users', ['email' => 'xlsx@example.test', 'name' => 'QA Parent', 'phone' => null]);
        } finally {
            unlink($file->getRealPath());
        }
    }

    public function test_xlsx_external_entities_and_oversized_decompressed_xml_are_rejected(): void
    {
        foreach (['<!DOCTYPE x [<!ENTITY e SYSTEM "file:///qa-forbidden">]><x>&e;</x>', str_repeat('x', 8 * 1024 * 1024 + 1)] as $xml) {
            $file = $this->workbook($xml);
            try {
                $this->rejected(fn () => app(ExcelImportService::class)->import($file, 'parents'));
                $this->assertDatabaseCount('users', 0);
            } finally {
                unlink($file->getRealPath());
            }
        }
    }
}
