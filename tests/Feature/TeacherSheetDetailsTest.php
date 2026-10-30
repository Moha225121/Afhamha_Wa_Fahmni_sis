<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ResultPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherSheetDetailsTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private array $fixture;

    private Teacher $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nasbia.teacher_sheet_visibility' => true]);
        $this->fixture = $this->createStudentFixture('SHEET-DETAIL');
        $subject = $this->createSubjectFor($this->fixture['classroom'], 'SHEET-DETAIL');
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $this->teacher->classrooms()->attach($this->fixture['classroom'], ['subject_id' => $subject->id]);
    }

    private function sheet(array $columns, array $scores): int
    {
        return DB::table('grade_sheets')->insertGetId([
            'teacher_id' => $this->teacher->id, 'classroom_id' => $this->fixture['classroom']->id,
            'sheet_data' => json_encode($columns), 'column_scores' => json_encode($scores),
            'updated_at' => now(),
        ]);
    }

    public function test_actual_zero_and_fractional_marks_render_for_student_and_linked_parent(): void
    {
        $id = $this->fixture['student']->id;
        $this->sheet([
            ['key' => 'zero', 'title' => 'اختبار الصفر', 'max_score' => 20],
            ['key' => 'fraction', 'title' => 'اختبار الكسور', 'max_score' => 20],
        ], ['zero' => [$id => 0], 'fraction' => [$id => 18.5]]);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $guardian->students()->attach($id);

        $this->actingAs($this->fixture['user'])->get(route('student.results'))->assertOk()
            ->assertSee('0 من 20')->assertSee('18.5 من 20');
        $this->actingAs($parent)->get(route('parent.results', ['student' => $id]))->assertOk()
            ->assertSee('0 من 20')->assertSee('18.5 من 20');
        $this->assertCount(0, app(ResultPublicationService::class)->visibleResults($this->fixture['student']));
    }

    public function test_malformed_legacy_json_and_unknown_or_invalid_scores_are_skipped(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Emulate a historical text schema without relaxing real PostgreSQL JSON constraints.
            DB::statement('ALTER TABLE grade_sheets ALTER COLUMN sheet_data TYPE text USING sheet_data::text');
            DB::statement('ALTER TABLE grade_sheets ALTER COLUMN column_scores TYPE text USING column_scores::text');
        }
        $id = $this->fixture['student']->id;
        $sheetId = $this->sheet([], []);
        foreach (['broken-json', 'null', '"scalar"', '42', '{}'] as $json) {
            DB::table('grade_sheets')->where('id', $sheetId)->update(['sheet_data' => $json, 'column_scores' => $json]);
            $this->assertCount(0, app(ResultPublicationService::class)->teacherSheetDetails($this->fixture['student']));
        }
        DB::table('grade_sheets')->where('id', $sheetId)->delete();
        $this->sheet([
            ['key' => 'valid', 'title' => '<script>اختبار</script>', 'max_score' => 20],
            ['key' => 'over', 'max_score' => 20], ['key' => 'negative', 'max_score' => 20],
            ['key' => 'badmax', 'max_score' => 0], ['key' => 'nested', 'max_score' => 20],
            ['key' => ['invalid']], false,
        ], ['valid' => [$id => 18], 'over' => [$id => 21], 'negative' => [$id => -1],
            'badmax' => [$id => 0], 'nested' => [$id => []], 'orphan' => [$id => 19]]);
        $rows = app(ResultPublicationService::class)->teacherSheetDetails($this->fixture['student']);
        $this->assertCount(1, $rows);
        $this->assertSame(18.0, $rows->first()->score);
        $this->actingAs($this->fixture['user'])->get(route('student.results'))->assertOk()
            ->assertSee('&lt;script&gt;اختبار&lt;/script&gt;', false)->assertDontSee('<script>اختبار</script>', false);
    }

    public function test_multiple_subject_assignments_and_duplicate_column_keys_do_not_duplicate_marks(): void
    {
        $subject = $this->createSubjectFor($this->fixture['classroom'], 'SECOND');
        $this->teacher->classrooms()->attach($this->fixture['classroom'], ['subject_id' => $subject->id]);
        $this->sheet([
            ['key' => 'test', 'title' => 'اختبار', 'max_score' => 20],
            ['key' => 'test', 'title' => 'نسخة أخرى', 'max_score' => 20],
        ], ['test' => [$this->fixture['student']->id => 18]]);
        $rows = app(ResultPublicationService::class)->teacherSheetDetails($this->fixture['student']);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('مادة SECOND', $rows->first()->subject);
        $this->assertStringContainsString('مادة SHEET-DETAIL', $rows->first()->subject);
    }

    public function test_student_and_parent_cannot_read_other_students_marks_or_unassigned_sheets(): void
    {
        $other = $this->createStudentFixture('OTHER-SHEET');
        $this->sheet([['key' => 'private', 'title' => 'درجة خاصة', 'max_score' => 20]],
            ['private' => [$other['student']->id => 19]]);
        $service = app(ResultPublicationService::class);
        $this->assertCount(0, $service->teacherSheetDetails($this->fixture['student']));
        $this->assertCount(0, $service->teacherSheetDetails($other['student']));
        $this->assertCount(0, $service->teacherSheetDetails(null));
        DB::table('grade_sheets')->update(['column_scores' => json_encode(['private' => [$this->fixture['student']->id => 18]])]);
        $this->teacher->classrooms()->detach();
        $this->assertCount(0, $service->teacherSheetDetails($this->fixture['student']));
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $this->actingAs($parent)->get(route('parent.results', ['student' => $this->fixture['student']->id]))->assertNotFound();
    }
}
