<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class SupervisorAttendanceFlowTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_grade_order_is_lowest_to_highest_then_track_and_section_on_roster_and_reports(): void
    {
        $context = $this->createStudentFixture('order');
        $supervisor = $this->supervisor([]);
        $definitions = [
            ['الثالث الثانوي علمي', 'ثانوي', 'F'], ['الصف الأول', 'أساسي', 'B'],
            ['الصف الثامن', 'أساسي', 'B'], ['الثاني ادبي', 'ثانوي', 'A'],
            ['الأول الثانوي', 'ثانوي', 'D'], ['الصف السابع', 'أساسي', 'B'],
            ['الصف الأول', 'أساسي', 'A'], ['الثالث الثانوي أدبي', 'ثانوي', 'G'],
            ['الصف التاسع', 'أساسي', 'A'], ['الثاني علمي', 'ثانوي', 'B'],
            ['الصف السابع', 'أساسي', 'A'], ['الأول الثانوي', 'ثانوي', 'C'],
            ['الثاني الثانوي علمي', 'ثانوي', 'A'], ['الصف الثامن', 'أساسي', 'A'],
            ['الثالث الثانوي علمي', 'ثانوي', 'B'],
        ];
        $classes = [];
        foreach ($definitions as [$name, $stage, $section]) {
            $classes[] = Classroom::create(['name' => $name, 'stage' => $stage, 'section' => $section, 'academic_year_id' => $context['classroom']->academic_year_id]);
        }
        $supervisor->supervisedClassrooms()->attach(array_column(array_map(fn ($c) => ['id' => $c->id], $classes), 'id'));
        $expected = array_map(fn ($index) => $classes[$index]->id, [6, 1, 10, 5, 13, 2, 8, 11, 4, 12, 9, 3, 14, 0, 7]);
        $this->actingAs($supervisor)->get(route('supervisor.attendance.index'))->assertOk()
            ->assertViewHas('classes', fn ($items) => $items->modelKeys() === $expected)
            ->assertViewHas('classId', $classes[6]->id);
        $this->get(route('supervisor.attendance.history'))->assertOk()
            ->assertViewHas('classes', fn ($items) => $items->modelKeys() === $expected);
    }

    public function test_numeric_arabic_and_secondary_grades_are_ordered_naturally(): void
    {
        $context = $this->createStudentFixture('numbers');
        $supervisor = $this->supervisor([]);
        $classes = collect([
            ['الصف ١٢', 'ثانوي'], ['Grade 2', 'Primary'], ['الصف ١٠', 'ثانوي'],
            ['الصف 9', 'أساسي'], ['الثاني عشر', 'ثانوي'], ['الصف ١', 'أساسي'],
            ['الحادي عشر', 'ثانوي'], ['1 ثانوي', 'ثانوي'],
        ])->map(fn ($row) => Classroom::create(['name' => $row[0], 'stage' => $row[1], 'section' => 'A', 'academic_year_id' => $context['classroom']->academic_year_id]));
        $supervisor->supervisedClassrooms()->attach($classes->pluck('id')->all());
        $this->actingAs($supervisor)->get(route('supervisor.attendance.index'))->assertOk()
            ->assertViewHas('classes', fn ($items) => $items->pluck('name')->all() === ['الصف ١', 'Grade 2', 'الصف 9', '1 ثانوي', 'الصف ١٠', 'الحادي عشر', 'الثاني عشر', 'الصف ١٢']);
    }

    public function test_saving_roster_makes_each_students_record_visible_only_to_linked_parent(): void
    {
        $this->travelTo('2026-10-16 10:00:00');
        $context = $this->createStudentFixture('shared');
        $second = $this->studentInClass($context['classroom'], 'Second child', 'S-SECOND');
        $other = $this->studentInClass($context['classroom'], 'Other family child', 'S-OTHER');
        $supervisor = $this->supervisor([$context['classroom']->id]);
        $parent = $this->parentAccount([$context['student']->id, $second->id]);
        $otherParent = $this->parentAccount([$other->id]);
        $this->actingAs($supervisor)->get(route('supervisor.attendance.index'))->assertOk();
        $this->assertDatabaseCount('attendance_records', 0);
        $payload = ['classroom_id' => $context['classroom']->id, 'date' => '2026-09-29', 'records' => [
            $context['student']->id => ['status' => 'present', 'notes' => 'First child attended'],
            $second->id => ['status' => 'absent', 'notes' => 'Second child absent'],
            $other->id => ['status' => 'present', 'notes' => 'Other family note'],
        ]];
        $this->post(route('supervisor.attendance.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($parent)->get(route('parent.attendance', ['student' => $context['student']->id]))
            ->assertOk()->assertSeeText('2026-09-29')->assertSeeText('First child attended')->assertSeeText('حاضر')
            ->assertDontSeeText('Second child absent')->assertDontSeeText('Other family note');
        $this->get(route('parent.attendance', ['student' => $second->id]))->assertOk()->assertSeeText('Second child absent')->assertSeeText('غائب');
        $this->get(route('parent.attendance', ['student' => $other->id]))->assertNotFound();
        $this->actingAs($otherParent)->get(route('parent.attendance'))->assertOk()->assertSeeText('Other family note')->assertDontSeeText('First child attended');

        $payload['records'] = [$second->id => ['status' => 'present', 'notes' => 'Absence corrected']];
        $this->actingAs($supervisor)->post(route('supervisor.attendance.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_records', 3);
        $this->actingAs($parent)->get(route('parent.attendance', ['student' => $second->id]))->assertOk()->assertSeeText('Absence corrected')
            ->assertViewHas('summary', fn ($summary) => $summary['present'] === 1 && $summary['absent'] === 0);
        $this->get(route('parent.children.show', $second))->assertOk()->assertSeeText('Absence corrected');
    }

    public function test_parent_date_filters_and_summary_use_the_same_records_and_preserve_boundaries(): void
    {
        $this->travelTo('2026-10-16 10:00:00');
        $context = $this->createStudentFixture('dates');
        $supervisor = $this->supervisor([$context['classroom']->id]);
        $parent = $this->parentAccount([$context['student']->id]);
        foreach (['2026-09-30' => 'absent', '2026-10-11' => 'present', '2026-10-16' => 'late'] as $day => $status) {
            Attendance::create(['student_id' => $context['student']->id, 'classroom_id' => $context['classroom']->id, 'date' => $day, 'status' => $status, 'recorded_by' => $supervisor->id]);
        }
        $this->actingAs($parent)->get(route('parent.attendance'))->assertOk()->assertViewHas('period', 'all')
            ->assertViewHas('summary', fn ($s) => $s['attendance_total'] === 3 && $s['attendance_percent'] === 66.7);
        $this->get(route('parent.attendance', ['period' => 'week']))->assertOk()->assertDontSeeText('2026-09-30')
            ->assertViewHas('summary', fn ($s) => $s['attendance_total'] === 2 && $s['attendance_percent'] === 100.0);
        $this->get(route('parent.attendance', ['period' => 'month']))->assertOk()->assertDontSeeText('2026-09-30')
            ->assertViewHas('summary', fn ($s) => $s['attendance_total'] === 2);
        $this->get(route('parent.attendance', ['period' => 'custom', 'from' => '2026-10-16', 'to' => '2026-09-30']))->assertOk()
            ->assertViewHas('dateFrom', '2026-09-30')->assertViewHas('dateTo', '2026-10-16')
            ->assertViewHas('summary', fn ($s) => $s['attendance_total'] === 3);
        $this->get(route('parent.attendance', ['period' => 'custom', 'from' => '2026-10-16', 'to' => '2026-10-16']))->assertOk()
            ->assertViewHas('summary', fn ($s) => $s['attendance_total'] === 1 && $s['late'] === 1);
        $this->get(route('parent.attendance', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    }

    public function test_parent_can_page_through_all_history_with_unchanged_totals(): void
    {
        $context = $this->createStudentFixture('pages');
        $supervisor = $this->supervisor([$context['classroom']->id]);
        $parent = $this->parentAccount([$context['student']->id]);
        for ($index = 0; $index < 32; $index++) {
            Attendance::create(['student_id' => $context['student']->id, 'classroom_id' => $context['classroom']->id,
                'date' => today()->subDays($index), 'status' => 'present', 'recorded_by' => $supervisor->id]);
        }
        $this->actingAs($parent)->get(route('parent.attendance'))->assertOk()->assertViewHas('records', fn ($items) => $items->count() === 30)
            ->assertViewHas('summary', fn ($s) => $s['attendance_total'] === 32);
        $this->get(route('parent.attendance', ['page' => 2]))->assertOk()->assertViewHas('records', fn ($items) => $items->count() === 2)
            ->assertViewHas('summary', fn ($s) => $s['attendance_total'] === 32);
    }

    public function test_existing_excused_and_late_details_survive_a_roster_save(): void
    {
        $context = $this->createStudentFixture('legacy');
        $supervisor = $this->supervisor([$context['classroom']->id]);
        $record = Attendance::create(['student_id' => $context['student']->id, 'classroom_id' => $context['classroom']->id,
            'date' => '2026-10-16', 'status' => 'excused_late', 'arrival_time' => '08:15', 'late_minutes' => 15,
            'excuse_reason' => 'Transport delay', 'notes' => 'Parent informed', 'recorded_by' => $supervisor->id]);
        $this->actingAs($supervisor)->get(route('supervisor.attendance.index', ['date' => '2026-10-16']))->assertOk()
            ->assertSee('value="excused_late" data-status-value', false)->assertSee('Transport delay');
        $this->post(route('supervisor.attendance.store'), ['date' => '2026-10-16', 'classroom_id' => $context['classroom']->id,
            'records' => [$context['student']->id => ['status' => 'excused_late', 'arrival_time' => '08:15', 'late_minutes' => 15,
                'excuse_reason' => 'Transport delay', 'notes' => 'Parent informed']]])->assertSessionHasNoErrors();
        $this->assertSame('excused_late', $record->fresh()->status->value);
        $this->assertSame('Transport delay', $record->fresh()->excuse_reason);
        $parent = $this->parentAccount([$context['student']->id]);
        $this->actingAs($parent)->get(route('parent.attendance'))->assertOk()->assertSeeText('Transport delay')->assertSeeText('Parent informed')->assertSeeText('تأخير بعذر');
    }

    public function test_qr_scan_and_roster_writes_enforce_supervision_and_parent_read_only_access(): void
    {
        $context = $this->createStudentFixture('scan');
        $supervisor = $this->supervisor([$context['classroom']->id]);
        $parent = $this->parentAccount([$context['student']->id]);
        $payload = ['date' => '2026-10-16', 'classroom_id' => $context['classroom']->id, 'student_id' => $context['student']->id];
        $this->actingAs($supervisor)->postJson(route('supervisor.attendance.scan'), $payload)->assertOk()->assertJsonPath('ok', true);
        $this->actingAs($parent)->get(route('parent.attendance'))->assertOk()->assertSeeText('2026-10-16')->assertSeeText('تم التسجيل عبر QR');
        $this->postJson(route('supervisor.attendance.scan'), $payload)->assertForbidden();
        $this->actingAs($this->supervisor([]))->postJson(route('supervisor.attendance.scan'), $payload)->assertForbidden();
        $foreign = $this->createStudentFixture('outside');
        $this->actingAs($supervisor)->post(route('supervisor.attendance.store'), ['date' => '2026-10-16', 'classroom_id' => $context['classroom']->id,
            'records' => [$foreign['student']->id => ['status' => 'absent']]])->assertForbidden();
        $this->get(route('supervisor.attendance.index', ['classroom_id' => $foreign['classroom']->id]))->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_invalid_roster_date_falls_back_to_today_and_report_link_keeps_class_and_date(): void
    {
        $context = $this->createStudentFixture('date');
        $this->actingAs($this->supervisor([$context['classroom']->id]))->get(route('supervisor.attendance.index', ['date' => '2026-02-31']))
            ->assertOk()->assertViewHas('date', today()->toDateString());
        $this->get(route('supervisor.attendance.index', ['date' => '2026-09-29']))->assertOk()
            ->assertSee(route('supervisor.attendance.history', ['period' => 'daily', 'date' => '2026-09-29', 'classroom_id' => $context['classroom']->id]));
    }

    private function supervisor(array $classIds): User
    {
        $user = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $user->supervisedClassrooms()->attach($classIds);
        return $user;
    }

    private function studentInClass(Classroom $classroom, string $name, string $number): Student
    {
        return Student::create(['user_id' => User::factory()->create(['name' => $name, 'role' => 'student', 'status' => 'active'])->id,
            'student_number' => $number, 'classroom_id' => $classroom->id, 'status' => 'active']);
    }

    private function parentAccount(array $studentIds): User
    {
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'relationship' => 'father', 'status' => 'active']);
        $guardian->students()->attach($studentIds);
        return $parent;
    }
}
