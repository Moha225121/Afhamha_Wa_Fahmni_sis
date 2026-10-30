<?php

namespace Tests\Unit;

use App\Support\RichText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\Parser\MastermindsParser;

class RichTextTest extends TestCase
{
    public static function payloads(): array
    {
        $payloads = [
            '<img src=x onerror="auditAttack()"><b>عربي</b>',
            '<p onclick="auditAttack()" id="location" name="cookie">عربي</p>',
            '<svg><a onload="auditAttack()">عربي</a></svg>',
            '<math><mtext><img src=x onerror="auditAttack()"></mtext></math>',
            '<iframe srcdoc="<script>auditAttack()</script>"></iframe><b>عربي</b>',
            '<script>auditAttack()</script><style>body{display:none}</style><b>عربي</b>',
            '<a href="javascript:auditAttack()">عربي</a>',
            '<p style="background:url(https://leak.example.test);position:fixed;text-align:right">عربي</p>',
            '<div dir="rtl" style="text-align:expression(auditAttack())">عربي</div>',
            '</textarea><img src=x onerror="auditAttack()"><u>عربي</u>',
        ];
        $cases = [];
        foreach ($payloads as $i => $html) {
            $cases['native-'.$i] = [$html, false];
            $cases['php83-parser-'.$i] = [$html, true];
        }

        return $cases;
    }

    #[DataProvider('payloads')]
    public function test_active_content_and_arbitrary_css_cannot_reach_editors(string $html, bool $legacyParser): void
    {
        $safe = (new RichText($legacyParser ? new MastermindsParser : null))->sanitize($html);
        foreach (['auditAttack', '<script', '<img', '<svg', '<math', '<iframe', '<style', 'onclick', 'onerror', 'javascript:', 'https:', 'position:', 'id=', 'name='] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $safe);
        }
    }

    public function test_toolbar_formatting_arabic_direction_and_alignment_are_preserved(): void
    {
        $safe = (new RichText)->sanitize('<div style="text-align:center" dir="rtl"><b>عنوان</b><i>مائل</i><u>تسطير</u><ul><li>نقطة</li></ul><ol><li>رقم</li></ol><blockquote>اقتباس</blockquote></div>');
        foreach (['text-align:center', 'dir="rtl"', '<b>عنوان</b>', '<i>مائل</i>', '<u>تسطير</u>', '<ul>', '<ol>', '<li>', '<blockquote>'] as $format) {
            $this->assertStringContainsString($format, $safe);
        }
    }

    public function test_existing_long_content_is_not_silently_truncated_and_malformed_old_input_is_safe(): void
    {
        $html = '<p>'.str_repeat('شرح عربي ', 6000).'</p>';
        $service = new RichText;
        $this->assertSame($html, $service->sanitize($html));
        $this->assertSame('', $service->sanitize(['malformed']));
        $this->assertSame('', $service->sanitize(null));
    }
}
