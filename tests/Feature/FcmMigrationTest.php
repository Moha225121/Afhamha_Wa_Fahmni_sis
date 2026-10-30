<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ParentPortalNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresFcm;
use Tests\TestCase;

class FcmMigrationTest extends TestCase
{
    use ConfiguresFcm, RefreshDatabase;

    public function test_additive_migration_rolls_back_and_reapplies_without_removing_legacy_subscriptions_or_notifications(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $legacy = $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/legacy', 'public_key' => 'public', 'auth_token' => 'auth']);
        $this->tokenFor($user, 'migration-device-token');
        $user->notify(new ParentPortalNotification(['title' => 'Existing', 'body' => 'Existing notification']));
        $migration = require database_path('migrations/2026_10_18_000002_create_fcm_tokens.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('fcm_tokens'));
        $this->assertFalse(Schema::hasColumn('push_subscriptions', 'device_id'));
        $this->assertDatabaseHas('push_subscriptions', ['id' => $legacy->id]);
        $this->assertSame(1, $user->notifications()->count());
        $migration->up();
        $this->assertTrue(Schema::hasTable('fcm_tokens'));
        $this->assertTrue(Schema::hasColumn('push_subscriptions', 'device_id'));
        $this->assertDatabaseCount('fcm_tokens', 0);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $legacy->id, 'device_id' => null]);
    }

    public function test_upgrade_from_a_populated_pre_fcm_schema_preserves_legacy_rows_and_read_state(): void
    {
        $migration = require database_path('migrations/2026_10_18_000002_create_fcm_tokens.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('push_subscriptions', 'device_id'));
        $user = User::factory()->create(['status' => 'active']);
        foreach (['one', 'two'] as $suffix) {
            $user->pushSubscriptions()->create(['endpoint' => 'https://push.example.test/'.$suffix, 'public_key' => 'fixture-public', 'auth_token' => 'fixture-auth', 'content_encoding' => 'aes128gcm']);
        }
        $user->notify(new ParentPortalNotification(['title' => 'Preserved', 'body' => 'Existing fixture']));
        $user->notifications()->first()->markAsRead();
        $before = DB::table('push_subscriptions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $notification = (array) DB::table('notifications')->sole();
        $migration->up();
        $after = DB::table('push_subscriptions')->orderBy('id')->get()->map(function ($row) {
            $this->assertNull($row->device_id);
            $values = (array) $row;
            unset($values['device_id']);

            return $values;
        })->all();
        $this->assertSame($before, $after);
        $this->assertSame($notification, (array) DB::table('notifications')->sole());
        $this->assertDatabaseCount('fcm_tokens', 0);
        $this->assertTrue(Schema::hasIndex('push_subscriptions', ['device_id']));
    }

    public function test_device_and_token_hash_uniqueness_and_user_foreign_key_cascade_are_enforced(): void
    {
        $first = User::factory()->create(['status' => 'active']);
        $second = User::factory()->create(['status' => 'active']);
        $token = $this->tokenFor($first, 'isolated-migration-fixture');
        foreach ([['device_id' => $token->device_id], ['token_hash' => $token->token_hash], ['user_id' => 999999999]] as $conflict) {
            $row = ['user_id' => $second->id, 'device_id' => (string) Str::uuid(), 'token' => 'opaque-fixture-ciphertext', 'token_hash' => hash('sha256', (string) Str::uuid()), 'endpoint_hash' => hash('sha256', 'fixture'), 'last_seen_at' => now()];
            try {
                // Savepoint keeps PostgreSQL usable after a deliberate constraint violation.
                DB::transaction(fn () => DB::table('fcm_tokens')->insert(array_replace($row, $conflict)));
                $this->fail('Expected a database constraint violation.');
            } catch (QueryException) {
                $this->assertDatabaseCount('fcm_tokens', 1);
            }
        }
        $this->assertTrue(Schema::hasIndex('fcm_tokens', ['device_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('fcm_tokens', ['token_hash'], 'unique'));
        $first->delete();
        $this->assertDatabaseCount('fcm_tokens', 0);
        $this->assertModelExists($second);
    }
}
