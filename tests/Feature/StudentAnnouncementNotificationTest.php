<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Services\StudentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentAnnouncementNotificationTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_admin_publishing_an_announcement_delivers_to_active_target_students_immediately(): void
    {
        $own = $this->createStudentFixture('own');
        $other = $this->createStudentFixture('other');
        $inactive = $this->createStudentFixture('inactive');
        $inactive['student']->update(['status' => 'inactive']);
        $disabled = $this->createStudentFixture('disabled');
        $disabled['user']->update(['status' => 'inactive']);

        $this->actingAs($this->createAdmin())->post(route('admin.announcements.store'), [
            'title' => 'Class news', 'content' => 'Bring your science project.',
            'audience' => 'classroom', 'classroom_id' => $own['classroom']->id, 'status' => 'published',
        ])->assertRedirect(route('admin.announcements.index'));

        $this->assertSame(1, $own['user']->unreadNotifications()->count());
        $this->assertSame(0, $other['user']->notifications()->count());
        $this->actingAs($this->createAdmin())->post(route('admin.announcements.store'), [
            'title' => 'School news', 'content' => 'Everyone is welcome.', 'audience' => 'all', 'status' => 'published',
        ])->assertRedirect();
        $this->assertSame(2, $own['user']->unreadNotifications()->count());
        $this->assertSame(1, $other['user']->unreadNotifications()->count());
        $this->assertSame(0, $inactive['user']->notifications()->count());
        $this->assertSame(0, $disabled['user']->notifications()->count());
    }

    public function test_summary_includes_only_currently_published_announcements_for_student_audience(): void
    {
        $student = $this->createStudentFixture('own');
        $other = $this->createStudentFixture('other');
        $this->announcement(['title' => 'Everyone', 'audience' => 'all']);
        $this->announcement(['title' => 'Students']);
        $this->announcement(['title' => 'Own class', 'audience' => 'classroom', 'classroom_id' => $student['classroom']->id]);
        $this->announcement(['title' => 'Parents', 'audience' => 'parents']);
        $this->announcement(['title' => 'Teachers', 'audience' => 'teachers']);
        $this->announcement(['title' => 'Other class', 'audience' => 'classroom', 'classroom_id' => $other['classroom']->id]);
        $this->announcement(['title' => 'Draft', 'status' => 'draft']);
        $this->announcement(['title' => 'Archived', 'status' => 'archived']);
        $this->announcement(['title' => 'Later', 'published_at' => now()->addHour()]);
        $this->announcement(['title' => 'Expired', 'expires_at' => now()->subMinute()]);

        $response = $this->actingAs($student['user'])->getJson(route('student.notifications.summary'))
            ->assertOk()->assertJsonPath('unread_count', 3)->assertJsonCount(3, 'notifications');
        $this->assertEqualsCanonicalizing(['Everyone', 'Students', 'Own class'], array_column($response->json('notifications'), 'title'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(3, $student['user']->notifications()->count());
    }

    public function test_admin_edit_form_preserves_published_audience_and_classroom_and_restores_failed_input(): void
    {
        $own = $this->createStudentFixture('form');
        $other = $this->createStudentFixture('retry');
        $announcement = $this->announcement(['audience' => 'classroom', 'classroom_id' => $own['classroom']->id]);
        $this->actingAs($this->createAdmin())->get(route('admin.announcements.edit', $announcement))->assertOk()
            ->assertSee('value="classroom" selected', false)
            ->assertSee('value="published" selected', false)
            ->assertSee('value="'.$own['classroom']->id.'" selected', false);

        $this->withSession(['_old_input' => [
            'audience' => 'classroom', 'status' => 'draft', 'classroom_id' => (string) $other['classroom']->id,
        ]])->get(route('admin.announcements.edit', $announcement))->assertOk()
            ->assertSee('value="draft" selected', false)
            ->assertSee('value="'.$other['classroom']->id.'" selected', false)
            ->assertDontSee('value="published" selected', false)
            ->assertDontSee('value="'.$own['classroom']->id.'" selected', false);
    }

    public function test_scheduled_announcements_arrive_on_refresh_without_a_background_worker(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $student = $this->createStudentFixture('scheduled');
        $announcement = $this->announcement(['published_at' => now()->addMinutes(5)]);
        app(StudentNotificationService::class)->sendAnnouncement($announcement);
        $this->actingAs($student['user'])->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 0);

        $this->travel(5)->minutes();
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 1)->assertJsonPath('notifications.0.title', 'New announcement');
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 1);
        $this->assertSame(1, $student['user']->notifications()->count());
    }

    public function test_repeated_publication_and_edits_preserve_read_state_without_duplicate_notifications(): void
    {
        $student = $this->createStudentFixture('edit');
        $announcement = $this->announcement();
        $service = app(StudentNotificationService::class);
        $service->sendAnnouncement($announcement);
        $id = $student['user']->notifications()->firstOrFail()->id;
        $this->actingAs($student['user'])->patchJson(route('student.notifications.read', $id))
            ->assertOk()->assertJsonPath('unread_count', 0)->assertJsonPath('url', route('student.announcements.show', $announcement));

        $this->actingAs($this->createAdmin())->put(route('admin.announcements.update', $announcement), [
            'title' => 'Updated announcement', 'content' => 'Revised details.', 'audience' => 'students', 'status' => 'published',
        ])->assertRedirect();
        $this->actingAs($student['user'])->getJson(route('student.notifications.summary'))
            ->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.0.is_read', true)
            ->assertJsonPath('notifications.0.title', 'Updated announcement')->assertJsonPath('notifications.0.body', 'Revised details.');
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 0);
        $this->assertSame(1, $student['user']->notifications()->count());
    }

    public function test_retargeting_expiry_archiving_and_class_transfers_remove_inaccessible_notifications(): void
    {
        $student = $this->createStudentFixture('own');
        $other = $this->createStudentFixture('other');
        $retargeted = $this->announcement();
        $expired = $this->announcement();
        $archived = $this->announcement();
        $class = $this->announcement(['audience' => 'classroom', 'classroom_id' => $student['classroom']->id]);
        $this->actingAs($student['user'])->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 4);
        $id = $student['user']->notifications()->where('data->announcement_id', $retargeted->id)->firstOrFail()->id;

        $retargeted->update(['audience' => 'teachers']);
        $expired->update(['expires_at' => now()->subSecond()]);
        $archived->update(['status' => 'archived']);
        $class->update(['classroom_id' => $other['classroom']->id]);
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
        $this->patchJson(route('student.notifications.read', $id))->assertNotFound();
        $this->get(route('student.announcements.show', $retargeted))->assertNotFound();

        $class->update(['classroom_id' => $student['classroom']->id]);
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 1);
        $student['student']->update(['classroom_id' => $other['classroom']->id]);
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 0);
    }

    public function test_mark_all_read_only_updates_the_current_students_visible_notifications(): void
    {
        $student = $this->createStudentFixture('own');
        $other = $this->createStudentFixture('other');
        $announcement = $this->announcement();
        app(StudentNotificationService::class)->sendAnnouncement($announcement);
        $student['user']->notify(new ParentPortalNotification(['title' => 'Results', 'body' => 'Your results are ready.', 'url' => route('student.results')]));

        $this->actingAs($student['user'])->patchJson(route('student.notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 0)->assertJsonCount(2, 'notifications');
        $this->assertSame(0, $student['user']->unreadNotifications()->count());
        $this->assertSame(1, $other['user']->unreadNotifications()->count());
        $foreign = $other['user']->notifications()->firstOrFail();
        $this->patchJson(route('student.notifications.read', $foreign->id))->assertNotFound();
        $this->patchJson(route('student.notifications.read', 'invalid-id'))->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_unread_filter_and_total_count_are_independent_of_the_five_item_preview_limit(): void
    {
        $student = $this->createStudentFixture('many');
        for ($index = 0; $index < 7; $index++) {
            $this->announcement(['title' => 'Notice '.$index]);
        }
        $response = $this->actingAs($student['user'])->getJson(route('student.notifications.summary'))
            ->assertJsonPath('unread_count', 7)->assertJsonCount(5, 'notifications');
        $id = $response->json('notifications.0.id');
        $this->patchJson(route('student.notifications.read', $id))->assertJsonPath('unread_count', 6);
        $response = $this->getJson(route('student.notifications.summary', ['unread' => 1]))
            ->assertJsonPath('unread_count', 6)->assertJsonCount(5, 'notifications');
        $this->assertNotContains($id, array_column($response->json('notifications'), 'id'));
        $this->assertSame([false], array_values(array_unique(array_column($response->json('notifications'), 'is_read'))));
    }

    public function test_opening_an_authorized_announcement_displays_full_content_and_marks_it_read(): void
    {
        $student = $this->createStudentFixture('detail');
        $announcement = $this->announcement(['content' => str_repeat('Full content. ', 30).'Final detail.']);
        $this->actingAs($student['user'])->get(route('student.announcements.show', $announcement))
            ->assertOk()->assertSeeText('New announcement')->assertSeeText('Final detail.');
        $this->getJson(route('student.notifications.summary'))->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.0.is_read', true);
    }

    public function test_the_bell_has_the_current_unread_count_on_other_student_screens(): void
    {
        $student = $this->createStudentFixture('header');
        $this->announcement(['title' => 'School announcement in the bell']);

        $this->actingAs($student['user'])->get(route('student.profile'))->assertOk()
            ->assertSee('aria-label="الإشعارات، 1 غير مقروءة"', false)
            ->assertSeeText('School announcement in the bell');
        $this->get(route('student.subjects.index'))->assertOk()->assertSee('aria-label="الإشعارات، 1 غير مقروءة"', false);
        $this->patchJson(route('student.notifications.read-all'))->assertOk();
        $this->get(route('student.profile'))->assertOk()->assertSee('aria-label="الإشعارات، 0 غير مقروءة"', false);
        $this->assertSame(1, $student['user']->notifications()->count());
    }

    public function test_notification_endpoints_reject_guests_and_non_student_accounts(): void
    {
        $this->getJson(route('student.notifications.summary'))->assertUnauthorized();
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->actingAs($teacher)->getJson(route('student.notifications.summary'))->assertForbidden();
        $this->patchJson(route('student.notifications.read-all'))->assertForbidden();
    }

    private function announcement(array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'New announcement', 'content' => 'Announcement details.', 'audience' => 'students',
            'status' => 'published', 'published_at' => now()->subMinute(), 'created_by' => $this->createAdmin()->id,
        ], $overrides));
    }
}
