<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DesignReviewSeeder extends Seeder
{
    public function run(): void
    {
        $database = (string) config('database.connections.sqlite.database');
        if (! app()->environment('local', 'testing') || config('database.default') !== 'sqlite'
            || ! in_array(basename($database), ['design-demo.sqlite', ':memory:'], true)) {
            throw new RuntimeException('Design demo seeding requires the dedicated design-demo.sqlite or in-memory test database.');
        }
        $this->call(LocalDemoSeeder::class);
        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $students = Student::with('classroom')->orderBy('id')->get();
        $subject = DB::table('subjects')->where('code', 'MATH-1')->first();
        foreach ($students as $index => $student) {
            for ($day = 0; $day < 12; $day++) {
                DB::table('attendance_records')->updateOrInsert([
                    'student_id' => $student->id, 'date' => now()->subDays($day)->toDateString(),
                ], [
                    'classroom_id' => $student->classroom_id,
                    'status' => $day === 4 ? 'absent' : ($day === 7 ? 'late' : 'present'),
                    'recorded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ([21, 7] as $periodIndex => $daysAgo) {
                $name = 'تقييم تجريبي '.($periodIndex + 1);
                DB::table('exam_periods')->updateOrInsert(['name' => $name], [
                    'academic_year_id' => $student->classroom->academic_year_id,
                    'classroom_id' => $student->classroom_id, 'term' => 'الأول', 'exam_type' => 'شهري',
                    'status' => 'published', 'published_at' => now()->subDays($daysAgo), 'created_by' => $admin->id,
                ]);
                $periodId = DB::table('exam_periods')->where('name', $name)->value('id');
                $score = 12 + $periodIndex * 3 + $index;
                DB::table('result_publications')->updateOrInsert([
                    'exam_period_id' => $periodId, 'student_id' => $student->id,
                ], ['payload' => json_encode([
                    'subjects' => [['subject_id' => $subject->id, 'subject' => $subject->name, 'score' => $score, 'maximum_score' => 20, 'notes' => null]],
                    'total' => $score, 'maximum' => 20, 'percentage' => $score * 5, 'passed' => true, 'rating' => 'جيد',
                ], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
