<?php

namespace Tests\Feature;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Support\TutorMessageRenderer;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentTutorRenderingTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_markdown_renders_after_submission_refresh_and_reopening_without_changing_stored_text(): void
    {
        $markdown = <<<'MD'
### شرح الكسور Fractions

هذه **مقارنة مهمة** مع `total = 2`.
سطر آخر بالعربية.

- النصف
- الربع
    - تفصيل

1. اقرأ
2. قارن

| الكسر | المعنى | Example |
| --- | --- | --- |
| نصف | جزء من اثنين | 1/2 |

```js
const total = 2;
console.log("<script>example</script>");
```
MD;
        $this->app->instance(SmartTutorGateway::class, new class($markdown) implements SmartTutorGateway
        {
            public function __construct(private string $markdown) {}

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                return new SmartTutorReply($this->markdown);
            }
        });
        $student = $this->createStudentFixture('render');
        $this->actingAs($student['user'])->post(route('student.tutor.conversations.store'))->assertRedirect();
        $conversation = $student['student']->tutorConversations()->firstOrFail();
        $question = "اشرح **الكسور** <img src=x onerror=alert(1)>\nمع مثال English";
        $this->post(route('student.tutor.messages.store', $conversation), [
            'message' => $question,
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversation));

        for ($visit = 0; $visit < 3; $visit++) {
            $this->get(route('student.tutor.show', $conversation))
                ->assertOk()
                ->assertSee('<h3>شرح الكسور Fractions</h3>', false)
                ->assertSee('<strong>مقارنة مهمة</strong>', false)
                ->assertSee('<ul>', false)
                ->assertSee('<ol>', false)
                ->assertSee('<table>', false)
                ->assertSee('class="tutor-table-scroll"', false)
                ->assertSee('tabindex="0"', false)
                ->assertSee('<code>total &#61; 2</code>', false)
                ->assertSee('<pre><code>', false)
                ->assertSee("<br />\n", false)
                ->assertSee(e($question), false)
                ->assertDontSee('<img src=x onerror=alert(1)>', false);
            $this->get(route('student.tutor.index'))->assertOk()->assertSeeText($conversation->fresh()->title);
        }

        $this->assertDatabaseHas('tutor_messages', ['role' => 'assistant', 'content' => $markdown]);
        $this->assertDatabaseHas('tutor_messages', ['role' => 'user', 'content' => $question]);
        $this->assertDatabaseCount('tutor_messages', 2);
    }

    public function test_untrusted_content_cannot_create_active_html_or_unsafe_urls(): void
    {
        $source = <<<'MD'
<script>alert(1)</script>
<img src=x onerror=alert(1)>
<svg onload=alert(1)></svg>
<iframe srcdoc="<script>alert(1)</script>"></iframe>
<a href="javascript:alert(1)" style="color:red" onclick="alert(1)">raw</a>

[bad](javascript:alert%281%29)
[data](data:text/html,boom)
[file](file:///etc/passwd)
[encoded](jav&#x61;script:alert%281%29)
![image](https://example.test/tracker.png)
[safe](https://example.test/lesson)

عربي، English **نص سليم**.
MD;
        $html = (new TutorMessageRenderer)->render($source)->toHtml();
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>');
        $xpath = new DOMXPath($document);
        $this->assertSame(0, $xpath->query('//script|//img|//svg|//iframe|//style|//object|//embed')->length);
        foreach ($xpath->query('//@*') as $attribute) {
            $this->assertFalse(str_starts_with(strtolower($attribute->nodeName), 'on'));
            $this->assertNotSame('style', $attribute->nodeName);
            if ($attribute->nodeName === 'href') {
                $this->assertSame('https://example.test/lesson', $attribute->nodeValue);
            }
        }
        $this->assertStringContainsString('<strong>نص سليم</strong>', $html);
        $this->assertStringContainsString('href="https://example.test/lesson"', $html);
    }

    public function test_long_arabic_and_mixed_language_text_survives_rendering_without_truncation(): void
    {
        $source = str_repeat('شرح عربي، English & < > مع مثال. ', 300).'النهاية';
        $html = (new TutorMessageRenderer)->render($source)->toHtml();
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>');

        $this->assertSame($source, trim($document->getElementsByTagName('body')->item(0)->textContent));
    }
}
