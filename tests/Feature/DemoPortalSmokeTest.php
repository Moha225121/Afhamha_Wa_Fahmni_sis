<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Student;
use App\Models\User;
use App\Services\SchoolAccountService;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DemoPortalSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_accounts_can_open_their_portal_pages_and_representative_records(): void
    {
        Http::preventStrayRequests();
        $this->seed(LocalDemoSeeder::class);
        $student = Student::where('student_number', 'S-1001')->firstOrFail();
        $assignment = Assignment::where('classroom_id', $student->classroom_id)->firstOrFail();
        $teacher = User::where('email', 'teacher1@example.test')->firstOrFail()->teacher;
        $guardian = User::where('email', 'parent@example.test')->firstOrFail()->guardian;
        $supervisor = User::where('email', 'supervisor@example.test')->firstOrFail();
        $exam = DB::table('exams')->where('classroom_id', $student->classroom_id)->first();
        $details = [
            'admin' => [
                'admin.students.show' => [$student->id],
                'admin.students.edit' => [$student->id],
                'admin.students.analysis' => [$student->id],
                'admin.teachers.show' => [$teacher->id],
                'admin.teachers.edit' => [$teacher->id],
                'admin.parents.show' => [$guardian->id],
                'admin.parents.edit' => [$guardian->id],
                'admin.classes.show' => [$student->classroom_id],
                'admin.classes.edit' => [$student->classroom_id],
                'admin.subjects.show' => [$assignment->subject_id],
                'admin.subjects.edit' => [$assignment->subject_id],
                'admin.supervisors.edit' => [$supervisor->id],
                'finance.students.show' => [$student->id],
            ],
            'teacher' => [
                'teacher.students.show' => [$student->id],
                'teacher.students.analysis' => [$student->id],
                'teacher.classes.show' => [$student->classroom_id],
                'teacher.assignments.show' => [$assignment->id],
                'teacher.assignments.edit' => [$assignment->id],
                'teacher.assignments.submissions' => [$assignment->id],
                'teacher.exams.edit' => [$exam->id],
            ],
            'parent' => [
                'parent.children.show' => [$student->id],
                'parent.students.analysis' => [$student->id],
            ],
            'student' => [
                'student.subjects.show' => [$assignment->subject_id],
                'student.lessons.index' => [$assignment->subject_id],
                'student.assignments.show' => [$assignment->id],
            ],
            'supervisor' => ['supervisor.students.show' => [$student->id]],
        ];
        $failures = [];

        foreach (['admin', 'teacher', 'parent', 'student', 'supervisor'] as $role) {
            $user = User::where('role', $role)->orderBy('id')->firstOrFail();
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password123'])
                ->assertRedirect(route($role.'.dashboard'));
            $this->assertAuthenticatedAs($user);

            $pages = $details[$role];
            foreach (Route::getRoutes() as $route) {
                $name = $route->getName();
                if (! $name || ! in_array('GET', $route->methods(), true)
                    || (! str_starts_with($name, $role.'.') && ! ($role === 'admin' && str_starts_with($name, 'finance.')))
                    || preg_match('/\{[^}]+(?<!\?)\}/', $route->uri())
                    || preg_match('/(?:download|export|print|avatar|api)/i', $name)) {
                    continue;
                }
                if ($name === 'student.finance' && ! app(SchoolAccountService::class)->settings()['student_finance_visible']) {
                    continue;
                }
                $pages[$name] = [];
            }

            foreach ($pages as $name => $parameters) {
                try {
                    $response = $this->followingRedirects()->get(route($name, $parameters));
                    // The seeded exam started three days ago, so editing it is intentionally forbidden.
                    $expectedStatus = $name === 'teacher.exams.edit' ? 403 : 200;
                    if ($response->status() !== $expectedStatus) {
                        $exception = $response->exception;
                        $failures[] = $role.' '.$name.': HTTP '.$response->status()
                            .($exception ? ' '.$exception->getMessage() : '');
                    } else {
                        $this->addToAssertionCount(1);
                    }
                } catch (\Throwable $exception) {
                    $failures[] = $role.' '.$name.': '.$exception->getMessage();
                }
            }

            $this->followingRedirects = false;
            $this->post(route('logout'))->assertRedirect(route('login'));
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
