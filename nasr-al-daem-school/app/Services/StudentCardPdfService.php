<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class StudentCardPdfService
{
    public function download(string $view, array $data, string $filename)
    {
        $this->registerQrCodeAutoloader();
        $data = $this->attachQrImages($data);
        $directory = storage_path('app/private/pdf-temp');
        if (! is_dir($directory)) mkdir($directory, 0700, true);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P', 'tempDir' => $directory, 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true, 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 8]);
        $pdf->SetDirectionality('rtl');
        $pdf->WriteHTML(view($view, $data + ['pdf' => true, 'pdfLogo' => $this->logo()])->render());
        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }

    public function downloadBulk($students, string $filename)
    {
        $this->registerQrCodeAutoloader();
        $directory = storage_path('app/private/pdf-temp');
        if (! is_dir($directory)) mkdir($directory, 0700, true);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P', 'tempDir' => $directory, 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true, 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0]);
        $pdf->SetDirectionality('rtl');
        $logo = $this->logo();
        foreach ($students->chunk(8) as $pageIndex => $page) {
            if ($pageIndex > 0) $pdf->AddPage();
            $pageHtml = '';
            foreach ($page->values() as $index => $student) {
                $student->setAttribute('qr_image', $this->qrImage($student->verification_url));
                $html = view('admin.accounts.card-fragment', ['student' => $student, 'pdfLogo' => $logo, 'schoolBranding' => view()->shared('schoolBranding')])->render();
                $row = intdiv($index, 2); $column = $index % 2;
                $pageHtml .= '<div style="position:absolute;left:'.(8 + ($column * 101)).'mm;top:'.(8 + ($row * 68)).'mm;width:94mm;height:62mm">'.$html.'</div>';
            }
            $pdf->WriteHTML($pageHtml);
        }
        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }

    private function attachQrImages(array $data): array
    {
        if (isset($data['student']) && $data['student']->verification_url) {
            $data['student']->setAttribute('qr_image', $this->qrImage($data['student']->verification_url));
        }
        if (isset($data['students'])) {
            $data['students']->each(fn ($student) => $student->verification_url ? $student->setAttribute('qr_image', $this->qrImage($student->verification_url)) : null);
        }
        return $data;
    }

    private function qrImage(string $value): string
    {
        $qr = new \Mpdf\QrCode\QrCode($value, 'M');
        $png = (new \Mpdf\QrCode\Output\Png())->output($qr, 100);
        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function registerQrCodeAutoloader(): void
    {
        if (class_exists('Mpdf\\QrCode\\QrCode')) return;
        $source = base_path('vendor/mpdf/qrcode/src');
        if (! is_dir($source)) return;
        spl_autoload_register(static function (string $class) use ($source): void {
            $prefix = 'Mpdf\\QrCode\\';
            if (! str_starts_with($class, $prefix)) return;
            $file = $source.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) require_once $file;
        });
    }

    private function logo(): ?string
    {
        $logo = DB::table('settings')->where('key', 'school_logo')->value('value');
        $root = realpath(storage_path('app/public')); $path = $logo ? realpath(storage_path('app/public/'.$logo)) : false;
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) return null;
        $mime = mime_content_type($path);
        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) ? 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path)) : null;
    }
}
