<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherGradeSheetPercentageTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_sheet_percentages_are_preserved_when_saved_and_displayed(): void
    {
        $fixture = $this->createStudentFixture('PERCENT');
        $student = $fixture['student'];
        $classroom = $fixture['classroom'];
        $subject = $this->createSubjectFor($classroom, 'PERCENT');
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'specialization' => 'رياضيات', 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($user);

        foreach ([0, 0.5, 1, 75] as $score) {
            $this->postJson(route('teacher.grades.store'), [
                'classroom_id' => $classroom->id,
                'scores' => [$student->id => $score],
                'sheet_columns' => [
                    ['key' => 'monthly', 'title' => 'اختبار شهري', 'max_score' => 100],
                    ['key' => 'custom_2026', 'title' => 'نشاط إضافي', 'max_score' => 100],
                ],
                'column_scores' => [
                    'monthly' => [$student->id => $score],
                    'custom_2026' => [$student->id => $score],
                ],
            ])->assertOk()->assertJsonPath('scores.'.$student->id, $score);

            $saved = json_decode(DB::table('grade_sheets')
                ->where('teacher_id', $teacher->id)
                ->where('classroom_id', $classroom->id)->value('scores'), true);
            $this->assertSame((float) $score, (float) $saved[$student->id]);

            $this->get(route('teacher.grades.index', ['classroom_id' => $classroom->id]))
                ->assertOk()->assertViewHas('grades', fn ($grades) => (float) $grades[$student->id]->score === (float) $score)
                ->assertViewHas('gradeSheetColumns', fn ($columns) => array_column($columns, 'key') === ['monthly', 'custom_2026'])
                ->assertViewHas('columnScores', fn ($columns) => array_keys($columns) === ['monthly', 'custom_2026']
                    && (float) $columns['monthly'][$student->id] === (float) $score
                    && (float) $columns['custom_2026'][$student->id] === (float) $score);
            $this->get(route('teacher.students.show', $student))
                ->assertOk()->assertViewHas('averagePercent', (float) $score);
            $this->get(route('teacher.students.index'))
                ->assertOk()->assertViewHas('students', fn ($students) => $students->firstWhere('id', $student->id)->average_percent === (float) $score);
        }
    }
}
