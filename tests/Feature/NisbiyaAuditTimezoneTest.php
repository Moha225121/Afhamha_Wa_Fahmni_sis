<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NisbiyaAuditTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_audit_timestamps_are_consistent_with_utc_application_time_on_a_tripoli_postgresql_server(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL timezone regression.');
        }
        DB::statement("SET LOCAL TIME ZONE 'Africa/Tripoli'");
        $this->travelTo('2026-10-29 23:30:00');
        $oldId = DB::table('audit_logs')->insertGetId(['action' => 'old', 'module' => 'qa', 'created_at' => '2026-01-01 10:15:00']);
        $record = AuditLog::create(['action' => 'created', 'module' => 'qa']);
        $this->assertSame('2026-10-29 23:30:00', $record->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 10:15:00', DB::table('audit_logs')->where('id', $oldId)->value('created_at'));
    }
}
