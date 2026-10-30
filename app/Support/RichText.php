<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;

class RichText
{
    public function __construct(private readonly ?ParserInterface $parser = null) {}

    public function sanitize(mixed $html): string
    {
        if (! is_string($html) || $html === '') {
            return '';
        }
        for ($depth = 0; $depth < 2 && ! preg_match('/<\/?[a-z][^>]*>/i', $html)
            && preg_match('/&(?:amp;)?lt;\/?[a-z]/i', $html); $depth++) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Render existing content safely without migrating it or silently applying the library's 20 KB truncation.
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(strlen($html))
            ->withAttributeSanitizer(new RichTextStyleSanitizer);
        foreach (['p', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ul', 'ol', 'li', 'br', 'blockquote', 'pre', 'code', 'sub', 'sup', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'hr'] as $tag) {
            $config = $config->allowElement($tag, ['style', 'dir']);
        }
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'img', 'video', 'audio', 'link', 'meta', 'base'] as $tag) {
            $config = $config->dropElement($tag);
        }

        return (new HtmlSanitizer($config, $this->parser))->sanitize($html);
    }
}
