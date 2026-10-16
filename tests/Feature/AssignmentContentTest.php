<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AssignmentContentRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class AssignmentContentTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_parent_and_student_read_formatted_homework_without_editor_code(): void
    {
        [$student, $parent, $assignment] = $this->fixture([
            'instructions' => '<p data-path-to-node="2,1,1,0">أجب عن الأسئلة التالية:</p><p><span data-index-in-node="0"><b>السؤال الأول:</b></span> علل ظهور القلم مكسوراً في الماء.</p><ul><li>ارسم مسار الضوء.</li><li>اذكر تطبيقين للألياف البصرية.</li></ul>',
        ]);
        foreach ([[$parent, route('parent.assignments')], [$student, route('student.assignments.show', $assignment)]] as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()->assertSeeText('أجب عن الأسئلة التالية:')
                ->assertSee('<b>السؤال الأول:</b>', false)->assertSee('<ul><li>ارسم مسار الضوء.</li>', false)
                ->assertDontSee('data-path-to-node', false)->assertDontSee('data-index-in-node', false)->assertDontSee('&lt;p', false);
        }
    }

    public function test_legacy_description_is_used_when_instructions_are_empty(): void
    {
        [$student, $parent, $assignment] = $this->fixture(['instructions' => null, 'description' => '<p><strong>Legacy description</strong></p>']);
        $this->actingAs($parent)->get(route('parent.assignments'))->assertOk()->assertSee('<strong>Legacy description</strong>', false);
        $this->actingAs($student)->get(route('student.assignments.show', $assignment))->assertOk()->assertSee('<strong>Legacy description</strong>', false);
    }

    public function test_escaped_legacy_markup_renders_as_paragraphs_instead_of_code(): void
    {
        $html = $this->render('&lt;p data-path-to-node=&quot;2,1,1&quot;&gt;السؤال الأول&lt;/p&gt;&lt;p&gt;السؤال الثاني&lt;/p&gt;');
        $this->assertStringContainsString('<p>السؤال الأول</p><p>السؤال الثاني</p>', $html);
        $this->assertStringNotContainsString('data-path-to-node', $html);
        $this->assertStringNotContainsString('&lt;p', $html);
    }

    public function test_plain_text_newlines_and_comparison_symbols_remain_readable(): void
    {
        $html = $this->render("القوة: 2 < 5 & 7 > 3\nالسؤال التالي");
        $this->assertStringContainsString('2 &lt; 5 &amp; 7 &gt; 3', $html);
        $this->assertStringContainsString("<br />\nالسؤال التالي", $html);
    }

    public function test_scripts_event_handlers_and_unsafe_links_are_not_rendered(): void
    {
        $html = $this->render('<p onclick="alert(1)" style="position:fixed" id="x">Homework<script>alert(2)</script><img src="x" onerror="alert(3)"><iframe srcdoc="bad">Hidden frame</iframe><svg onload="alert(4)"><text>Hidden SVG</text></svg><a href="javascript:alert(5)">Unsafe link label</a></p>');
        $this->assertStringContainsString('Homework', $html);
        $this->assertStringContainsString('Unsafe link label', $html);
        foreach (['alert(', 'onclick', 'onerror', 'style=', '<img', '<iframe', '<svg', 'Hidden frame', 'Hidden SVG', 'javascript:'] as $code) {
            $this->assertStringNotContainsString($code, $html);
        }
    }

    public function test_valid_links_and_deliberate_code_examples_are_preserved(): void
    {
        $html = $this->render('<a href="https://example.test/worksheet?a=1&amp;b=2" data-path-to-node="2">Worksheet</a><pre><code>&lt;p&gt;Code example&lt;/p&gt;</code></pre>');
        $this->assertStringContainsString('<a href="https://example.test/worksheet?a=1&amp;b=2">Worksheet</a>', $html);
        $this->assertStringContainsString('<pre><code>&lt;p&gt;Code example&lt;/p&gt;</code></pre>', $html);
        $this->assertStringNotContainsString('data-path-to-node', $html);
    }

    public function test_pasted_unclosed_markup_does_not_cause_a_server_error(): void
    {
        [$student, $parent, $assignment] = $this->fixture(['instructions' => '<p data-path-to-node="2"><b>سؤال غير مغلق<br>تابع الإجابة']);
        $this->actingAs($parent)->get(route('parent.assignments'))->assertOk()->assertSeeText('تابع الإجابة');
        $this->actingAs($student)->get(route('student.assignments.show', $assignment))->assertOk()->assertSeeText('سؤال غير مغلق');
    }

    private function render(string $content): string
    {
        return app(AssignmentContentRenderer::class)->render($content)->toHtml();
    }

    private function fixture(array $values): array
    {
        $context = $this->createStudentFixture('content');
        $subject = $this->createSubjectFor($context['classroom'], 'content');
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'relationship' => 'father', 'status' => 'active']);
        $guardian->students()->attach($context['student']->id);
        $assignment = Assignment::create(array_merge([
            'title' => 'ورقة عمل: ظواهر وتطبيقات ضوئية', 'instructions' => null,
            'classroom_id' => $context['classroom']->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'status' => 'published', 'published_at' => now(), 'due_at' => now()->addDay(),
        ], $values));
        return [$context['user'], $parent, $assignment];
    }
}
