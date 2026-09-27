<?php
namespace App\Http\Controllers\SupervisorPortal\Concerns;
use App\Models\Student;
use Illuminate\Http\Request;
trait AuthorizesAssignedStudents { private function authorizeStudent(Request $request, Student $student): void { abort_unless($student->classroom_id && $request->user()->supervisedClassrooms()->whereKey($student->classroom_id)->exists(), 403); } }
