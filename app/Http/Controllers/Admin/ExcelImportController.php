<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ExcelImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelImportController extends Controller
{
    public function create(string $type, ExcelImportService $imports): View
    { abort_unless(isset(ExcelImportService::TYPES[$type]), 404); return view('admin.imports.create', ['type' => $type, 'label' => ExcelImportService::TYPES[$type]]); }
    public function template(string $type): StreamedResponse
    {
        $templates = [
            'students' => [['الاسم', 'الرقم الدراسي', 'البريد', 'الصف', 'السنة الدراسية', 'تاريخ الميلاد', 'النوع', 'العنوان', 'ولي الأمر', 'هاتف ولي الأمر', 'ولي الأمر البريد'], ['أحمد محمد', '2026001', 'student@example.com', 'الصف الأول', '2026/2027', '', '', '', '', '', '']],
            'teachers' => [['الاسم', 'البريد', 'التخصص'], ['محمد علي', 'teacher@example.com', 'الرياضيات']],
            'subjects' => [['الرمز', 'الاسم', 'المرحلة', 'الوصف'], ['MATH-1', 'الرياضيات', 'المرحلة الابتدائية', '']],
            'classes' => [['الاسم', 'الشعبة', 'المرحلة', 'السنة الدراسية'], ['الصف الأول', 'أ', 'المرحلة الابتدائية', '2026/2027']],
            'stages' => [['الاسم'], ['المرحلة الابتدائية']],
            'parents' => [['الاسم', 'البريد', 'الهاتف', 'صلة القرابة'], ['ولي أمر أحمد', 'parent@example.com', '0910000000', 'الأب']],
        ];
        abort_unless(isset($templates[$type]), 404);
        return response()->streamDownload(function () use ($templates, $type) {
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'wb');
            foreach ($templates[$type] as $row) fputcsv($out, $row);
            fclose($out);
        }, 'قالب-استيراد-'.$type.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
    public function store(Request $request, string $type, ExcelImportService $imports): RedirectResponse
    { $request->validate(['file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240']]); $count = $imports->import($request->file('file'), $type); $destination = $type === 'stages' ? 'admin.classes.index' : 'admin.'.$type.'.index'; return redirect()->route($destination)->with('success', "تم استيراد {$count} سجل بنجاح."); }
}
