<?php

namespace App\Services;

use App\Support\SchoolBranding;
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
        $pdf->WriteHTML(view($view, array_merge($data, ['pdf' => true, 'pdfLogo' => SchoolBranding::logoDataUri()]))->render());

        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }
}
