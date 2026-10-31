<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Exam;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SubjectDisplayName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class ParentSubjectFilterTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private User $parent;
    private Guardian $guardian;
    private Student $student;
    private Teacher $teacher;
    private Subject $physics;
    private Subject $math;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = $this->createStudentFixture('FILTER')['student'];
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $this->guardian->students()->attach($this->student);
        $this->teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $this->physics = Subject::create(['name' => 'Physics', 'code' => 'FILTER-P', 'stage' => 'أساسي', 'status' => 'active']);
        $this->math = Subject::create(['name' => 'Mathematics', 'code' => 'FILTER-M', 'stage' => 'أساسي', 'status' => 'active']);
        $this->actingAs($this->parent);
    }

    public function test_homework_filters_by_subject_with_arabic_labels_and_keeps_the_child_selected(): void
    {
        $this->assignment($this->physics, 'واجب الضوء');
        $this->assignment($this->math, 'واجب الكسور');
        $this->assignment($this->math, 'واجب لم ينشر', ['published_at' => now()->addDay()]);
        $this->assignment($this->math, 'مسودة واجب', ['status' => 'draft']);

        $this->get('/parent/assignments')->assertOk()->assertSeeText('المادة: الفيزياء')->assertSeeText('المادة: الرياضيات')
            ->assertDontSeeText('Physics')->assertDontSeeText('Mathematics');
        $response = $this->get($this->url('assignments', $this->physics));
        $response->assertOk()->assertSeeText('واجب الضوء')->assertDontSeeText('واجب الكسور')
            ->assertDontSeeText('واجب لم ينشر')->assertDontSeeText('مسودة واجب')
            ->assertViewHas('assignments', fn ($items) => $items->count() === 1)
            ->assertViewHas('subjects', fn ($items) => $items->count() === 2)
            ->assertSee('name="student" value="'.$this->student->id.'"', false)
            ->assertSee('href="'.url('/parent/assignments').'?student='.$this->student->id.'"', false);
    }

    public function test_subject_choices_do_not_reveal_drafts_or_another_classroom(): void
    {
        $other = $this->createStudentFixture('OTHER')['student'];
        $secret = Subject::create(['name' => 'مادة خاصة', 'code' => 'PRIVATE', 'stage' => 'أساسي']);
        $this->assignment($this->physics, 'واجب ظاهر');
        $this->assignment($secret, 'واجب صف آخر', ['classroom_id' => $other->classroom_id]);
        $this->exam($secret, 'اختبار صف آخر', ['classroom_id' => $other->classroom_id]);
        $this->exam($secret, 'مسودة اختبار', ['status' => 'draft']);

        $this->get('/parent/assignments')->assertOk()->assertDontSeeText('مادة خاصة')->assertDontSeeText('واجب صف آخر');
        $this->get('/parent/exams')->assertOk()->assertDontSeeText('مادة خاصة')->assertDontSeeText('اختبار صف آخر');
    }

    public function test_exam_filter_applies_to_upcoming_and_previous_exams(): void
    {
        $this->exam($this->physics, 'اختبار الضوء القادم', ['starts_at' => now()->addDay()]);
        $this->exam($this->physics, 'اختبار الضوء السابق');
        $this->exam($this->math, 'اختبار الكسور القادم', ['starts_at' => now()->addDays(2)]);
        $this->exam($this->math, 'اختبار الكسور السابق');

        $this->get($this->url('exams', $this->physics))->assertOk()
            ->assertSeeText('اختبار الضوء القادم')->assertSeeText('اختبار الضوء السابق')
            ->assertSeeText('المادة: الفيزياء')->assertDontSeeText('Physics')
            ->assertDontSeeText('اختبار الكسور القادم')->assertDontSeeText('اختبار الكسور السابق')
            ->assertViewHas('upcomingExams', fn ($items) => $items->count() === 1)
            ->assertViewHas('previousExams', fn ($items) => $items->count() === 1);
    }

    public function test_results_filter_manual_and_automatic_grades_and_recalculate_summary(): void
    {
        $manual = $this->exam($this->physics, 'اختبار فيزياء مصحح');
        $this->grade($manual, 8);
        $automatic = $this->exam($this->physics, 'اختبار فيزياء إلكتروني');
        DB::table('exam_attempts')->insert([
            'exam_id' => $automatic->id, 'student_id' => $this->student->id, 'attempt_number' => 1,
            'status' => 'submitted', 'started_at' => now()->subHour(), 'expires_at' => now(),
            'submitted_at' => now(), 'graded_at' => now(), 'score' => 10, 'maximum_score' => 10, 'percentage' => 100,
        ]);
        $this->grade($this->exam($this->math, 'اختبار رياضيات مصحح'), 2);

        $this->get($this->url('results', $this->physics))->assertOk()
            ->assertSeeText('اختبار فيزياء مصحح')->assertSeeText('اختبار فيزياء إلكتروني')
            ->assertDontSeeText('اختبار رياضيات مصحح')->assertSeeText('المادة: الفيزياء')
            ->assertViewHas('summary', fn ($summary) => $summary['published_grades'] === 2 && $summary['average_percent'] === 90.0)
            ->assertViewHas('recentGrades', fn ($items) => $items->count() === 2);
        $this->get('/parent/results')->assertOk()->assertViewHas('summary', fn ($summary) => $summary['published_grades'] === 3);
    }

    public function test_period_reports_filter_subject_rows_without_showing_overall_totals_as_subject_totals(): void
    {
        $this->publication('نتائج الفترة الأولى', 'published', [
            ['subject_id' => $this->physics->id, 'subject' => 'Physics', 'score' => 8, 'maximum_score' => 10, 'notes' => 'ملاحظة الفيزياء'],
            ['subject_id' => $this->math->id, 'subject' => 'Mathematics', 'score' => 2, 'maximum_score' => 10, 'notes' => 'ملاحظة الرياضيات'],
        ]);
        $this->get($this->url('results', $this->physics))->assertOk()->assertSeeText('نتائج الفترة الأولى')
            ->assertSeeText('المادة: الفيزياء')->assertSeeText('ملاحظة الفيزياء')->assertSeeText('8 / 10')
            ->assertDontSeeText('ملاحظة الرياضيات')->assertDontSeeText('المجموع العام')->assertDontSeeText('Physics')
            ->assertViewHas('publications', fn ($items) => count($items->first()->result['subjects']) === 1)
            ->assertViewHas('summary', fn ($summary) => $summary['published_grades'] === 1 && $summary['average_percent'] === 80.0);
        $this->get('/parent/results')->assertOk()->assertSeeText('المادة: الرياضيات')->assertSeeText('المجموع العام')
            ->assertViewHas('recentGrades', fn ($items) => $items->isEmpty());
    }

    public function test_hidden_and_future_results_remain_hidden_in_results_and_subject_choices(): void
    {
        $this->grade($this->exam($this->physics, 'نتيجة غير معتمدة', ['legacy_results_published' => false]), 9);
        $this->grade($this->exam($this->physics, 'درجة مستقبلية'), 9, ['published_at' => now()->addDay()]);
        $subjects = [['subject_id' => $this->physics->id, 'subject' => 'Physics', 'score' => 8, 'maximum_score' => 10]];
        $this->publication('فترة مخفية', 'hidden', $subjects);
        $this->publication('فترة مستقبلية', 'published', $subjects, now()->addDay());

        $this->get($this->url('results', $this->physics))->assertOk()->assertDontSeeText('نتيجة غير معتمدة')
            ->assertDontSeeText('درجة مستقبلية')->assertDontSeeText('فترة مخفية')->assertDontSeeText('فترة مستقبلية')
            ->assertViewHas('subjects', fn ($items) => $items->isEmpty())
            ->assertViewHas('summary', fn ($summary) => $summary['published_grades'] === 0);
    }

    public function test_filters_cannot_access_an_unlinked_child_and_empty_filters_are_safe(): void
    {
        $other = $this->createStudentFixture('UNLINKED')['student'];
        foreach (['assignments', 'exams', 'results'] as $page) {
            $this->get('/parent/'.$page.'?student='.$other->id.'&subject='.$this->physics->id)->assertNotFound();
            $this->get($this->url($page, $this->physics))->assertOk();
            $this->get('/parent/'.$page.'?subject=999999')->assertOk();
            $this->get('/parent/'.$page.'?subject[]=1')->assertRedirect()->assertSessionHasErrors('subject');
        }
        $this->guardian->students()->detach();
        foreach (['assignments', 'exams', 'results'] as $page) {
            $this->get('/parent/'.$page)->assertOk()->assertSeeText('لا يوجد أبناء مرتبطون');
        }
    }

    public function test_switching_children_resets_the_subject_and_choices_follow_the_selected_child(): void
    {
        $second = $this->createStudentFixture('SECOND')['student'];
        $this->guardian->students()->attach($second);
        $this->assignment($this->physics, 'واجب الابن الأول');
        $this->assignment($this->math, 'واجب الابن الثاني', ['classroom_id' => $second->classroom_id]);

        $this->get('/parent/assignments?student='.$second->id)->assertOk()->assertSeeText('واجب الابن الثاني')
            ->assertDontSeeText('واجب الابن الأول')->assertViewHas('subjects', fn ($items) => $items->keys()->all() === [$this->math->id]);
    }

    public function test_old_results_are_still_filterable_beyond_the_previous_thirty_item_limit(): void
    {
        $exam = $this->exam($this->physics, 'نتيجة الفيزياء الأقدم');
        $this->grade($exam, 7, ['published_at' => now()->subDays(40)]);
        for ($i = 0; $i < 31; $i++) {
            $this->grade($this->exam($this->math, 'اختبار رياضيات '.$i), 8);
        }
        $this->get($this->url('results', $this->physics))->assertOk()->assertSeeText('نتيجة الفيزياء الأقدم')
            ->assertViewHas('recentGrades', fn ($items) => $items->count() === 1);
    }

    public function test_arabic_subject_names_and_class_suffixes_are_preserved(): void
    {
        $this->assertSame('اللغة العربية', SubjectDisplayName::arabic('Arabic'));
        $this->assertSame('اللغة الإنجليزية', SubjectDisplayName::arabic('English'));
        $this->assertSame('الحاسوب', SubjectDisplayName::arabic('Computer Science'));
        $this->assertSame('الرياضيات الصف 7', SubjectDisplayName::arabic('Math Grade 7'));
        $this->assertSame('رياضيات خاصة', SubjectDisplayName::arabic('رياضيات خاصة'));
        $this->assertSame('مادة غير محددة', SubjectDisplayName::arabic(null));
    }

    private function url(string $page, Subject $subject): string
    {
        return '/parent/'.$page.'?student='.$this->student->id.'&subject='.$subject->id;
    }

    private function assignment(Subject $subject, string $title, array $attributes = []): Assignment
    {
        return Assignment::create($attributes + ['title' => $title, 'classroom_id' => $this->student->classroom_id,
            'subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'status' => 'published', 'published_at' => now()]);
    }

    private function exam(Subject $subject, string $title, array $attributes = []): Exam
    {
        $exam = new Exam;
        $exam->forceFill($attributes + ['title' => $title, 'classroom_id' => $this->student->classroom_id,
            'subject_id' => $subject->id, 'teacher_id' => $this->teacher->id, 'status' => 'published',
            'legacy_results_published' => true, 'starts_at' => now()->subDay(), 'duration_minutes' => 30, 'total_score' => 10]);
        $exam->save();

        return $exam;
    }

    private function grade(Exam $exam, int $score, array $attributes = []): void
    {
        DB::table('grades')->insert($attributes + ['exam_id' => $exam->id, 'student_id' => $this->student->id, 'score' => $score, 'published_at' => now()]);
    }

    private function publication(string $name, string $status, array $subjects, $publishedAt = null): void
    {
        $period = DB::table('exam_periods')->insertGetId([
            'name' => $name, 'academic_year_id' => $this->student->classroom->academic_year_id,
            'term' => 'الأول', 'exam_type' => 'نهائي', 'classroom_id' => $this->student->classroom_id,
            'status' => $status, 'published_at' => $publishedAt ?? now(), 'created_by' => $this->teacher->user_id,
        ]);
        DB::table('result_publications')->insert(['exam_period_id' => $period, 'student_id' => $this->student->id,
            'payload' => json_encode(['subjects' => $subjects, 'total' => 10, 'maximum' => 20,
                'percentage' => 50, 'rating' => 'مقبول', 'passed' => false], JSON_UNESCAPED_UNICODE)]);
    }
}
