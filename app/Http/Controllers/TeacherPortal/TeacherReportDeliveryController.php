<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Services\TeacherReportDeliveryService;
use Illuminate\Http\Request;

class TeacherReportDeliveryController extends Controller
{
    use InteractsWithTeacherScope;

    public function recipients(Request $request, int $report, TeacherReportDeliveryService $delivery)
    {
        return response()->json($delivery->recipients($this->teacher($request), $report))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, int $report, TeacherReportDeliveryService $delivery)
    {
        return response()->json($delivery->preview($this->teacher($request), $report, $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, int $report, TeacherReportDeliveryService $delivery)
    {
        return response()->json($delivery->send($this->teacher($request), $report, $request->all()))->header('Cache-Control', 'private, no-store');
    }
}
