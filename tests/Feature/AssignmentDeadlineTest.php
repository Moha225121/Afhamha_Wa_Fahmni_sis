<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AdminPublicationService;
use App\Support\AssignmentDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssignmentDeadlineTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\SubmitsTeacherCreationForms;

    public function test_assignment_without_either_date_has_no_deadline(): void
    {
        $this->assertNull(AssignmentDeadline::for(new Assignment));
    }

    public function test_legacy_date_only_homework_remains_open_through_the_chosen_school_day(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $assignment = new Assignment(['due_date' => '2026-10-25', 'due_at' => '2026-10-25 00:00:00']);
        $before = $assignment->getAttributes();

        $deadline = AssignmentDeadline::for($assignment);

        $this->assertInstanceOf(CarbonImmutable::class, $deadline);
        $this->assertSame('2026-10-25 21:59:59.999999 +00:00', $deadline->format('Y-m-d H:i:s.u P'));
        $this->assertSame('2026-10-25 23:59:59.999999 +02:00', $deadline->setTimezone('Africa/Tripoli')->format('Y-m-d H:i:s.u P'));
        $this->assertTrue(CarbonImmutable::parse('2026-10-25 12:00:00', 'Africa/Tripoli')->lt($deadline));
        $this->assertTrue(CarbonImmutable::parse('2026-10-25 23:59:59', 'Africa/Tripoli')->lt($deadline));
        $this->assertTrue(CarbonImmutable::parse('2026-10-26 00:00:00', 'Africa/Tripoli')->gt($deadline));
        $this->assertSame($before, $assignment->getAttributes());
    }

    public function test_date_only_homework_with_null_due_at_uses_its_due_date(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $assignment = new Assignment(['due_date' => '2026-10-25', 'due_at' => null]);

        $this->assertSame('2026-10-25 21:59:59.999999', AssignmentDeadline::for($assignment)->format('Y-m-d H:i:s.u'));
    }

    public function test_an_explicit_non_midnight_time_is_preserved_even_with_a_due_date(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $assignment = new Assignment(['due_date' => '2026-10-25', 'due_at' => '2026-10-25 09:30:00']);

        $deadline = AssignmentDeadline::for($assignment);

        $this->assertSame('2026-10-25 09:30:00.000000 +00:00', $deadline->format('Y-m-d H:i:s.u P'));
        $this->assertSame('2026-10-25 11:30:00', $deadline->setTimezone('Africa/Tripoli')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 09:30:00', $assignment->due_at->format('Y-m-d H:i:s'));
    }

    public function test_midnight_without_the_matching_legacy_date_marker_is_an_exact_deadline(): void
    {
        $withoutDate = new Assignment(['due_at' => '2026-10-25 00:00:00']);
        $differentDate = new Assignment(['due_date' => '2026-10-26', 'due_at' => '2026-10-25 00:00:00']);

        foreach ([$withoutDate, $differentDate] as $assignment) {
            $this->assertSame('2026-10-25 00:00:00.000000 +00:00', AssignmentDeadline::for($assignment)->format('Y-m-d H:i:s.u P'));
        }
    }

    public function test_the_school_timezone_controls_date_only_deadlines_instead_of_server_utc(): void
    {
        $this->schoolTimezone('Asia/Kathmandu');

        $deadline = AssignmentDeadline::fromDate('2026-10-25');

        $this->assertSame('2026-10-25 18:14:59.999999 +00:00', $deadline->format('Y-m-d H:i:s.u P'));
        $this->assertSame('2026-10-25 23:59:59', $deadline->setTimezone('Asia/Kathmandu')->format('Y-m-d H:i:s'));
    }

    public function test_daylight_saving_boundaries_use_the_end_of_the_local_day(): void
    {
        $this->schoolTimezone('Europe/London');

        $deadline = AssignmentDeadline::fromDate('2026-10-25');

        $this->assertSame('2026-10-25 23:59:59.999999 +00:00', $deadline->format('Y-m-d H:i:s.u P'));
        $this->assertSame('2026-10-25 23:59:59.999999', $deadline->setTimezone('Europe/London')->format('Y-m-d H:i:s.u'));
    }

    public function test_missing_or_invalid_school_timezone_falls_back_to_tripoli(): void
    {
        DB::table('settings')->where('key', 'timezone')->delete();
        $this->assertSame('2026-10-25 21:59:59', AssignmentDeadline::fromDate('2026-10-25')->format('Y-m-d H:i:s'));

        $this->schoolTimezone('invalid/timezone');
        $this->assertSame('2026-10-25 21:59:59', AssignmentDeadline::fromDate('2026-10-25')->format('Y-m-d H:i:s'));
    }

    public function test_teacher_creates_a_date_only_homework_with_a_school_day_end_deadline(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        [$teacher, $classroom, $subject] = $this->teacherFixture();

        $this->actingAs($teacher)->postTeacherCreation(route('teacher.assignments.store'), $this->payload($classroom, $subject, '2026-11-12'))
            ->assertRedirect(route('teacher.assignments.index'))
            ->assertSessionHasNoErrors();

        $assignment = Assignment::query()->sole();
        $this->assertSame('2026-11-12', $assignment->due_date->toDateString());
        $this->assertSame('2026-11-12 21:59:59', $assignment->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-12 23:59:59', AssignmentDeadline::for($assignment)->setTimezone('Africa/Tripoli')->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('assignment_classroom', ['assignment_id' => $assignment->id, 'classroom_id' => $classroom->id]);
    }

    public function test_teacher_updating_the_date_also_updates_the_actual_deadline(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        [$teacher, $classroom, $subject] = $this->teacherFixture();
        $assignment = Assignment::create([
            'teacher_id' => $teacher->teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'title' => 'واجب قديم',
            'status' => 'active',
            'published_at' => now(),
            'due_date' => '2026-11-10',
            'due_at' => '2026-11-10 00:00:00',
        ]);

        $this->actingAs($teacher)->put(route('teacher.assignments.update', $assignment), $this->payload($classroom, $subject, '2026-11-13'))
            ->assertRedirect(route('teacher.assignments.index'))
            ->assertSessionHasNoErrors();

        $assignment->refresh();
        $this->assertSame('2026-11-13', $assignment->due_date->toDateString());
        $this->assertSame('2026-11-13 21:59:59', $assignment->due_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('assignments', 1);
        $this->assertDatabaseHas('assignment_classroom', ['assignment_id' => $assignment->id, 'classroom_id' => $classroom->id]);
    }

    public function test_teacher_filters_and_summary_keep_todays_date_only_homework_active_and_preserve_exact_times(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Africa/Tripoli'));
        [$teacher, $classroom, $subject] = $this->teacherFixture();
        $today = $this->homework($teacher, $classroom, $subject, 'واجب اليوم القديم', '2026-10-25', '2026-10-25 00:00:00');
        $newToday = $this->homework($teacher, $classroom, $subject, 'واجب اليوم المحدّث', '2026-10-25', '2026-10-25 21:59:59');
        $futureTime = $this->homework($teacher, $classroom, $subject, 'وقت دقيق لاحق', '2026-10-25', '2026-10-25 11:00:00');
        $expiredTime = $this->homework($teacher, $classroom, $subject, 'وقت دقيق منتهٍ', '2026-10-25', '2026-10-25 09:00:00');
        $yesterday = $this->homework($teacher, $classroom, $subject, 'واجب الأمس', '2026-10-24', '2026-10-24 00:00:00');
        $openIds = [$today->id, $newToday->id, $futureTime->id];
        $closedIds = [$expiredTime->id, $yesterday->id];

        $active = $this->actingAs($teacher)->get(route('teacher.assignments.index', ['status' => 'active']))->assertOk();
        $this->assertEqualsCanonicalizing($openIds, $active->viewData('rows')->pluck('id')->all());
        $this->assertTrue($active->viewData('rows')->every(fn ($row) => $row->is_closed === false));
        $this->assertSame(3, $active->viewData('assignmentSummary')['active']);
        $this->assertSame(2, $active->viewData('assignmentSummary')['closed']);

        $closed = $this->get(route('teacher.assignments.index', ['status' => 'closed']))->assertOk();
        $this->assertEqualsCanonicalizing($closedIds, $closed->viewData('rows')->pluck('id')->all());
        $this->assertTrue($closed->viewData('rows')->every(fn ($row) => $row->is_closed === true));
    }

    public function test_teacher_filters_change_at_school_midnight_even_before_utc_midnight(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 22:30:00', 'UTC'));
        [$teacher, $classroom, $subject] = $this->teacherFixture();
        $yesterday = $this->homework($teacher, $classroom, $subject, 'يوم انتهى محليًا', '2026-10-25', '2026-10-25 00:00:00');
        $today = $this->homework($teacher, $classroom, $subject, 'واجب اليوم المحلي الجديد', '2026-10-26', null);

        $active = $this->actingAs($teacher)->get(route('teacher.assignments.index', ['status' => 'active']))->assertOk();
        $this->assertSame([$today->id], $active->viewData('rows')->pluck('id')->all());
        $closed = $this->get(route('teacher.assignments.index', ['status' => 'closed']))->assertOk();
        $this->assertSame([$yesterday->id], $closed->viewData('rows')->pluck('id')->all());
    }

    public function test_teacher_cannot_cancel_todays_legacy_homework_until_its_actual_deadline(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Africa/Tripoli'));
        [$teacher, $classroom, $subject] = $this->teacherFixture();
        $assignment = $this->homework($teacher, $classroom, $subject, 'موعد الإلغاء', '2026-10-25', '2026-10-25 00:00:00');

        $this->actingAs($teacher)->patch(route('teacher.assignments.cancel', $assignment))->assertUnprocessable();
        $this->assertSame('active', $assignment->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-10-26 00:00:00', 'Africa/Tripoli'));
        $this->from(route('teacher.assignments.index'))->patch(route('teacher.assignments.cancel', $assignment))
            ->assertRedirect(route('teacher.assignments.index'));
        $this->assertSame('cancelled', $assignment->fresh()->status);
    }

    public function test_teacher_can_cancel_an_explicit_deadline_after_the_exact_time_has_passed(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Africa/Tripoli'));
        [$teacher, $classroom, $subject] = $this->teacherFixture();
        $assignment = $this->homework($teacher, $classroom, $subject, 'موعد محدد انتهى', '2026-10-25', '2026-10-25 09:00:00');

        $this->actingAs($teacher)->from(route('teacher.assignments.index'))->patch(route('teacher.assignments.cancel', $assignment))
            ->assertRedirect(route('teacher.assignments.index'));

        $this->assertSame('cancelled', $assignment->fresh()->status);
    }

    public function test_admin_publication_details_show_the_same_effective_homework_deadline(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        [$teacher, $classroom, $subject] = $this->teacherFixture();
        $assignment = $this->homework($teacher, $classroom, $subject, 'نفس الموعد للإدارة', '2026-10-25', '2026-10-25 00:00:00');
        $post = app(AdminPublicationService::class)->detail('assignment', $assignment->id);
        $metadata = collect($post['metadata'])->pluck('value', 'label');

        $this->assertSame('2026/10/25 23:59', $metadata['آخر موعد للتسليم']);
    }

    public function test_editing_an_existing_student_portal_homework_keeps_its_exact_deadline_and_private_attachment(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        [$teacher, $classroom, $subject] = $this->teacherFixture();
        Storage::fake('local');
        Storage::disk('local')->put('assignments/legacy-qa.pdf', '%PDF-1.4 old QA attachment');
        $assignment = Assignment::create([
            'teacher_id' => $teacher->teacher->id, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id,
            'title' => 'QA Exact Deadline', 'instructions' => '<p>تعليمات الطالب المحفوظة</p>',
            'status' => 'published', 'due_at' => '2026-11-12 09:30:00', 'attachment_path' => 'assignments/legacy-qa.pdf',
        ]);
        $this->actingAs($teacher)->get(route('teacher.assignments.edit', $assignment))->assertOk()
            ->assertSee('تعليمات الطالب المحفوظة')->assertSee('value="2026-11-12"', false);
        $this->get(route('teacher.assignments.index', ['due_date' => '2026-11-12']))->assertOk()->assertSee('2026-11-12 11:30');
        $this->get(route('teacher.assignments.index', ['due_date' => '2026-11-13']))->assertOk()->assertDontSee('QA Exact Deadline');
        $payload = $this->payload($classroom, $subject, '2026-11-12');
        $payload['attachments'] = [UploadedFile::fake()->createWithContent('new-qa.pdf', '%PDF-1.4 new QA attachment')];
        $this->put(route('teacher.assignments.update', $assignment), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-11-12 09:30:00', $assignment->fresh()->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('assignments/legacy-qa.pdf', $assignment->fresh()->attachment_path);
        Storage::disk('local')->assertExists('assignments/legacy-qa.pdf');
        $this->assertGreaterThan(0, $assignment->attachments()->count());
    }

    public function test_teacher_dashboard_includes_current_exact_and_date_only_homework_but_not_expired_work(): void
    {
        $this->schoolTimezone('Africa/Tripoli');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'Africa/Tripoli'));
        [$teacher, $room, $subject] = $this->teacherFixture();
        $this->homework($teacher, $room, $subject, 'QA Today Legacy', '2026-10-25', '2026-10-25 00:00:00');
        $exact = Assignment::create(['teacher_id' => $teacher->teacher->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id, 'title' => 'QA Exact Upcoming', 'status' => 'published', 'due_at' => '2026-10-25 12:00:00']);
        $this->homework($teacher, $room, $subject, 'QA Expired', '2026-10-24', '2026-10-24 12:00:00');
        $this->actingAs($teacher)->get(route('teacher.dashboard'))->assertOk()
            ->assertViewHas('upcomingAssignments', fn ($items) => $items->pluck('title')->sort()->values()->all() === ['QA Exact Upcoming', 'QA Today Legacy']);
    }

    private function schoolTimezone(string $timezone): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'timezone'], ['value' => $timezone]);
    }

    /** @return array{User, Classroom, Subject} */
    private function teacherFixture(): array
    {
        $year = AcademicYear::create(['name' => '2026/2027-DEADLINE', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create(['name' => 'الصف الرابع', 'stage' => 'أساسي', 'section' => 'A', 'academic_year_id' => $year->id]);
        $subject = Subject::create(['name' => 'لغة عربية', 'code' => 'DEADLINE-AR', 'stage' => 'أساسي', 'status' => 'active']);
        $classroom->subjects()->attach($subject);
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id]);

        return [$user, $classroom, $subject];
    }

    private function payload(Classroom $classroom, Subject $subject, string $date): array
    {
        return [
            'title' => 'واجب بنهاية يوم التسليم',
            'classroom_ids' => [$classroom->id],
            'subject_id' => $subject->id,
            'due_date' => $date,
            'max_score' => 10,
            'description' => 'تعليمات الواجب',
        ];
    }

    private function homework(User $teacher, Classroom $classroom, Subject $subject, string $title, string $date, ?string $dueAt): Assignment
    {
        return Assignment::create([
            'teacher_id' => $teacher->teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'title' => $title,
            'status' => 'active',
            'published_at' => now()->subDay(),
            'due_date' => $date,
            'due_at' => $dueAt,
        ]);
    }
}
