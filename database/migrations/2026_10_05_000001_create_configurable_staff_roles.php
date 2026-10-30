<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique('name');
        });
        Schema::create('staff_role_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('staff_role_id')->constrained()->cascadeOnDelete();
            $t->string('permission');
            $t->json('scope')->nullable();
            $t->timestamps();
            $t->unique(['staff_role_id', 'permission']);
        });
        Schema::create('staff_role_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('staff_role_id')->constrained()->cascadeOnDelete();
            $t->json('scope')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'staff_role_id']);
        });
        foreach ([['Student Affairs Officer', ['students.view', 'students.create', 'students.update', 'student-guardians.manage']], ['Academic Follow-up Officer', ['academic.view', 'attendance.view', 'assignments.view']], ['Teacher Monitoring Officer', ['teacher-monitoring.view']], ['Parent Communication and Guardian Calls', ['parent-notifications.send', 'guardian-calls.manage']], ['Finance Notifications Officer', ['parent-notifications.send']], ['Announcements and Communications Officer', ['announcements.create', 'announcements.publish', 'announcements.broadcast']], ['Activities Coordinator', ['activities.manage']]] as [$name,$permissions]) {
            $id = DB::table('staff_roles')->insertGetId(['name' => $name, 'description' => 'قالب قابل للتعديل', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($permissions as $permission) {
                DB::table('staff_role_permissions')->insert(['staff_role_id' => $id, 'permission' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_role_assignments');
        Schema::dropIfExists('staff_role_permissions');
        Schema::dropIfExists('staff_roles');
    }
};
