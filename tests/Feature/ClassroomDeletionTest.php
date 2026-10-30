<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClassroomDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_class_can_be_deleted_and_restored_when_schedule_table_is_missing(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create([
            'name' => 'Grade 6',
            'section' => 'A',
            'stage' => 'Basic',
            'academic_year_id' => $year->id,
        ]);
        $subject = Subject::create(['name' => 'Science', 'code' => 'SCI-UNDO', 'stage' => 'Basic', 'status' => 'active']);
        $classroom->subjects()->attach($subject);
        Schema::dropIfExists('schedules');

        $this->actingAs($admin)
            ->delete(route('admin.classes.destroy', $classroom))
            ->assertRedirect(route('admin.classes.index'))
            ->assertSessionHas('undo_class_id', $classroom->id);

        $this->assertSoftDeleted('classrooms', ['id' => $classroom->id]);
        $this->assertDatabaseHas('classroom_subject', ['classroom_id' => $classroom->id, 'subject_id' => $subject->id]);
        $this->get(route('admin.classes.index'))
            ->assertOk()
            ->assertSeeText('تنبيه قبل الحذف')
            ->assertSeeText('الصفوف المحذوفة مؤخرًا')
            ->assertSee(route('admin.classes.restore', $classroom->id), false);

        $this->patch(route('admin.classes.restore', $classroom->id))->assertRedirect(route('admin.classes.index'));
        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('classroom_subject', ['classroom_id' => $classroom->id, 'subject_id' => $subject->id]);
    }

    public function test_classroom_soft_delete_repair_migration_restores_missing_column_idempotently(): void
    {
        Schema::table('classrooms', fn ($table) => $table->dropSoftDeletes());

        $migration = require database_path('migrations/2026_10_12_000001_ensure_classroom_soft_deletes.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('classrooms', 'deleted_at'));
    }

    public function test_class_with_students_still_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $classroom = Classroom::create([
            'name' => 'Grade 5',
            'section' => 'A',
            'stage' => 'Basic',
            'academic_year_id' => $year->id,
        ]);
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create([
            'user_id' => $studentUser->id,
            'student_number' => 'S-CLASS-UNDO',
            'classroom_id' => $classroom->id,
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.classes.destroy', $classroom))
            ->assertUnprocessable();

        $this->assertDatabaseHas('classrooms', ['id' => $classroom->id, 'deleted_at' => null]);
    }
}
