<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomDuplicateValidationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $year = AcademicYear::create(['name' => 'QA 2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        return ['name' => 'QA صف', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id];
    }

    public function test_duplicate_create_returns_validation_error_without_changing_the_existing_class_or_subjects(): void
    {
        $data = $this->payload();
        $class = Classroom::create($data);
        $subject = Subject::create(['name' => 'QA مادة', 'code' => 'QA-DUP', 'stage' => 'أساسي', 'status' => 'active']);
        $class->subjects()->attach($subject);
        $this->postJson(route('admin.classes.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('classrooms', 1);
        $this->assertSame([$subject->id], $class->fresh()->subjects()->pluck('subjects.id')->all());
    }

    public function test_conflicting_update_is_rejected_but_saving_the_same_class_is_allowed(): void
    {
        $data = $this->payload();
        $first = Classroom::create($data);
        $second = Classroom::create(array_replace($data, ['section' => 'ب']));
        $this->putJson(route('admin.classes.update', $second), $data)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('ب', $second->fresh()->section);
        $this->put(route('admin.classes.update', $first), $data)->assertSessionHasNoErrors()->assertRedirect(route('admin.classes.show', $first));
        $this->assertDatabaseCount('classrooms', 2);
    }

    public function test_same_name_in_another_section_or_year_is_legitimate_and_malformed_scope_is_rejected(): void
    {
        $data = $this->payload();
        Classroom::create($data);
        $otherYear = AcademicYear::create(['name' => 'QA 2027/2028', 'starts_at' => '2027-09-01', 'ends_at' => '2028-06-30', 'is_current' => false]);
        $this->post(route('admin.classes.store'), array_replace($data, ['section' => 'ب']))->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.classes.store'), array_replace($data, ['academic_year_id' => $otherYear->id]))->assertSessionHasNoErrors()->assertRedirect();
        $this->postJson(route('admin.classes.store'), array_replace($data, ['section' => ['أ']]))->assertUnprocessable()->assertJsonValidationErrors('section');
        $this->assertDatabaseCount('classrooms', 3);
    }

    public function test_malformed_foreign_ids_are_rejected_before_database_integer_comparisons(): void
    {
        $data = $this->payload();
        foreach ([
            [['academic_year_id' => 'not-a-year'], 'academic_year_id'],
            [['academic_year_id' => ['invalid']], 'academic_year_id'],
            [['subject_ids' => ['not-a-subject']], 'subject_ids.0'],
            [['student_ids' => ['not-a-student']], 'student_ids.0'],
        ] as [$invalid, $field]) {
            $this->postJson(route('admin.classes.store'), array_replace($data, $invalid))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('classrooms', 0);
    }

    public function test_optional_null_section_keeps_existing_database_uniqueness_semantics(): void
    {
        $data = array_replace($this->payload(), ['section' => null]);
        $this->post(route('admin.classes.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('admin.classes.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('classrooms', 2);
        $this->assertSame(2, Classroom::whereNull('section')->count());
    }
}
