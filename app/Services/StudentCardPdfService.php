<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
                $student->setAttribute('photo_image', $this->studentPhoto($student));
                $html = view('admin.accounts.card-fragment', ['student' => $student, 'pdfLogo' => $logo, 'schoolBranding' => view()->shared('schoolBranding')])->render();
                $row = intdiv($index, 2); $column = $index % 2;
                $pageHtml .= '<div style="position:absolute;left:'.(8 + ($column * 101)).'mm;top:'.(8 + ($row * 68)).'mm;width:94mm;height:62mm">'.$html.'</div>';
            }
            $pdf->WriteHTML($pageHtml);
        }
        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }

    public function preparePrintableCards($students): void
    {
        $this->registerQrCodeAutoloader();
        $students->each(function ($student): void {
            if ($student->verification_url) {
                $student->setAttribute('qr_image', $this->qrImage($student->verification_url));
            }
            if ($student->user?->avatar_path) {
                $thumbnail = $this->studentPhotoThumbnail($student);
                if ($thumbnail) $student->setAttribute('photo_image', asset('storage/'.$thumbnail));
            }
        });
    }

    private function attachQrImages(array $data): array
    {
        if (isset($data['student']) && $data['student']->verification_url) {
            $data['student']->setAttribute('qr_image', $this->qrImage($data['student']->verification_url));
        }
        if (isset($data['student'])) {
            $data['student']->setAttribute('photo_image', $this->studentPhoto($data['student']));
        }
        if (isset($data['students'])) {
            $data['students']->each(function ($student): void {
                if ($student->verification_url) $student->setAttribute('qr_image', $this->qrImage($student->verification_url));
                $student->setAttribute('photo_image', $this->studentPhoto($student));
            });
        }
        return $data;
    }

    private function studentPhoto($student): ?string
    {
        $thumbnail = $this->studentPhotoThumbnail($student);
        if (! $thumbnail) return null;
        return 'data:image/jpeg;base64,'.base64_encode(Storage::disk('public')->get($thumbnail));
    }

    private function studentPhotoThumbnail($student): ?string
    {
        $relative = $student->user?->avatar_path;
        $root = realpath(Storage::disk('public')->path(''));
        $path = $relative ? realpath(Storage::disk('public')->path($relative)) : false;
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) return null;

        $thumbnail = 'student-card-thumbnails/'.hash('sha256', $relative.'|'.filemtime($path).'|'.filesize($path)).'.jpg';
        $disk = Storage::disk('public');
        if ($disk->exists($thumbnail)) return $thumbnail;
        if (! function_exists('imagecreatetruecolor')) return null;

        $image = match (mime_content_type($path)) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (! $image) return null;

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 1 || $height < 1) return null;
            $targetWidth = 260;
            $targetHeight = 300;
            $cropWidth = min($width, (int) round($height * $targetWidth / $targetHeight));
            $cropHeight = min($height, (int) round($width * $targetHeight / $targetWidth));
            $cardImage = imagecreatetruecolor($targetWidth, $targetHeight);
            if (! $cardImage) return null;
            imagefill($cardImage, 0, 0, imagecolorallocate($cardImage, 255, 255, 255));
            imagecopyresampled(
                $cardImage, $image, 0, 0,
                (int) (($width - $cropWidth) / 2), (int) (($height - $cropHeight) / 2),
                $targetWidth, $targetHeight, $cropWidth, $cropHeight,
            );
            ob_start();
            imagejpeg($cardImage, null, 72);
            $jpeg = ob_get_clean();
            imagedestroy($cardImage);
            return $jpeg && $disk->put($thumbnail, $jpeg) ? $thumbnail : null;
        } finally {
            imagedestroy($image);
        }
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
