<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class RichTextStyleSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style', 'dir'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if ($attribute === 'dir') {
            return in_array(strtolower($value), ['rtl', 'ltr', 'auto'], true) ? strtolower($value) : null;
        }

        // Preserve the existing editor's alignment commands, without permitting arbitrary CSS or URLs.
        return preg_match('/(?:^|;)\s*text-align\s*:\s*(left|right|center|justify)\s*(?:;|$)/i', $value, $match)
            ? 'text-align:'.strtolower($match[1]) : null;
    }
}
