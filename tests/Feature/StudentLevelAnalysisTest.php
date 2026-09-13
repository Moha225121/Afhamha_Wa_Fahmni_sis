<?php

namespace Tests\Feature;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Exceptions\SmartTutorGatewayException;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use App\Services\StudentLevelAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentLevelAnalysisTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    private function parentFor($student): User
    {
        $user = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $user->id, 'relationship' => 'father', 'status' => 'active']);
        $guardian->students()->attach($student);

        return $user;
    }

    private function publish(array $fixture, string $status = 'published', string $suffix = 'A'): int
    {
        $subject = $this->createSubjectFor($fixture['classroom'], $suffix);
        $period = DB::table('exam_periods')->insertGetId([
            'name' => 'فترة '.$suffix, 'academic_year_id' => $fixture['classroom']->academic_year_id,
            'term' => 'first', 'exam_type' => 'monthly', 'status' => $status,
            'created_by' => $this->createAdmin()->id, 'published_at' => now()->subDay(),
        ]);
        DB::table('result_publications')->insert([
            'exam_period_id' => $period, 'student_id' => $fixture['student']->id,
            'payload' => json_encode(['subjects' => [[
                'subject_id' => $subject->id, 'subject' => $subject->name,
                'score' => 15, 'maximum_score' => 20, 'notes' => 'private note',
            ]]]),
        ]);

        return $subject->id;
    }

    private function gateway()
    {
        $gateway = new class implements SmartTutorGateway
        {
            public array $prompts = [];

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->prompts[] = $prompt;

                return new SmartTutorReply('خطة أسبوعية: مراجعة قصيرة يوميًا. <script>alert(1)</script>');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        return $gateway;
    }

    public function test_parent_generates_safe_report_and_cached_report_is_invalidated_when_results_are_hidden(): void
    {
        $fixture = $this->createStudentFixture('A');
        $this->publish($fixture);
        $this->publish($fixture, 'hidden', 'HIDDEN');
        $parent = $this->parentFor($fixture['student']);
        $gateway = $this->gateway();
        $url = route('parent.students.analysis', $fixture['student']);
        $this->actingAs($parent)->get($url)->assertOk()->assertSee('75%');
        $this->assertCount(0, $gateway->prompts);
        $this->post($url)->assertOk()->assertSeeText('خطة أسبوعية')
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->post($url)->assertOk();
        $this->assertCount(1, $gateway->prompts);
        $payload = $gateway->prompts[0]->turns[1]->content;
        $this->assertStringNotContainsString($fixture['user']->name, $payload);
        $this->assertStringNotContainsString('student_id', $payload);
        $this->assertStringNotContainsString('private note', $payload);
        $this->assertStringNotContainsString('HIDDEN', $payload);
        $this->assertStringContainsString('ولي الأمر', $gateway->prompts[0]->turns[0]->content);
        DB::table('exam_periods')->update(['status' => 'hidden']);
        $this->get($url)->assertOk()->assertSeeText('لا توجد بيانات كافية')->assertDontSeeText('خطة أسبوعية: مراجعة');
    }

    public function test_parent_cannot_read_or_generate_another_childs_report(): void
    {
        $own = $this->createStudentFixture('A');
        $other = $this->createStudentFixture('B');
        $gateway = $this->gateway();
        $this->actingAs($this->parentFor($own['student']))
            ->get(route('parent.students.analysis', $other['student']))->assertNotFound();
        $this->post(route('parent.students.analysis.generate', $other['student']))->assertNotFound();
        $this->assertCount(0, $gateway->prompts);
    }

    public function test_no_data_does_not_call_ai_and_student_cannot_access_admin_report(): void
    {
        $fixture = $this->createStudentFixture('A');
        $gateway = $this->gateway();
        $this->actingAs($this->createAdmin())->post(route('admin.students.analysis.generate', $fixture['student']))
            ->assertOk()->assertSeeText('لا توجد بيانات كافية');
        $this->assertCount(0, $gateway->prompts);
        $this->actingAs($fixture['user'])->get(route('admin.students.analysis', $fixture['student']))->assertForbidden();
    }

    public function test_teacher_only_analyzes_assigned_subjects_and_students(): void
    {
        $fixture = $this->createStudentFixture('A');
        $subjectId = $this->publish($fixture);
        $this->publish($fixture, 'published', 'OTHER');
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $fixture['classroom']->id, 'subject_id' => $subjectId]);
        $gateway = $this->gateway();
        $this->actingAs($user)->post(route('teacher.students.analysis.generate', $fixture['student']))->assertOk();
        $snapshot = json_decode($gateway->prompts[0]->turns[1]->content, true);
        $this->assertSame(1, $snapshot['results_count']);
        $other = $this->createStudentFixture('B');
        $this->get(route('teacher.students.analysis', $other['student']))->assertNotFound();
        $this->post(route('teacher.students.analysis.generate', $other['student']))->assertNotFound();
    }

    public function test_attendance_counts_lateness_as_attendance_and_missing_days_are_not_absence(): void
    {
        $fixture = $this->createStudentFixture('A');
        foreach (['present', 'late', 'excused_late', 'absent', 'excused_absence'] as $index => $status) {
            DB::table('attendance_records')->insert([
                'student_id' => $fixture['student']->id, 'classroom_id' => $fixture['classroom']->id,
                'date' => now()->subDays($index)->toDateString(), 'status' => $status, 'recorded_by' => $this->createAdmin()->id,
            ]);
        }
        $snapshot = app(StudentLevelAnalysisService::class)->snapshot($fixture['student'], null, 'parent');
        $this->assertSame(5, $snapshot['attendance_total']);
        $this->assertEquals(60, $snapshot['attendance_percent']);
        $this->assertNull($snapshot['average_percent']);
    }

    public function test_provider_failure_shows_retry_without_leaking_details(): void
    {
        $fixture = $this->createStudentFixture('A');
        $this->publish($fixture);
        $this->app->instance(SmartTutorGateway::class, new class implements SmartTutorGateway
        {
            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                throw SmartTutorGatewayException::timeout();
            }
        });
        $this->actingAs($this->parentFor($fixture['student']))->post(route('parent.students.analysis.generate', $fixture['student']))
            ->assertOk()->assertSeeText('تعذر إنشاء التحليل الآن')->assertSeeText('إنشاء التحليل وخطة الدعم');
    }

    public function test_private_teacher_sheets_are_not_in_parent_snapshot_or_shared_reports(): void
    {
        $fixture = $this->createStudentFixture('A');
        $this->publish($fixture);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        DB::table('grade_sheets')->insert([
            'teacher_id' => $teacher->id, 'classroom_id' => $fixture['classroom']->id,
            'scores' => json_encode([$fixture['student']->id => 33]), 'updated_at' => now(),
        ]);
        $service = app(StudentLevelAnalysisService::class);
        $this->assertSame([], $service->snapshot($fixture['student'], null, 'parent')['teacher_sheet_summaries']);
        $this->assertCount(1, $service->snapshot($fixture['student'], null, 'admin')['teacher_sheet_summaries']);
        $gateway = $this->gateway();
        $this->actingAs($this->createAdmin())->post(route('admin.students.analysis.generate', $fixture['student']))->assertOk();
        $parent = $this->parentFor($fixture['student']);
        $this->actingAs($parent)->get(route('parent.students.analysis', $fixture['student']))->assertOk()->assertDontSeeText('خطة أسبوعية: مراجعة');
        $this->post(route('parent.students.analysis.generate', $fixture['student']))->assertOk();
        $this->assertCount(2, $gateway->prompts);
    }
}
