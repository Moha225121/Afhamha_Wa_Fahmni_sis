<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GradeSheetTeacherMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require database_path('migrations/2026_10_11_000001_repoint_grade_sheets_to_teachers.php');
        $this->migration->down();
        User::factory()->create(['id' => 2, 'role' => 'student']);
        User::factory()->create(['id' => 7, 'role' => 'teacher']);
        DB::table('teachers')->insert(['id' => 2, 'user_id' => 7]);
        $year = AcademicYear::create(['name' => '2026', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-01', 'is_current' => true]);
        $this->classroom = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
    }

    private function insertSheet(int $reference): int
    {
        return DB::table('grade_sheets')->insertGetId([
            'teacher_id' => $reference,
            'classroom_id' => $this->classroom->id,
            'sheet_data' => json_encode(['saved' => 79]),
        ]);
    }

    public function test_existing_teacher_id_is_preserved_despite_users_foreign_key(): void
    {
        $id = $this->insertSheet(2);
        $this->migration->up();
        $this->assertDatabaseHas('grade_sheets', ['id' => $id, 'teacher_id' => 2]);
        $this->assertSame(['saved' => 79], json_decode(DB::table('grade_sheets')->where('id', $id)->value('sheet_data'), true));
        $foreignKey = collect(Schema::getForeignKeys('grade_sheets'))->firstWhere('columns', ['teacher_id']);
        $this->assertSame('teachers', $foreignKey['foreign_table']);
    }

    public function test_user_reference_is_converted_to_its_teacher_id(): void
    {
        $id = $this->insertSheet(7);
        $this->migration->up();
        $this->assertDatabaseHas('grade_sheets', ['id' => $id, 'teacher_id' => 2]);
    }

    public function test_ambiguous_reference_is_rejected_before_data_or_schema_changes(): void
    {
        DB::table('teachers')->insert(['id' => 3, 'user_id' => 2]);
        $id = $this->insertSheet(2);
        try {
            $this->migration->up();
            $this->fail('Expected ambiguous teacher reference to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Ambiguous teacher reference', $exception->getMessage());
        }
        $this->assertDatabaseHas('grade_sheets', ['id' => $id, 'teacher_id' => 2]);
        $foreignKey = collect(Schema::getForeignKeys('grade_sheets'))->firstWhere('columns', ['teacher_id']);
        $this->assertSame('users', $foreignKey['foreign_table']);
    }
}
