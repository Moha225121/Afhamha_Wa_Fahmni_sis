<?php

namespace App\Services;

use App\Support\SchoolBranding;
use Illuminate\Support\Collection;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Mpdf\QrCode\Output\Png;
use Mpdf\QrCode\QrCode;

class StudentCardPdfService
{
    public const CARD_WIDTH_MM = 84;

    public const CARD_HEIGHT_MM = 53;

    public const CARDS_PER_PAGE = 10;

    private const PDF_SPACER = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWP4//8/AAX+Av5Y8msOAAAAAElFTkSuQmCC';

    public function download(array $data, string $filename)
    {
        $this->registerQrCodeAutoloader();
        $data = $this->attachQrImages($data);
        $pdf = $this->newPdf();
        $student = $data['student'];
        $html = $this->pdfCardHtml($student);
        $pdf->WriteFixedPosHTML($html, 12, 8, self::CARD_WIDTH_MM, self::CARD_HEIGHT_MM, 'hidden');

        return $this->response($pdf, $filename);
    }

    public function downloadBulk(Collection $students, string $filename)
    {
        $this->registerQrCodeAutoloader();
        $students = $this->attachQrImages(['students' => $students])['students'];
        $pdf = $this->newPdf();

        foreach ($students->chunk(self::CARDS_PER_PAGE) as $pageIndex => $page) {
            if ($pageIndex > 0) {
                $pdf->AddPage('P', 'A4');
            }

            foreach ($page->values() as $index => $student) {
                $column = $index % 2;
                $row = intdiv($index, 2);
                $x = $column === 0 ? 12 : 114;
                $y = 8 + ($row * 57);

                $pdf->WriteFixedPosHTML(
                    $this->pdfCardHtml($student),
                    $x,
                    $y,
                    self::CARD_WIDTH_MM,
                    self::CARD_HEIGHT_MM,
                    'hidden'
                );
            }
        }

        return $this->response($pdf, $filename);
    }

    public function preparePrintableCards(Collection $students): void
    {
        $this->registerQrCodeAutoloader();
        $students->each(function ($student): void {
            if ($student->verification_url) {
                $student->setAttribute('qr_image', $this->qrImage($student->verification_url));
            }

            $student->setAttribute('photo_image', $this->studentPhotoUrl($student));
        });
    }

    private function newPdf(): Mpdf
    {
        $directory = storage_path('app/private/pdf-temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'tempDir' => $directory,
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
        ]);
        $pdf->SetDirectionality('rtl');
        $pdf->SetAutoPageBreak(false, 0);

        return $pdf;
    }

    private function response(Mpdf $pdf, string $filename)
    {
        return response($pdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function pdfCardHtml($student): string
    {
        return view('admin.accounts.card-pdf-fragment', [
            'student' => $student,
            'pdfLogo' => $this->logo(),
            'pdfSpacer' => self::PDF_SPACER,
            'schoolBranding' => view()->shared('schoolBranding'),
        ])->render();
    }

    private function attachQrImages(array $data): array
    {
        if (isset($data['student']) && $data['student']->verification_url) {
            $data['student']->setAttribute('qr_image', $this->qrImage($data['student']->verification_url));
            $data['student']->setAttribute('photo_image', $this->studentPhotoDataUri($data['student']));
        }
        if (isset($data['students'])) {
            $data['students']->each(function ($student): void {
                if ($student->verification_url) {
                    $student->setAttribute('qr_image', $this->qrImage($student->verification_url));
                }
                $student->setAttribute('photo_image', $this->studentPhotoDataUri($student));
            });
        }

        return $data;
    }

    private function photoPath($student): ?string
    {
        $relative = $student->user?->avatar_path;
        $root = realpath(storage_path('app/public'));
        $path = $relative ? realpath(storage_path('app/public/'.$relative)) : false;

        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) ? $path : null;
    }

    private function studentPhotoDataUri($student): ?string
    {
        $path = $this->photoPath($student);

        return $path ? 'data:'.mime_content_type($path).';base64,'.base64_encode(file_get_contents($path)) : null;
    }

    private function studentPhotoUrl($student): ?string
    {
        return $this->photoPath($student) ? asset('storage/'.$student->user->avatar_path) : null;
    }

    private function qrImage(string $value): string
    {
        $qr = new QrCode($value, 'M');
        $png = (new Png)->output($qr, 100);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function registerQrCodeAutoloader(): void
    {
        if (class_exists('Mpdf\\QrCode\\QrCode')) {
            return;
        }

        $source = base_path('vendor/mpdf/qrcode/src');
        if (! is_dir($source)) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($source): void {
            $prefix = 'Mpdf\\QrCode\\';
            if (! str_starts_with($class, $prefix)) {
                return;
            }
            $file = $source.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    private function logo(): string
    {
        return SchoolBranding::logoDataUri();
    }
}
