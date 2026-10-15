<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_class_section_and_assignments_show_the_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);
        $classroom = Classroom::create([
            'name' => 'Grade 1',
            'stage' => 'Primary',
            'section' => 'A',
            'academic_year_id' => $year->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.classes.edit', $classroom))
            ->assertOk()
            ->assertSee('name="section"', false)
            ->assertSee('value="A"', false);

        $this->put(route('admin.classes.update', $classroom), [
            'name' => 'Grade 1',
            'stage' => 'Primary',
            'section' => 'B',
            'academic_year_id' => $year->id,
            'subject_ids' => [],
        ])->assertRedirect(route('admin.classes.show', $classroom));

        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id, 'section' => 'B']);

        $this->get(route('admin.teachers.create'))
            ->assertOk()
            ->assertSeeText('الشعبة B');
    }
}