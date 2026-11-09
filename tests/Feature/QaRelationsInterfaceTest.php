<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class QaRelationsInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->year = AcademicYear::create(['name' => 'QA 2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $this->actingAs($this->admin);
    }

    private function classroom(string $name = 'الصف الأول', string $section = 'A'): Classroom
    {
        return Classroom::create(['name' => $name, 'stage' => 'أساسي', 'section' => $section, 'academic_year_id' => $this->year->id]);
    }

    private function subject(string $code): Subject
    {
        return Subject::create(['name' => 'مادة '.$code, 'code' => $code, 'stage' => 'أساسي', 'status' => 'active']);
    }

    public function test_class_template_round_trips_without_renaming_headers(): void
    {
        $csv = $this->get('/admin/imports/classes/template')->assertOk()->streamedContent();
        $this->post('/admin/imports/classes', ['file' => UploadedFile::fake()->createWithContent('classes.csv', $csv)])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/classes');
        $this->assertDatabaseHas('classrooms', ['name' => 'الصف الأول', 'section' => 'أ', 'stage' => 'المرحلة الابتدائية']);
        $this->post('/admin/imports/classes', ['file' => UploadedFile::fake()->createWithContent('classes.csv', $csv)])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('classrooms', 1);
    }

    public function test_class_import_rejects_missing_required_values_atomically(): void
    {
        $csv = "الاسم,الشعبة,المرحلة,السنة الدراسية\nصف آمن,A,أساسي,2026/2027\nصف غير صالح,B,,2026/2027\n";
        $this->post('/admin/imports/classes', ['file' => UploadedFile::fake()->createWithContent('classes.csv', $csv)])
            ->assertSessionHasErrors('file');
        $this->assertDatabaseCount('classrooms', 0);
    }

    public function test_class_and_subject_relations_are_saved_reloaded_and_removed(): void
    {
        $a = $this->subject('QA-A');
        $b = $this->subject('QA-B');
        $payload = ['name' => 'الصف الأول', 'stage' => 'أساسي', 'section' => 'A', 'academic_year_id' => $this->year->id, 'subject_ids' => [$a->id, $b->id]];
        $this->withHeader('User-Agent', 'QA mobile browser')->post('/admin/classes', $payload)->assertSessionHasNoErrors();
        $room = Classroom::firstOrFail();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $room->subjects()->pluck('subjects.id')->all());
        $this->get('/admin/classes/'.$room->id.'/edit')->assertOk()->assertSee('type="checkbox"', false)->assertSee('QA-A');
        $this->put('/admin/classes/'.$room->id, array_replace($payload, ['subject_ids' => [$b->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$b->id], $room->fresh()->subjects()->pluck('subjects.id')->all());
        $second = $this->classroom('الصف الثاني');
        $subjectPayload = ['name' => $a->name, 'code' => $a->code, 'stage' => $a->stage, 'status' => 'active', 'classroom_ids' => [$room->id, $second->id]];
        $this->put('/admin/subjects/'.$a->id, $subjectPayload)->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$room->id, $second->id], $a->fresh()->classrooms()->pluck('classrooms.id')->all());
        $this->get('/admin/subjects/'.$a->id.'/edit')->assertOk()->assertSee('type="checkbox"', false);
        $this->put('/admin/subjects/'.$a->id, array_replace($subjectPayload, ['classroom_ids' => []]))->assertSessionHasNoErrors();
        $this->assertSame(0, $a->fresh()->classrooms()->count());
    }

    public function test_guardian_can_link_two_children_then_remove_one(): void
    {
        $children = collect([$this->classroom(), $this->classroom('الصف الثاني')])->map(fn ($room, $index) => Student::create([
            'user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id,
            'student_number' => 'QA-CHILD-'.$index, 'classroom_id' => $room->id, 'status' => 'active',
        ]));
        $payload = ['name' => 'ولي أمر اصطناعي', 'email' => 'qa-parent@example.test', 'phone' => '0000000000', 'relationship' => 'الأب', 'status' => 'active', 'student_ids' => $children->pluck('id')->all()];
        $this->post('/admin/parents', $payload)->assertSessionHasNoErrors();
        $parent = Guardian::firstOrFail();
        $this->assertEqualsCanonicalizing($children->pluck('id')->all(), $parent->students()->pluck('students.id')->all());
        $this->get('/admin/parents/'.$parent->id.'/edit')->assertOk()->assertSee('type="checkbox"', false);
        $this->put('/admin/parents/'.$parent->id, array_replace($payload, ['student_ids' => [$children->last()->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$children->last()->id], $parent->fresh()->students()->pluck('students.id')->all());
    }

    public function test_users_search_composes_with_role_status_and_pagination(): void
    {
        $keep = User::factory()->create(['name' => 'QA match parent', 'role' => 'parent', 'status' => 'active']);
        User::factory()->create(['name' => 'QA match teacher', 'role' => 'teacher', 'status' => 'active']);
        User::factory()->create(['name' => 'QA match inactive', 'role' => 'parent', 'status' => 'inactive']);
        $response = $this->get('/admin/users?q=QA%20match&role=parent&status=active')->assertOk();
        $this->assertSame([$keep->id], $response->viewData('users')->pluck('id')->all());
        $response->assertSee('إعادة الضبط');
        $this->get('/admin/users')->assertOk()->assertViewHas('users', fn ($rows) => $rows->total() === 4);
    }

    public function test_students_filter_has_academic_order_sections_and_grouped_search(): void
    {
        $six = $this->classroom('الصف السادس', 'B');
        $oneB = $this->classroom('الصف الأول', 'B');
        $oneA = $this->classroom('الصف الأول', 'A');
        foreach ([$six, $oneA] as $index => $room) {
            Student::create(['user_id' => User::factory()->create(['name' => 'QA matching '.$index, 'role' => 'student'])->id, 'student_number' => 'QA-MATCH-'.$index, 'classroom_id' => $room->id, 'status' => 'active']);
        }
        $response = $this->get('/admin/students?q=QA&classroom_id='.$oneA->id)->assertOk();
        $this->assertSame([$oneA->id, $oneB->id, $six->id], $response->viewData('classrooms')->pluck('id')->all());
        $this->assertSame(1, $response->viewData('students')->total());
        $response->assertSee('الصف الأول A');
    }

    public function test_exam_filters_and_grade_filters_preserve_scope(): void
    {
        $room = $this->classroom();
        $otherRoom = $this->classroom('الصف الثاني');
        $subject = $this->subject('QA-EXAM');
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        foreach ([$room, $otherRoom] as $index => $classroom) {
            DB::table('exams')->insert(['title' => 'QA exam '.$index, 'classroom_id' => $classroom->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'starts_at' => now()->addDay(), 'duration_minutes' => 30, 'total_score' => 20, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        }
        $response = $this->get('/admin/exams?classroom_id='.$room->id.'&subject_id='.$subject->id.'&teacher_id='.$teacher->id.'&status=scheduled&q=QA')->assertOk();
        $this->assertSame(1, $response->viewData('rows')->total());
        $this->assertSame($room->id, $response->viewData('rows')->first()->classroom_id);
        $this->get('/admin/grades?classroom_id='.$room->id.'&academic_year_id='.$this->year->id)->assertOk()->assertViewHas('grades', fn ($rows) => $rows->count() === 1);
        $this->actingAs($teacher->user)->get('/admin/exams')->assertForbidden();
    }

    public function test_admin_chat_filters_never_reveal_another_admins_conversation(): void
    {
        $room = $this->classroom();
        $student = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'QA-CHAT', 'classroom_id' => $room->id, 'status' => 'active']);
        $parent = User::factory()->create(['name' => 'QA chat guardian', 'role' => 'parent']);
        $mine = Conversation::create(['student_id' => $student->id, 'created_by' => $parent->id, 'subject' => 'QA chat', 'status' => 'open', 'last_message_at' => now()]);
        $mine->participants()->attach([$this->admin->id, $parent->id]);
        $private = Conversation::create(['student_id' => $student->id, 'created_by' => $parent->id, 'subject' => 'QA private', 'status' => 'open', 'last_message_at' => now()]);
        $private->participants()->attach([$parent->id, User::factory()->create(['role' => 'admin'])->id]);
        $response = $this->get('/admin/conversations?q=QA&status=open&classroom_id='.$room->id)->assertOk()
            ->assertSee('css/school-chat.css')->assertDontSee("@stack('styles')", false)
            ->assertSee('id="sidebar-close"', false)->assertSee('aria-controls="sidebar"', false);
        $this->assertSame([$mine->id], $response->viewData('conversations')->pluck('id')->all());
        $this->get('/admin/conversations?status=closed')->assertOk()->assertViewHas('conversations', fn ($rows) => $rows->isEmpty());
        $this->get('/admin/conversations/'.$private->id)->assertNotFound();
    }

    public function test_filters_validate_values_and_preserve_pagination_parameters(): void
    {
        User::factory()->count(21)->create(['name' => 'QA pagination', 'role' => 'parent', 'status' => 'active']);
        $officer = User::factory()->create(['role' => 'financial_officer', 'status' => 'active']);
        $this->get('/admin/users?role=financial_officer')->assertOk()->assertViewHas('users', fn ($rows) => $rows->pluck('id')->all() === [$officer->id]);
        $response = $this->get('/admin/users?q=QA&role=parent&status=active')->assertOk();
        $response->assertSee('page=2')->assertSee('role=parent')->assertSee('status=active');
        $this->get('/admin/users?q=QA&role=parent&status=active&page=2')->assertOk()
            ->assertViewHas('users', fn ($rows) => $rows->count() === 1 && $rows->total() === 21);
        $this->getJson('/admin/users?status=invalid')->assertUnprocessable();
        $this->getJson('/admin/exams?classroom_id=invalid')->assertUnprocessable();
        $this->getJson('/admin/grades?academic_year_id=invalid')->assertUnprocessable();
        $this->getJson('/admin/conversations?status=invalid')->assertUnprocessable();
    }

    public function test_student_cards_render_filtered_students_and_require_valid_signed_verification(): void
    {
        $room = $this->classroom();
        $other = $this->classroom('الصف الثاني');
        $students = collect([$room, $other])->map(fn ($class, $index) => Student::create([
            'user_id' => User::factory()->create(['name' => 'QA CARD '.$index, 'role' => 'student'])->id,
            'student_number' => 'QA-CARD-'.$index, 'classroom_id' => $class->id, 'status' => 'active',
        ]));
        $this->get('/admin/students/cards?classroom_id='.$room->id)->assertOk()->assertSee('QA CARD 0')->assertDontSee('QA CARD 1')->assertSee('data:image/png;base64,', false);
        $signed = URL::temporarySignedRoute('student.card.verify', now()->addMinutes(5), ['student' => $students->first()->id]);
        auth()->logout();
        $this->get($signed)->assertOk()->assertSee('QA CARD 0');
        $this->get(route('student.card.verify', $students->first()))->assertForbidden();
        $this->get(str_replace('/'.$students->first()->id.'/', '/'.$students->last()->id.'/', $signed))->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($signed)->assertForbidden();
        $this->travelBack();
    }

    public function test_lesson_quick_navigation_contains_only_accessible_published_lessons(): void
    {
        $room = $this->classroom();
        $other = $this->classroom('الصف الثاني');
        $subject = $this->subject('QA-NAV');
        $room->subjects()->attach($subject);
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $user->id, 'student_number' => 'QA-NAV', 'classroom_id' => $room->id, 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $data = ['subject_id' => $subject->id, 'classroom_id' => $room->id, 'teacher_id' => $teacher->id, 'status' => 'published', 'published_at' => now()->subMinute(), 'content' => 'QA content'];
        $visible = Lesson::create($data + ['title' => 'QA VISIBLE']);
        Lesson::create(array_replace($data, ['title' => 'QA DRAFT', 'status' => 'draft']));
        Lesson::create(array_replace($data, ['title' => 'QA FUTURE', 'published_at' => now()->addDay()]));
        $private = Lesson::create(array_replace($data, ['title' => 'QA OTHER ROOM', 'classroom_id' => $other->id]));
        $this->actingAs($user)->get(route('student.lessons.show', [$subject, $visible]))->assertOk()
            ->assertSee('الوصول السريع للدروس')->assertSee('QA VISIBLE')->assertDontSee('QA DRAFT')->assertDontSee('QA FUTURE')->assertDontSee('QA OTHER ROOM');
        $this->get(route('student.lessons.index', $subject))->assertOk()->assertSee('lesson-quick-nav')->assertSee('QA VISIBLE')->assertDontSee('QA OTHER ROOM');
        $this->get(route('student.lessons.show', [$subject, $private]))->assertNotFound();
        $teacher->classrooms()->attach($room, ['subject_id' => $subject->id]);
        $this->actingAs($teacher->user)->get(route('teacher.lessons.create'))->assertOk()->assertSee('QA-NAV');
    }
}
