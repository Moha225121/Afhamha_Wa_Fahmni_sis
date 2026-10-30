<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class SchoolBranding
{
    public const LOGO = 'images/nasbia-school-logo.png';

    public const NAVY = '#132F3B';

    public const NAVY_LIGHT = '#1C4657';

    public const GOLD = '#008C95';

    public const GOLD_LIGHT = '#60D4CE';

    public const WHITE = '#FFFFFF';

    public const GRAY = '#F1F2F5';

    public static function logoDataUri(): string
    {
        $file = self::logoFile();
        $mime = getimagesize($file)['mime'];

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($file));
    }

    public static function logoUrl(): string
    {
        $logo = self::configuredLogo();

        return $logo !== null ? asset('storage/'.$logo) : asset(self::LOGO);
    }

    private static function configuredLogo(bool $rasterOnly = false): ?string
    {
        $logo = Schema::hasTable('settings') ? DB::table('settings')->where('key', 'school_logo')->value('value') : null;
        if (! is_string($logo) || $logo === '' || str_contains($logo, '..')
            || str_starts_with($logo, '/') || str_contains($logo, ':') || str_contains($logo, '\\')) {
            return null;
        }
        $root = realpath(Storage::disk('public')->path(''));
        $file = realpath(Storage::disk('public')->path($logo));
        if (! $root || ! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)
            || ! is_file($file) || filesize($file) > 2 * 1024 * 1024) {
            return null;
        }
        $image = @getimagesize($file);

        if (! $rasterOnly && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg'
            && (new \finfo(FILEINFO_MIME_TYPE))->file($file) === 'image/svg+xml') {
            return $logo;
        }

        return $image && in_array($image['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) ? $logo : null;
    }

    private static function logoFile(): string
    {
        $logo = self::configuredLogo(true);

        return $logo !== null ? Storage::disk('public')->path($logo) : public_path(self::LOGO);
    }

    /** All installation icons use the approved logo, with white safe-area padding. */
    public static function iconPng(int $size): string
    {
        if (! in_array($size, [180, 192, 512], true)) {
            throw new \InvalidArgumentException('Unsupported school icon size.');
        }

        $source = imagecreatefromstring(file_get_contents(self::logoFile()));
        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        $scale = ($size * .8) / max(imagesx($source), imagesy($source));
        $width = (int) round(imagesx($source) * $scale);
        $height = (int) round(imagesy($source) * $scale);
        imagecopyresampled($canvas, $source, (int) (($size - $width) / 2), (int) (($size - $height) / 2), 0, 0, $width, $height, imagesx($source), imagesy($source));

        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        return $png;
    }
}
