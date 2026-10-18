<?php

namespace Tests\Feature;

use Tests\TestCase;

class StudentPwaTest extends TestCase
{
    public function test_student_app_has_its_own_public_identity_and_launch_page(): void
    {
        $manifest = $this->get(route('student.pwa.manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->json();

        $this->assertSame('/student/', $manifest['id']);
        $this->assertSame('/student/', $manifest['scope']);
        $this->assertSame('/student/dashboard', $manifest['start_url']);
        $this->assertSame('بوابة الطالب', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }
        foreach ($manifest['shortcuts'] as $shortcut) {
            $this->assertStringStartsWith('/student/', $shortcut['url']);
        }

        $this->get(route('student.pwa.service-worker'))->assertOk()
            ->assertHeader('Content-Type', 'application/javascript');
        $this->get(route('student.offline'))->assertOk()
            ->assertSeeText('أنت غير متصل حالياً')
            ->assertSee('href="/student/dashboard"', false);
    }

    public function test_student_layout_exposes_install_button_and_student_specific_help(): void
    {
        $this->view('student.layout', ['studentNotificationSummary' => [
            'unread_count' => 0,
            'notifications' => [],
        ]])->assertSeeText('تثبيت التطبيق')
            ->assertSeeText('تثبيت تطبيق الطالب')
            ->assertSee('student-manifest.webmanifest', false)
            ->assertSee('js/student-install.js', false)
            ->assertSee('data-service-worker-scope="/student/"', false);
    }
}
