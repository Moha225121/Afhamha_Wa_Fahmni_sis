<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Guardian;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use App\Services\ParentNotificationService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class ParentAnnouncementNotificationTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_admin_publication_notifies_only_active_parents_of_target_class_once(): void
    {
        $child = $this->createStudentFixture('class');
        $parent = $this->parentAccount([$child['student']->id]);
        $second = $this->createStudentFixture('second');
        $parent->guardian->students()->attach($second['student']->id);
        $foreign = $this->parentAccount([$second['student']->id]);
        $disabled = $this->parentAccount([$child['student']->id]);
        $disabled->update(['status' => 'inactive']);
        $inactiveGuardian = $this->parentAccount([$child['student']->id]);
        $inactiveGuardian->guardian->update(['status' => 'inactive']);

        $this->actingAs($this->createAdmin())->post(route('admin.announcements.store'), [
            'title' => 'Class meeting', 'content' => 'Parents are invited.',
            'audience' => 'classroom', 'classroom_id' => $child['classroom']->id, 'status' => 'published',
        ])->assertRedirect(route('admin.announcements.index'));

        $this->assertSame(1, $parent->unreadNotifications()->count());
        $this->assertSame(0, $foreign->notifications()->count());
        $this->assertSame(0, $disabled->notifications()->count());
        $this->assertSame(0, $inactiveGuardian->notifications()->count());
        app(ParentNotificationService::class)->sendAnnouncement(Announcement::firstOrFail());
        $this->assertSame(1, $parent->notifications()->count());
    }

    public function test_missed_ads_appear_in_bell_messages_and_dashboard_with_one_visibility_rule(): void
    {
        $child = $this->createStudentFixture('own');
        $other = $this->createStudentFixture('other');
        $parent = $this->parentAccount([$child['student']->id]);
        $visible = ['Global news', 'Parent news', 'Legacy parent news', 'Own class news', 'Own parent class news'];
        $this->announcement(['title' => $visible[0], 'audience' => 'all']);
        $this->announcement(['title' => $visible[1]]);
        $this->announcement(['title' => $visible[2], 'audience' => 'parent']);
        $this->announcement(['title' => $visible[3], 'audience' => 'classroom', 'classroom_id' => $child['classroom']->id]);
        $this->announcement(['title' => $visible[4], 'classroom_id' => $child['classroom']->id]);
        $hidden = ['Teachers only', 'Students only', 'Other class', 'Other parents class', 'Draft news', 'Future news', 'Expired news', 'Invalid class'];
        $this->announcement(['title' => $hidden[0], 'audience' => 'teachers', 'classroom_id' => $child['classroom']->id]);
        $this->announcement(['title' => $hidden[1], 'audience' => 'students', 'classroom_id' => $child['classroom']->id]);
        $this->announcement(['title' => $hidden[2], 'audience' => 'classroom', 'classroom_id' => $other['classroom']->id]);
        $this->announcement(['title' => $hidden[3], 'classroom_id' => $other['classroom']->id]);
        $this->announcement(['title' => $hidden[4], 'status' => 'draft']);
        $this->announcement(['title' => $hidden[5], 'published_at' => now()->addHour()]);
        $this->announcement(['title' => $hidden[6], 'expires_at' => now()->subMinute()]);
        $this->announcement(['title' => $hidden[7], 'audience' => 'classroom']);
        $this->assertSame(0, $parent->notifications()->count());

        $response = $this->actingAs($parent)->getJson(route('parent.notifications.summary'))
            ->assertOk()->assertJsonPath('unread_count', 5)->assertJsonCount(5, 'notifications');
        $this->assertEqualsCanonicalizing($visible, array_column($response->json('notifications'), 'title'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $messages = $this->get(route('parent.messages'))->assertOk();
        foreach ($visible as $title) $messages->assertSeeText($title);
        foreach ($hidden as $title) $messages->assertDontSeeText($title);
        $this->get(route('parent.dashboard'))->assertOk()->assertSee('aria-label="الإشعارات، 5 غير مقروءة"', false);
        $this->get(route('parent.profile'))->assertOk()->assertSeeText('Global news');
    }

    public function test_scheduled_ad_arrives_when_due_without_republishing(): void
    {
        $this->travelTo('2026-10-16 10:00:00');
        $parent = $this->parentAccount();
        $ad = $this->announcement(['published_at' => now()->addMinutes(5)]);
        app(ParentNotificationService::class)->sendAnnouncement($ad);
        $this->actingAs($parent)->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 0);
        $this->travel(5)->minutes();
        $this->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 1);
        $this->get(route('parent.messages'))->assertSeeText($ad->title);
        $this->assertSame(1, $parent->notifications()->count());
    }

    public function test_old_read_state_is_preserved_without_duplicate_or_mistargeted_legacy_rows(): void
    {
        $parent = $this->parentAccount();
        $ad = $this->announcement();
        $parent->notify(new ParentPortalNotification(['title' => $ad->title, 'body' => Str::limit($ad->content, 140), 'category' => 'announcement']));
        $parent->notifications()->firstOrFail()->markAsRead();
        $parent->notify(new ParentPortalNotification(['title' => 'Old teacher-only ad', 'body' => 'Private', 'category' => 'announcement']));
        $parent->notify(new ParentPortalNotification(['title' => 'Personal account alert', 'body' => 'Your update', 'category' => 'grade']));

        $response = $this->actingAs($parent)->getJson(route('parent.notifications.summary'))
            ->assertJsonPath('unread_count', 1)->assertJsonCount(2, 'notifications');
        $this->assertNotContains('Old teacher-only ad', array_column($response->json('notifications'), 'title'));
        $record = collect($response->json('notifications'))->firstWhere('title', $ad->title);
        $this->assertTrue($record['is_read']);
        $this->getJson(route('parent.notifications.summary'))->assertJsonCount(2, 'notifications');
    }

    public function test_authorized_full_ad_marks_read_and_edits_do_not_reset_or_duplicate_it(): void
    {
        $parent = $this->parentAccount();
        $ad = $this->announcement(['content' => str_repeat('Full announcement. ', 30).'Final details.']);
        $this->actingAs($parent)->get(route('parent.announcements.show', $ad))->assertOk()->assertSeeText('Final details.');
        $this->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.0.is_read', true);
        $this->actingAs($this->createAdmin())->put(route('admin.announcements.update', $ad), [
            'title' => 'Updated parent notice', 'content' => 'Revised details', 'audience' => 'parents', 'status' => 'published',
        ])->assertRedirect();
        $this->actingAs($parent)->getJson(route('parent.notifications.summary'))
            ->assertJsonPath('unread_count', 0)->assertJsonPath('notifications.0.title', 'Updated parent notice');
        $this->assertSame(1, $parent->notifications()->count());
    }

    public function test_audience_edit_delivers_and_withdrawal_retargeting_or_child_unlink_hides_ads(): void
    {
        $child = $this->createStudentFixture('child');
        $other = $this->createStudentFixture('foreign');
        $parent = $this->parentAccount([$child['student']->id]);
        $ad = $this->announcement(['audience' => 'teachers']);
        $this->actingAs($this->createAdmin())->put(route('admin.announcements.update', $ad), [
            'title' => $ad->title, 'content' => $ad->content, 'audience' => 'parents', 'status' => 'published',
        ])->assertRedirect();
        $this->assertSame(1, $parent->notifications()->count());
        $expired = $this->announcement();
        $archived = $this->announcement();
        $class = $this->announcement(['audience' => 'classroom', 'classroom_id' => $child['classroom']->id]);
        $this->actingAs($parent)->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 4);
        $id = $parent->notifications()->where('data->announcement_id', $ad->id)->firstOrFail()->id;
        $ad->refresh()->update(['audience' => 'teachers']);
        $expired->update(['expires_at' => now()->subSecond()]);
        $archived->update(['status' => 'archived']);
        $class->update(['classroom_id' => $other['classroom']->id]);
        $this->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
        $this->patchJson(route('parent.notifications.read', $id))->assertNotFound();
        $this->get(route('parent.announcements.show', $ad))->assertNotFound();
        $class->update(['classroom_id' => $child['classroom']->id]);
        $this->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 1);
        $parent->guardian->students()->detach($child['student']->id);
        $this->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 0);
        $this->get(route('parent.announcements.show', $class))->assertNotFound();
    }

    public function test_read_all_and_individual_actions_are_scoped_to_current_parent(): void
    {
        $parent = $this->parentAccount();
        $other = $this->parentAccount();
        $this->announcement();
        $this->actingAs($other)->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 1);
        $foreign = $other->notifications()->firstOrFail();
        $response = $this->actingAs($parent)->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 1);
        $this->patchJson(route('parent.notifications.read', $foreign->id))->assertNotFound();
        $this->patchJson(route('parent.notifications.read', 'invalid'))->assertNotFound();
        $this->patchJson(route('parent.notifications.read', $response->json('notifications.0.id')))
            ->assertOk()->assertJsonPath('unread_count', 0);
        $this->announcement(['title' => 'Another notice']);
        $this->patchJson(route('parent.notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->get(route('parent.notifications', ['unread' => 1]))->assertSeeText('أنت مطّلع على كل جديد');
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_total_unread_count_is_not_limited_by_dropdown_and_messages_are_paginated(): void
    {
        $parent = $this->parentAccount();
        for ($index = 0; $index < 23; $index++) $this->announcement(['title' => 'Notice '.$index]);
        $fresh = $this->announcement(['title' => 'Immediate publication without a date', 'published_at' => null]);
        $response = $this->actingAs($parent)->getJson(route('parent.notifications.summary'))
            ->assertJsonPath('unread_count', 24)->assertJsonCount(5, 'notifications');
        $this->assertSame($fresh->title, $response->json('notifications.0.title'));
        $this->patchJson(route('parent.notifications.read', $response->json('notifications.0.id')))->assertJsonPath('unread_count', 23);
        $this->getJson(route('parent.notifications.summary', ['unread' => 1]))
            ->assertJsonPath('unread_count', 23)->assertJsonCount(5, 'notifications');
        $messages = $this->get(route('parent.messages'))->assertOk()->assertSeeText($fresh->title);
        $this->assertSame(20, $messages->viewData('announcements')->count());
        $this->get(route('parent.messages', ['page' => 2]))->assertOk()->assertViewHas('announcements', fn ($items) => $items->count() === 4);
    }

    public function test_push_is_sent_once_but_opening_parent_portal_does_not_push_old_ads(): void
    {
        $parent = $this->parentAccount();
        $this->mock(WebPushService::class)->shouldReceive('send')->once()->withArgs(fn ($user, $payload) =>
            $user->is($parent) && $payload['url'] === route('parent.announcements.show', $payload['announcement_id'], false));
        $ad = $this->announcement();
        app(ParentNotificationService::class)->sendAnnouncement($ad);
        app(ParentNotificationService::class)->sendAnnouncement($ad);
        $this->announcement(['title' => 'Missed earlier notice']);
        $this->actingAs($parent)->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 2);
    }

    public function test_personal_notifications_keep_the_selected_child_and_hide_after_unlinking(): void
    {
        $child = $this->createStudentFixture('personal');
        $parent = $this->parentAccount([$child['student']->id]);
        $parent->notify(new ParentPortalNotification([
            'title' => 'Child results', 'body' => 'A personal update', 'student_id' => $child['student']->id,
            'url' => route('parent.results', ['student' => $child['student']->id]),
        ]));
        $response = $this->actingAs($parent)->getJson(route('parent.notifications.summary'))->assertJsonPath('unread_count', 1);
        $this->patchJson(route('parent.notifications.read', $response->json('notifications.0.id')))
            ->assertJsonPath('url', '/parent/results?student='.$child['student']->id);
        $parent->guardian->students()->detach($child['student']->id);
        $this->getJson(route('parent.notifications.summary'))->assertJsonCount(0, 'notifications');
    }

    public function test_parent_without_children_can_see_global_ads_and_unauthorized_users_cannot_use_endpoints(): void
    {
        $this->getJson(route('parent.notifications.summary'))->assertUnauthorized();
        $parent = $this->parentAccount();
        $ad = $this->announcement(['title' => 'School holiday']);
        $this->actingAs($parent)->get(route('parent.dashboard'))->assertOk()->assertSeeText($ad->title)
            ->assertSee('aria-label="الإشعارات، 1 غير مقروءة"', false);
        $this->actingAs(User::factory()->create(['role' => 'student']))->getJson(route('parent.notifications.summary'))->assertForbidden();
        $this->patchJson(route('parent.notifications.read-all'))->assertForbidden();
        $parent->guardian->update(['status' => 'inactive']);
        $this->actingAs($parent)->getJson(route('parent.notifications.summary'))->assertForbidden();
    }

    private function parentAccount(array $studentIds = []): User
    {
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'relationship' => 'father', 'status' => 'active']);
        $guardian->students()->attach($studentIds);
        return $parent;
    }

    private function announcement(array $values = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Parent announcement', 'content' => 'School announcement details.', 'audience' => 'parents',
            'status' => 'published', 'published_at' => now()->subMinute(), 'created_by' => $this->createAdmin()->id,
        ], $values));
    }
}
