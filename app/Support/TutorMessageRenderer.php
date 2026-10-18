<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class TutorMessageRenderer
{
    private readonly MarkdownConverter $markdown;

    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'max_delimiters_per_line' => 1000,
            'renderer' => ['soft_break' => "<br>\n"],
            'table' => ['wrap' => [
                'enabled' => true,
                'attributes' => [
                    'class' => 'tutor-table-scroll',
                    'tabindex' => '0',
                    'role' => 'region',
                    'aria-label' => 'جدول قابل للتمرير أفقيًا',
                ],
            ]],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $this->markdown = new MarkdownConverter($environment);

        // Explicit allowlist: no arbitrary HTML, styles, events, embeds or images.
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowRelativeLinks()
            ->allowElement('a', ['href', 'title'])
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->allowElement('div', ['class', 'tabindex', 'role', 'aria-label'])
            ->allowElement('ol', ['start'])
            ->allowElement('th', ['align'])
            ->allowElement('td', ['align'])
            // Escaped HTML and table markup can expand well beyond the source length.
            ->withMaxInputLength(1_000_000);

        foreach (['p', 'br', 'strong', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'li', 'blockquote', 'hr', 'pre', 'code', 'table', 'thead', 'tbody', 'tr'] as $tag) {
            $config = $config->allowElement($tag);
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function render(string $content): HtmlString
    {
        // HtmlString is created only after sanitization; stored source remains untouched.
        return new HtmlString($this->sanitizer->sanitize($this->markdown->convert($content)->getContent()));
    }
}
