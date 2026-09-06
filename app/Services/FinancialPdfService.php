<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class FinancialPdfService
{
    public function download(string $view, array $data, string $filename)
    {
        $directory = storage_path('app/private/pdf-temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $directory, 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $logo = DB::table('settings')->where('key', 'school_logo')->value('value');
        $root = realpath(storage_path('app/public'));
        $path = $logo ? realpath(storage_path('app/public/'.$logo)) : false;
        $pdfLogo = null;
        if ($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path)) {
            $mime = mime_content_type($path);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $pdfLogo = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
            }
        }
        $pdf->WriteHTML(view($view, $data + ['pdf' => true, 'pdfLogo' => $pdfLogo])->render());

        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }
}
