<?php

namespace App\Support;

final class SchoolBranding
{
    public const LOGO = 'icons/nasr-daem-logo.jpg';
    public const NAVY = '#101632';
    public const NAVY_LIGHT = '#1A2346';
    public const GOLD = '#E6C52A';
    public const GOLD_LIGHT = '#EFD75C';
    public const WHITE = '#FFFFFF';
    public const GRAY = '#F1F2F5';

    public static function logoDataUri(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path(self::LOGO)));
    }

    /** All installation icons use the approved logo, with white safe-area padding. */
    public static function iconPng(int $size): string
    {
        if (! in_array($size, [180, 192, 512], true)) {
            throw new \InvalidArgumentException('Unsupported school icon size.');
        }

        $source = imagecreatefromjpeg(public_path(self::LOGO));
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
