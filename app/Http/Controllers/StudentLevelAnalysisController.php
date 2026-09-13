<?php

namespace App\Http\Controllers;

use App\Exceptions\SmartTutorGatewayException;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Models\Student;
use App\Services\ParentPortalContext;
use App\Services\StudentLevelAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StudentLevelAnalysisController extends Controller
{
    use InteractsWithTeacherScope;

    public function __invoke(Request $request, Student $student, StudentLevelAnalysisService $service)
    {
        $audience = explode('.', $request->route()->getName())[0];
        $teacher = null;
        if ($audience === 'teacher') {
            $teacher = $this->teacher($request);
            abort_unless($this->ownsStudent($teacher, $student->id), 404);
        } elseif ($audience === 'parent') {
            $context = app(ParentPortalContext::class);
            $context->assertChild($context->guardian($request), $student);
        } else {
            abort_unless($audience === 'admin', 403);
        }

        $student->load(['user', 'classroom']);
        $snapshot = $service->snapshot($student, $teacher, $audience);
        $key = 'student-analysis:v1:'.$request->user()->id.':'.$student->id.':'.$audience.':'.hash('sha256', json_encode($snapshot));
        $report = Cache::get($key);
        $error = null;
        if ($request->isMethod('post') && ! $report && $service->hasEvidence($snapshot)) {
            $lock = Cache::lock($key.':lock', 100);
            if (! $lock->get()) {
                $error = 'التحليل قيد الإعداد. يرجى الانتظار قليلًا ثم إعادة تحميل الصفحة.';
            } else {
                try {
                    $report = Cache::get($key);
                    if (! $report) {
                        $report = ['text' => $service->generate($snapshot, $audience), 'generated_at' => now()->format('Y-m-d H:i')];
                        Cache::put($key, $report, now()->addHours(6));
                    }
                } catch (SmartTutorGatewayException $exception) {
                    $error = match ($exception->reason) {
                        SmartTutorGatewayException::NOT_CONFIGURED => 'خدمة التحليل غير مهيأة حاليًا. يرجى التواصل مع الإدارة.',
                        SmartTutorGatewayException::RATE_LIMITED => 'خدمة التحليل مشغولة حاليًا. يرجى المحاولة لاحقًا.',
                        default => 'تعذر إنشاء التحليل الآن. بيانات الطالب محفوظة ويمكنك المحاولة مجددًا.',
                    };
                } finally {
                    $lock->release();
                }
            }
        }

        return response()->view('shared.student-analysis', compact('student', 'audience', 'snapshot', 'report', 'error') + [
            'hasEvidence' => $service->hasEvidence($snapshot),
        ])->header('Cache-Control', 'private, no-store');
    }
}
