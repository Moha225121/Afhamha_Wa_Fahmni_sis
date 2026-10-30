<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherReportTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    private Classroom $room;

    private Classroom $unbranched;

    private Classroom $foreignRoom;

    private Subject $math;

    private Subject $arabic;

    private Student $first;

    private Student $second;

    private Student $foreignStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-28 10:00:00');
        Http::preventStrayRequests();
        Notification::fake();
        Bus::fake();
        config(['fcm.enabled' => false, 'services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
        DB::table('settings')->updateOrInsert(['key' => 'timezone'], ['value' => 'Africa/Tripoli']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $this->room = Classroom::create(['name' => 'الصف الأول', 'section' => 'C', 'stage' => 'أساسي', 'academic_year_id' => $year->id]);
        $this->unbranched = Classroom::create(['name' => 'الثالث ثانوي', 'section' => null, 'stage' => 'ثانوي', 'academic_year_id' => $year->id]);
        $this->foreignRoom = Classroom::create(['name' => 'الصف الثاني', 'section' => 'A', 'stage' => 'أساسي', 'academic_year_id' => $year->id]);
        $this->math = Subject::create(['name' => 'الرياضيات', 'code' => 'REPORT-MATH', 'stage' => 'أساسي', 'status' => 'active']);
        $this->arabic = Subject::create(['name' => 'اللغة العربية', 'code' => 'REPORT-AR', 'stage' => 'أساسي', 'status' => 'active']);
        $this->teacherUser = User::factory()->create(['name' => 'المعلمة مريم', 'role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        $this->teacher->classrooms()->attach($this->room, ['subject_id' => $this->math->id]);
        $this->teacher->classrooms()->attach($this->unbranched, ['subject_id' => $this->arabic->id]);
        $this->room->subjects()->attach([$this->math->id, $this->arabic->id]);
        $this->unbranched->subjects()->attach($this->arabic);
        $this->first = $this->student('أحمد طالب التقرير', 'REPORT-1', $this->room);
        $this->second = $this->student('سارة طالبة التقرير', 'REPORT-2', $this->room);
        $this->foreignStudent = $this->student('طالب خارج الصف', 'REPORT-FOREIGN', $this->foreignRoom);
    }

    private function student(string $name, string $number, Classroom $room, string $status = 'active'): Student
    {
        return Student::create(['user_id' => User::factory()->create(['name' => $name, 'role' => 'student', 'status' => 'active'])->id, 'student_number' => $number, 'classroom_id' => $room->id, 'status' => $status]);
    }

    private function payload(array $config = [], array $entries = []): array
    {
        return ['classroom_id' => $this->room->id, 'title' => 'تقرير متابعة الصف', 'config' => array_replace([
            'report_type' => 'overall', 'from' => null, 'to' => null, 'subject_ids' => [$this->math->id],
            'categories' => null, 'source_keys' => null, 'thresholds' => ['excellent' => 85, 'very_good' => 75, 'good' => 65], 'aggregation' => 'mean',
        ], $config), 'entries' => $entries];
    }

    private function preview(array $config = [], array $entries = []): array
    {
        return $this->actingAs($this->teacherUser)->postJson('/teacher/reports/preview', $this->payload($config, $entries))->assertOk()->json('builder');
    }

    private function save(array $config = [], array $entries = []): array
    {
        return $this->actingAs($this->teacherUser)->postJson('/teacher/reports', $this->payload($config, $entries))->assertCreated()->json('builder');
    }

    private function entry(array $builder, ?Student $student = null, ?Subject $subject = null): array
    {
        $entry = collect($builder['entries'])->first(fn ($row) => (int) $row['student_id'] === ($student ?? $this->first)->id && (int) $row['subject_id'] === ($subject ?? $this->math)->id);
        $this->assertIsArray($entry);

        return $entry;
    }

    private function sheet(array $columns, array $marks, array $overall = []): int
    {
        return DB::table('grade_sheets')->insertGetId(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->room->id, 'sheet_data' => json_encode($columns), 'column_scores' => json_encode($marks), 'scores' => json_encode($overall), 'created_at' => now()->subMonth(), 'updated_at' => now()]);
    }

    private function exam(array $overrides = []): Exam
    {
        return Exam::create(array_replace(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->room->id, 'subject_id' => $this->math->id, 'title' => 'اختبار مسجل', 'starts_at' => '2026-10-28 08:00:00', 'duration_minutes' => 30, 'total_score' => 20, 'status' => 'published'], $overrides));
    }

    private function mark(Exam $exam, float $score, ?Student $student = null): void
    {
        DB::table('grades')->insert(['exam_id' => $exam->id, 'student_id' => ($student ?? $this->first)->id, 'score' => $score, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function attempt(Exam $exam, float $score, int $number = 1, string $status = 'submitted', ?string $gradedAt = '2026-10-28 09:00:00'): void
    {
        ExamAttempt::create(['exam_id' => $exam->id, 'student_id' => $this->first->id, 'attempt_number' => $number, 'started_at' => now()->subHours(2), 'expires_at' => now()->subHour(), 'submitted_at' => now()->subHour(), 'graded_at' => $gradedAt, 'score' => $score, 'maximum_score' => 20, 'percentage' => $score * 5, 'status' => $status]);
    }

    private function classify(string $key, string $type, string $date = '2026-10-28', ?Subject $subject = null): array
    {
        $source = ['key' => $key, 'assessment_type' => $type];
        if (! str_starts_with($key, 'exam:')) {
            $source += ['assessment_date' => $date, 'subject_id' => ($subject ?? $this->math)->id];
        }

        return $this->actingAs($this->teacherUser)->postJson('/teacher/reports/sources', ['classroom_id' => $this->room->id, 'sources' => [$source]])->assertOk()->json('catalog');
    }

    private function editedEntry(array $overrides = []): array
    {
        return array_replace(['student_id' => $this->first->id, 'subject_id' => $this->math->id, 'score_mode' => 'auto', 'score' => null, 'rating_mode' => 'auto', 'rating' => null, 'behaviour' => null, 'activity' => '', 'notes' => ''], $overrides);
    }

    private function reportEdits(array $builder): array
    {
        $fields = array_fill_keys(['student_id', 'subject_id', 'score_mode', 'score', 'rating_mode', 'rating', 'behaviour', 'activity', 'notes'], true);

        return array_map(fn ($entry) => array_intersect_key($entry, $fields), $builder['entries']);
    }

    public function test_routes_require_an_active_teacher_and_do_not_disclose_reports_to_other_roles(): void
    {
        foreach (['/teacher/reports', '/teacher/reports/create?classroom_id='.$this->room->id, '/teacher/reports/999', '/teacher/reports/999/print'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach (['admin', 'parent', 'student', 'supervisor'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get('/teacher/reports')->assertForbidden();
            $this->postJson('/teacher/reports/preview', $this->payload())->assertForbidden();
            $this->postJson('/teacher/reports', $this->payload())->assertForbidden();
        }
        $this->teacher->update(['status' => 'inactive']);
        $this->actingAs($this->teacherUser)->get('/teacher/reports')->assertForbidden();
    }

    public function test_index_and_class_builder_use_only_existing_assigned_classes_subjects_and_current_students(): void
    {
        $this->student('طالب غير نشط', 'REPORT-INACTIVE', $this->room, 'inactive');
        $before = DB::table('classrooms')->orderBy('id')->get()->toJson();
        $response = $this->actingAs($this->teacherUser)->get('/teacher/reports')->assertOk();
        $rooms = collect($response->viewData('dashboard')['classrooms']);
        $this->assertEqualsCanonicalizing([$this->room->id, $this->unbranched->id], $rooms->pluck('id')->all());
        $this->assertSame('الثالث ثانوي', $rooms->firstWhere('id', $this->unbranched->id)['label']);
        $builder = $this->get('/teacher/reports/create?classroom_id='.$this->room->id)->assertOk()->viewData('builder');
        $this->assertCount(2, $builder['entries']);
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], collect($builder['entries'])->pluck('student_id')->all());
        $this->assertSame([$this->math->id], collect($builder['catalog']['subjects'])->pluck('id')->all());
        $this->assertSame($before, DB::table('classrooms')->orderBy('id')->get()->toJson());
    }

    public function test_unassigned_classes_and_subject_pairs_cannot_be_selected(): void
    {
        $this->actingAs($this->teacherUser)->get('/teacher/reports/create?classroom_id='.$this->foreignRoom->id)->assertForbidden();
        $this->postJson('/teacher/reports/preview', $this->payload(['subject_ids' => [$this->arabic->id]]))->assertUnprocessable();
        $foreignPayload = $this->payload();
        $foreignPayload['classroom_id'] = $this->foreignRoom->id;
        $this->postJson('/teacher/reports', $foreignPayload)->assertForbidden();
    }

    public function test_sheet_marks_use_actual_maxima_preserve_zero_and_leave_unassessed_students_blank(): void
    {
        $this->sheet([['key' => 'one', 'title' => 'تقييم أول', 'max_score' => 10], ['key' => 'two', 'title' => 'تقييم ثان', 'max_score' => 20]], ['one' => [$this->first->id => 8, $this->foreignStudent->id => 10], 'two' => [$this->first->id => 0]], [$this->first->id => 99]);
        $builder = $this->preview();
        $entry = $this->entry($builder);
        $this->assertEqualsWithDelta(40, $entry['automatic_score'], 0.001);
        $this->assertSame('attention', $entry['rating']);
        $this->assertCount(2, $entry['evidence']);
        $this->assertSame('auto', $entry['score_mode']);
        $this->assertNull($this->entry($builder, $this->second)['score']);
        $this->assertNull($this->entry($builder, $this->second)['rating']);
        $this->assertNull($entry['behaviour']);
        $this->assertSame('', $entry['activity']);
        $this->assertSame('', $entry['notes']);
    }

    public function test_report_aggregation_is_explicit_and_supports_mean_or_total_points(): void
    {
        $this->sheet([['key' => 'one', 'title' => 'أول', 'max_score' => 10], ['key' => 'two', 'title' => 'ثان', 'max_score' => 20]], ['one' => [$this->first->id => 8], 'two' => [$this->first->id => 0]]);
        $this->assertEqualsWithDelta(40, $this->entry($this->preview())['score'], 0.001);
        $this->assertEqualsWithDelta(26.67, $this->entry($this->preview(['aggregation' => 'points']))['score'], 0.001);
    }

    public function test_a_multi_subject_sheet_is_not_copied_into_each_subject_without_explicit_mapping(): void
    {
        $this->teacher->classrooms()->attach($this->room, ['subject_id' => $this->arabic->id]);
        $sheet = $this->sheet([['key' => 'monthly', 'title' => 'اختبار شهري', 'max_score' => 20]], ['monthly' => [$this->first->id => 16]]);
        $config = ['subject_ids' => [$this->math->id, $this->arabic->id]];
        $builder = $this->preview($config);
        $this->assertNull($this->entry($builder)['score']);
        $this->assertNull($this->entry($builder, $this->first, $this->arabic)['score']);
        $this->classify('sheet:'.$sheet.':column:monthly', 'monthly', '2026-10-28', $this->arabic);
        $builder = $this->preview($config);
        $this->assertNull($this->entry($builder)['score']);
        $this->assertEqualsWithDelta(80, $this->entry($builder, $this->first, $this->arabic)['score'], 0.001);
    }

    public function test_weekly_and_monthly_reports_do_not_guess_assessment_dates_from_sheet_update_times(): void
    {
        $sheet = $this->sheet([['key' => 'monthly', 'title' => 'شهري', 'max_score' => 20]], ['monthly' => [$this->first->id => 18]]);
        $this->assertNull($this->entry($this->preview(['report_type' => 'monthly']))['score']);
        $this->classify('sheet:'.$sheet.':column:monthly', 'monthly');
        $this->assertEqualsWithDelta(90, $this->entry($this->preview(['report_type' => 'monthly']))['score'], 0.001);
        $this->assertNull($this->entry($this->preview(['report_type' => 'weekly']))['score']);
    }

    public function test_type_and_inclusive_school_date_filters_include_only_matching_assessments(): void
    {
        $inside = $this->exam(['title' => 'أسبوعي داخل الأسبوع', 'starts_at' => '2026-10-24 22:30:00']);
        $outside = $this->exam(['title' => 'أسبوعي بعد نهاية الأسبوع', 'starts_at' => '2026-10-31 22:00:00']);
        $monthly = $this->exam(['title' => 'شهري في نفس الأسبوع']);
        foreach ([$inside, $outside, $monthly] as $exam) {
            $this->mark($exam, 18);
        }
        $this->classify('exam:'.$inside->id, 'weekly');
        $this->classify('exam:'.$outside->id, 'weekly');
        $this->classify('exam:'.$monthly->id, 'monthly');
        $builder = $this->preview(['report_type' => 'weekly', 'from' => '2026-10-25', 'to' => '2026-10-31']);
        $entry = $this->entry($builder);
        $this->assertSame(['exam:'.$inside->id], array_column($entry['evidence'], 'key'));
        $this->assertEqualsWithDelta(90, $entry['score'], 0.001);
    }

    public function test_midterm_final_and_overall_presets_can_be_customized_by_selected_categories_and_sources(): void
    {
        $midterm = $this->exam(['title' => 'نصفي']);
        $final = $this->exam(['title' => 'نهائي']);
        $this->mark($midterm, 10);
        $this->mark($final, 18);
        $this->classify('exam:'.$midterm->id, 'midterm');
        $this->classify('exam:'.$final->id, 'final');
        $this->assertEqualsWithDelta(50, $this->entry($this->preview(['report_type' => 'midterm']))['score'], 0.001);
        $this->assertEqualsWithDelta(90, $this->entry($this->preview(['report_type' => 'final']))['score'], 0.001);
        $this->assertEqualsWithDelta(70, $this->entry($this->preview())['score'], 0.001);
        $this->assertEqualsWithDelta(90, $this->entry($this->preview(['report_type' => 'midterm', 'categories' => ['final']]))['score'], 0.001);
        $this->assertEqualsWithDelta(50, $this->entry($this->preview(['source_keys' => ['exam:'.$midterm->id]]))['score'], 0.001);
        $this->postJson('/teacher/reports/preview', $this->payload(['report_type' => 'weekly', 'source_keys' => ['exam:'.$final->id]]))->assertUnprocessable();
    }

    public function test_valid_manual_exam_grades_take_precedence_and_fully_graded_attempts_are_not_duplicated_or_pending(): void
    {
        $manual = $this->exam(['title' => 'درجة يدوية مع محاولة']);
        $this->mark($manual, 12);
        $this->attempt($manual, 18);
        $automatic = $this->exam(['title' => 'آخر محاولة مصححة']);
        $this->attempt($automatic, 4, 1);
        $this->attempt($automatic, 16, 2);
        $this->attempt($automatic, 20, 3, 'pending_review', null);
        $pending = $this->exam(['title' => 'تصحيح لم يكتمل']);
        $this->attempt($pending, 10, 1, 'pending_review', null);
        $entry = $this->entry($this->preview());
        $this->assertCount(2, $entry['evidence']);
        $this->assertEqualsWithDelta(70, $entry['score'], 0.001);
        $this->assertEqualsCanonicalizing([12.0, 16.0], array_column($entry['evidence'], 'score'));
    }

    public function test_other_teachers_unassigned_subjects_and_invalid_marks_do_not_enter_the_report(): void
    {
        $other = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $foreignTeacherExam = $this->exam(['teacher_id' => $other->id, 'title' => 'درجة معلم آخر']);
        $foreignSubjectExam = $this->exam(['subject_id' => $this->arabic->id, 'title' => 'مادة غير مسندة']);
        $invalid = $this->exam(['title' => 'درجة أكبر من النهاية']);
        $this->mark($foreignTeacherExam, 20);
        $this->mark($foreignSubjectExam, 20);
        $this->mark($invalid, 21);
        $entry = $this->entry($this->preview());
        $this->assertNull($entry['score']);
        $this->assertSame([], $entry['evidence']);
    }

    public function test_manual_only_reports_can_be_created_without_inventing_an_automatic_zero(): void
    {
        $exam = $this->exam();
        $this->mark($exam, 20);
        $builder = $this->save(['source_keys' => []], [$this->editedEntry(['score_mode' => 'manual', 'score' => 0])]);
        $entry = $this->entry($builder);
        $this->assertNull($entry['automatic_score']);
        $this->assertSame('manual', $entry['score_mode']);
        $this->assertEqualsWithDelta(0, $entry['score'], 0.001);
        $this->assertSame('attention', $entry['rating']);
        $this->assertNull($this->entry($builder, $this->second)['score']);
    }

    public function test_manual_grades_ratings_behaviour_activity_and_notes_roundtrip_without_modifying_official_grades(): void
    {
        $exam = $this->exam();
        $this->mark($exam, 16);
        $before = DB::table('grades')->orderBy('id')->get()->toJson();
        $builder = $this->save([], [$this->editedEntry(['score_mode' => 'manual', 'score' => 92.5, 'rating_mode' => 'manual', 'rating' => 'good', 'behaviour' => 'calm', 'activity' => 'شارك في النشاط', 'notes' => 'يحتاج إلى تنظيم دفتره'])]);
        $loaded = $this->get('/teacher/reports/'.$builder['report']['id'])->assertOk()->viewData('builder');
        $entry = $this->entry($loaded);
        $this->assertEqualsWithDelta(80, $entry['automatic_score'], 0.001);
        $this->assertEqualsWithDelta(92.5, $entry['score'], 0.001);
        $this->assertSame('good', $entry['rating']);
        $this->assertSame('manual', $entry['rating_mode']);
        $this->assertSame('calm', $entry['behaviour']);
        $this->assertSame('شارك في النشاط', $entry['activity']);
        $this->assertSame('يحتاج إلى تنظيم دفتره', $entry['notes']);
        $this->assertSame($before, DB::table('grades')->orderBy('id')->get()->toJson());
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_saved_automatic_grades_stay_frozen_until_explicit_refresh_and_manual_overrides_survive_refresh(): void
    {
        $exam = $this->exam();
        $this->mark($exam, 10);
        $this->mark($exam, 16, $this->second);
        $builder = $this->save([], [
            $this->editedEntry(['behaviour' => 'calm', 'notes' => 'ملاحظة محفوظة']),
            $this->editedEntry(['student_id' => $this->second->id, 'score_mode' => 'manual', 'score' => 0, 'rating_mode' => 'manual', 'rating' => 'good', 'behaviour' => 'disruptive', 'activity' => 'نشاط محفوظ', 'notes' => 'ملاحظة الطالبة']),
        ]);
        $id = $builder['report']['id'];
        DB::table('grades')->where('exam_id', $exam->id)->update(['score' => 18]);
        $loaded = $this->get('/teacher/reports/'.$id)->assertOk()->viewData('builder');
        $this->assertEqualsWithDelta(50, $this->entry($loaded)['score'], 0.001);
        $this->assertEqualsWithDelta(80, $this->entry($loaded, $this->second)['automatic_score'], 0.001);
        $this->assertEqualsWithDelta(0, $this->entry($loaded, $this->second)['score'], 0.001);
        $payload = $this->payload([], [$this->editedEntry(['behaviour' => 'calm', 'notes' => 'ملاحظة معدلة'])]) + ['revision' => $loaded['report']['revision'], 'refresh_sources' => false];
        $updated = $this->putJson('/teacher/reports/'.$id, $payload)->assertOk()->json('builder');
        $this->assertEqualsWithDelta(50, $this->entry($updated)['automatic_score'], 0.001);
        $payload['revision'] = $updated['report']['revision'];
        $payload['refresh_sources'] = true;
        $payload['entries'] = $this->reportEdits($updated);
        $payload['entries'][0] = $this->editedEntry(['score_mode' => 'manual', 'score' => 77, 'behaviour' => 'calm', 'notes' => 'ملاحظة معدلة']);
        $refreshed = $this->putJson('/teacher/reports/'.$id, $payload)->assertOk()->json('builder');
        $this->assertEqualsWithDelta(90, $this->entry($refreshed)['automatic_score'], 0.001);
        $this->assertEqualsWithDelta(77, $this->entry($refreshed)['score'], 0.001);
        $this->assertSame('ملاحظة معدلة', $this->entry($refreshed)['notes']);
        $second = $this->entry($refreshed, $this->second);
        $this->assertEqualsWithDelta(90, $second['automatic_score'], 0.001);
        $this->assertEqualsWithDelta(0, $second['score'], 0.001);
        $this->assertSame('manual', $second['score_mode']);
        $this->assertSame('manual', $second['rating_mode']);
        $this->assertSame('good', $second['rating']);
        $this->assertSame('disruptive', $second['behaviour']);
        $this->assertSame('نشاط محفوظ', $second['activity']);
        $this->assertSame('ملاحظة الطالبة', $second['notes']);
        $reopened = $this->getJson('/teacher/reports/'.$id)->assertOk()->json('builder');
        $this->assertSame($refreshed['entries'], $reopened['entries']);
    }

    public function test_an_ordinary_manual_save_preserves_null_overall_filters_and_intentionally_blank_manual_values(): void
    {
        $exam = $this->exam();
        $this->mark($exam, 16);
        $builder = $this->save();
        $id = $builder['report']['id'];
        $this->assertNull($builder['config']['categories']);
        $this->assertNull($builder['config']['source_keys']);
        $edits = $this->reportEdits($builder);
        $edits[0] = $this->editedEntry(['score_mode' => 'manual', 'score' => null, 'rating_mode' => 'manual', 'rating' => 'excellent', 'behaviour' => 'calm', 'activity' => 'عمل جماعي', 'notes' => 'درجة فارغة مقصودة']);
        $payload = $this->payload($builder['config'], $edits) + ['revision' => $builder['report']['revision'], 'refresh_sources' => false];
        $updated = $this->putJson('/teacher/reports/'.$id, $payload)->assertOk()->json('builder');
        $this->assertSame($builder['config'], $updated['config']);
        $this->assertSame($builder['selected_sources'], $updated['selected_sources']);
        $entry = $this->entry($updated);
        $this->assertEqualsWithDelta(80, $entry['automatic_score'], 0.001);
        $this->assertNull($entry['score']);
        $this->assertSame('manual', $entry['score_mode']);
        $this->assertSame('manual', $entry['rating_mode']);
        $this->assertSame('excellent', $entry['rating']);
        $this->assertSame('calm', $entry['behaviour']);
        $this->assertSame('عمل جماعي', $entry['activity']);
        $this->assertSame('درجة فارغة مقصودة', $entry['notes']);
        $this->assertSame($builder['report']['revision'] + 1, $updated['report']['revision']);
        $reopened = $this->getJson('/teacher/reports/'.$id)->assertOk()->json('builder');
        $this->assertSame($updated['config'], $reopened['config']);
        $this->assertSame($updated['entries'], $reopened['entries']);
    }

    public function test_an_ordinary_update_keeps_the_saved_roster_but_explicit_refresh_uses_the_current_roster(): void
    {
        $exam = $this->exam();
        $this->mark($exam, 10);
        $this->mark($exam, 16, $this->second);
        $builder = $this->save([], [
            $this->editedEntry(['score_mode' => 'manual', 'score' => 77, 'rating_mode' => 'manual', 'rating' => 'good', 'behaviour' => 'calm', 'activity' => 'نشاط أحمد', 'notes' => 'ملاحظة أحمد']),
            $this->editedEntry(['student_id' => $this->second->id, 'score_mode' => 'manual', 'score' => 0, 'notes' => 'ملاحظة تاريخية']),
        ]);
        $id = $builder['report']['id'];
        $this->second->update(['classroom_id' => $this->foreignRoom->id]);
        $newStudent = $this->student('طالب انضم حديثًا', 'REPORT-NEW', $this->room);
        $this->mark($exam, 12, $newStudent);
        DB::table('grades')->where('exam_id', $exam->id)->where('student_id', $this->first->id)->update(['score' => 18]);
        $loaded = $this->getJson('/teacher/reports/'.$id)->assertOk()->json('builder');
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], array_column($loaded['entries'], 'student_id'));
        $this->assertEqualsCanonicalizing([$this->first->id, $newStudent->id], array_column($loaded['catalog']['students'], 'id'));
        $this->assertSame($builder['entries'], $loaded['entries']);
        $edits = $this->reportEdits($loaded);
        $secondIndex = array_search($this->second->id, array_column($edits, 'student_id'), true);
        $edits[$secondIndex]['notes'] = 'تعديل ملاحظة الطالبة في التقرير المحفوظ';
        $payload = $this->payload($loaded['config'], $edits) + ['revision' => $loaded['report']['revision'], 'refresh_sources' => false];
        $updated = $this->putJson('/teacher/reports/'.$id, $payload)->assertOk()->json('builder');
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], array_column($updated['entries'], 'student_id'));
        $this->assertEqualsWithDelta(50, $this->entry($updated)['automatic_score'], 0.001);
        $this->assertEqualsWithDelta(0, $this->entry($updated, $this->second)['score'], 0.001);
        $this->assertSame('تعديل ملاحظة الطالبة في التقرير المحفوظ', $this->entry($updated, $this->second)['notes']);
        $this->get('/teacher/reports/'.$id.'/print?student_id='.$this->second->id)->assertOk()->assertSeeText($this->second->user->name);

        $payload['revision'] = $updated['report']['revision'];
        $payload['refresh_sources'] = true;
        $this->putJson('/teacher/reports/'.$id, $payload)->assertUnprocessable();
        $currentIds = array_column($updated['catalog']['students'], 'id');
        $payload['entries'] = array_values(array_filter($this->reportEdits($updated), fn ($entry) => in_array($entry['student_id'], $currentIds, true)));
        $refreshed = $this->putJson('/teacher/reports/'.$id, $payload)->assertOk()->json('builder');
        $this->assertEqualsCanonicalizing([$this->first->id, $newStudent->id], array_column($refreshed['entries'], 'student_id'));
        $this->assertEqualsWithDelta(90, $this->entry($refreshed)['automatic_score'], 0.001);
        $this->assertEqualsWithDelta(77, $this->entry($refreshed)['score'], 0.001);
        $this->assertSame('good', $this->entry($refreshed)['rating']);
        $this->assertSame('calm', $this->entry($refreshed)['behaviour']);
        $this->assertSame('نشاط أحمد', $this->entry($refreshed)['activity']);
        $this->assertSame('ملاحظة أحمد', $this->entry($refreshed)['notes']);
        $newEntry = $this->entry($refreshed, $newStudent);
        $this->assertEqualsWithDelta(60, $newEntry['score'], 0.001);
        $this->assertSame('auto', $newEntry['score_mode']);
        $this->assertNull($newEntry['behaviour']);
        $this->assertSame('', $newEntry['notes']);
        $this->get('/teacher/reports/'.$id.'/print?student_id='.$this->second->id)->assertNotFound();
    }

    public function test_stale_revisions_fail_with_a_conflict_without_overwriting_the_newer_report(): void
    {
        $builder = $this->save();
        $id = $builder['report']['id'];
        $payload = $this->payload([], [$this->editedEntry(['notes' => 'نسخة حديثة'])]) + ['revision' => $builder['report']['revision']];
        $newer = $this->putJson('/teacher/reports/'.$id, $payload)->assertOk()->json('builder');
        $payload['entries'][0]['notes'] = 'نسخة قديمة لا تُحفظ';
        $this->putJson('/teacher/reports/'.$id, $payload)->assertConflict();
        $current = $this->get('/teacher/reports/'.$id)->assertOk()->viewData('builder');
        $this->assertSame($newer['report']['revision'], $current['report']['revision']);
        $this->assertSame('نسخة حديثة', $this->entry($current)['notes']);
    }

    public function test_report_ownership_and_revoked_class_assignments_are_checked_on_show_update_and_print(): void
    {
        $builder = $this->save();
        $id = $builder['report']['id'];
        $otherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $other = Teacher::create(['user_id' => $otherUser->id, 'status' => 'active']);
        $other->classrooms()->attach($this->room, ['subject_id' => $this->math->id]);
        $this->actingAs($otherUser)->get('/teacher/reports/'.$id)->assertNotFound();
        $this->get('/teacher/reports/'.$id.'/print')->assertNotFound();
        $this->putJson('/teacher/reports/'.$id, $this->payload() + ['revision' => 1])->assertNotFound();
        DB::table('teacher_assignments')->where('teacher_id', $this->teacher->id)->where('classroom_id', $this->room->id)->delete();
        $this->actingAs($this->teacherUser)->get('/teacher/reports/'.$id)->assertNotFound();
        $this->get('/teacher/reports/'.$id.'/print')->assertNotFound();
    }

    public function test_printed_student_card_is_private_and_escapes_manually_entered_content(): void
    {
        $builder = $this->save([], [$this->editedEntry(['notes' => '<script>reportAttack()</script>', 'activity' => '<img src=x onerror=reportAttack()>', 'behaviour' => 'disruptive'])]);
        $id = $builder['report']['id'];
        $this->get('/teacher/reports/'.$id.'/print?format=cards&student_id='.$this->first->id)->assertOk()
            ->assertSeeText($this->first->user->name)->assertDontSeeText($this->second->user->name)
            ->assertDontSee('<script>reportAttack()', false)->assertDontSee('<img src=x onerror=reportAttack()>', false);
        $this->get('/teacher/reports/'.$id.'/print?format=cards&student_id='.$this->foreignStudent->id)->assertNotFound();
        $this->get('/teacher/reports/'.$id.'/print?format=class')->assertOk()->assertSeeText($this->first->user->name)->assertSeeText($this->second->user->name);
    }

    public function test_malformed_filters_foreign_students_duplicates_and_invalid_manual_values_are_rejected_cleanly(): void
    {
        foreach ([['report_type' => 'invented'], ['from' => 'not-a-date'], ['from' => '2026-11-01', 'to' => '2026-10-01'], ['thresholds' => ['excellent' => 60, 'very_good' => 75, 'good' => 65]], ['source_keys' => ['exam:999999']]] as $config) {
            $this->actingAs($this->teacherUser)->postJson('/teacher/reports/preview', $this->payload($config))->assertUnprocessable();
        }
        foreach ([['student_id' => $this->foreignStudent->id], ['subject_id' => $this->arabic->id], ['score_mode' => 'manual', 'score' => 101], ['rating_mode' => 'manual', 'rating' => 'invented'], ['behaviour' => 'invented']] as $entry) {
            $this->postJson('/teacher/reports', $this->payload([], [$this->editedEntry($entry)]))->assertUnprocessable();
        }
        $entry = $this->editedEntry();
        $this->postJson('/teacher/reports', $this->payload([], [$entry, $entry]))->assertUnprocessable();
        foreach (['999999999999999999999999999', '0', '-1'] as $id) {
            $this->get('/teacher/reports/'.$id)->assertNotFound();
            $this->get('/teacher/reports/'.$id.'/print')->assertNotFound();
        }
    }

    public function test_source_metadata_does_not_allow_foreign_sources_or_rewriting_exam_subject_and_date(): void
    {
        $exam = $this->exam();
        foreach ([['key' => 'exam:999999', 'assessment_type' => 'weekly'], ['key' => 'exam:'.$exam->id, 'subject_id' => $this->arabic->id, 'assessment_type' => 'weekly'], ['key' => 'exam:'.$exam->id, 'assessment_date' => '2025-01-01', 'assessment_type' => 'weekly']] as $source) {
            $this->actingAs($this->teacherUser)->postJson('/teacher/reports/sources', ['classroom_id' => $this->room->id, 'sources' => [$source]])->assertUnprocessable();
        }
    }

    public function test_reading_classifying_saving_and_printing_reports_does_not_change_official_student_records_or_send_notifications(): void
    {
        $sheet = $this->sheet([['key' => 'monthly', 'title' => 'شهري', 'max_score' => 20]], ['monthly' => [$this->first->id => 15]]);
        $exam = $this->exam();
        $this->mark($exam, 12);
        $tables = ['classrooms', 'students', 'teacher_assignments', 'grade_sheets', 'exams', 'grades', 'exam_attempts', 'result_marks', 'notifications'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $this->classify('sheet:'.$sheet.':column:monthly', 'monthly');
        $this->preview(['report_type' => 'monthly']);
        $builder = $this->save();
        $this->get('/teacher/reports')->assertOk();
        $this->get('/teacher/reports/'.$builder['report']['id'])->assertOk();
        $this->get('/teacher/reports/'.$builder['report']['id'].'/print')->assertOk();
        $this->assertSame($before, $snapshot());
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_missing_report_tables_have_a_readable_setup_state_and_writes_fail_as_validation_instead_of_server_errors(): void
    {
        Schema::dropIfExists('teacher_report_deliveries');
        Schema::dropIfExists('teacher_report_dispatches');
        Schema::dropIfExists('teacher_reports');
        Schema::dropIfExists('teacher_report_sources');
        $response = $this->actingAs($this->teacherUser)->get('/teacher/reports')->assertOk();
        $this->assertFalse($response->viewData('ready'));
        $this->postJson('/teacher/reports', $this->payload())->assertUnprocessable();
        $this->postJson('/teacher/reports/sources', ['classroom_id' => $this->room->id, 'sources' => []])->assertUnprocessable();
    }
}
