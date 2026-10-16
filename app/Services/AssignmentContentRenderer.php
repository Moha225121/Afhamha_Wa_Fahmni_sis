<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\HtmlString;

class AssignmentContentRenderer
{
    private const TAGS = ['p', 'div', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'sub', 'sup',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'hr'];

    private const OMIT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template',
        'form', 'input', 'button', 'select', 'textarea'];

    public function render(?string $content): HtmlString
    {
        $content = trim($content ?? '');
        // Recover legacy descriptions saved as escaped HTML, without decoding code examples inside HTML.
        for ($depth = 0; $depth < 2 && ! preg_match('/<\/?[a-z][^>]*>/i', $content)
            && preg_match('/&(?:amp;)?lt;\/?[a-z]/i', $content); $depth++) {
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (! preg_match('/<\/?[a-z][^>]*>/i', $content)) {
            return new HtmlString(nl2br(e($content)));
        }
        if (! class_exists(DOMDocument::class)) {
            $text = preg_replace('/<\/?(?:p|div|li|h[1-6]|br)\b[^>]*>/i', "\n", $content);
            return new HtmlString(nl2br(e(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$content.'</body></html>', LIBXML_NONET);
            $body = $document->getElementsByTagName('body')->item(0);
            return new HtmlString($body ? $this->children($body) : '');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function children(DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $html .= e($node->textContent);
            } elseif ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, self::OMIT, true)) continue;
                $children = $this->children($node);
                if ($tag === 'a') {
                    $href = trim($node->getAttribute('href'));
                    $safe = ! preg_match('/[\x00-\x20\x7f]/', $href) && (
                        preg_match('#^https?://#i', $href)
                        || (str_starts_with($href, '/') && ! str_starts_with($href, '//') && ! str_contains($href, '\\'))
                        || str_starts_with($href, '#')
                    );
                    $html .= $safe ? '<a href="'.e($href).'">'.$children.'</a>' : $children;
                    continue;
                }
                // Rebuild text formatting without editor attributes, styles, or event handlers.
                $html .= in_array($tag, self::TAGS, true)
                    ? '<'.$tag.'>'.(in_array($tag, ['br', 'hr'], true) ? '' : $children.'</'.$tag.'>')
                    : $children;
            }
        }
        return $html;
    }
}
