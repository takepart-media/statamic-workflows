<?php

namespace TakepartMedia\Workflows\Translation;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Reads the plain-language service's answer back into its sections — STRICTLY.
 *
 * What was sent is `<h1>title</h1>` followed by one
 * `<section data-wf-leaf="N" data-wf-type="…">` per text leaf. The answer has to
 * carry exactly those sections again, because each one is written into the field
 * it came from (PlainLanguageLeafDocument::apply()). Anything that makes that
 * mapping ambiguous fails the whole answer, and nothing is written:
 *
 *   - a section that was sent and is missing;
 *   - a section with a number that was never sent;
 *   - a section that is empty;
 *   - the same number twice, with another section in between (two adjacent
 *     pieces of one section are joined — a service splitting a long section in
 *     two has not lost anything);
 *   - content outside the sections, other than the leading `<h1>` and whitespace.
 *
 * Sections in a different order are fine: they are matched by number. That is
 * reported as a warning so a log reader can see it happened.
 */
class PlainLanguageSections
{
    /**
     * @param  array<int, string>  $blocks  the answer's HTML blocks, in order
     * @param  array<int, int>  $expected  the leaf numbers that were sent
     * @return array{title: string|null, sections: array<int, string>, errors: array<int, string>, warnings: array<int, string>}
     */
    public function parse(array $blocks, array $expected): array
    {
        $result = ['title' => null, 'sections' => [], 'errors' => [], 'warnings' => []];
        $expected = array_values(array_map('intval', $expected));

        [$document, $root] = self::load(implode("\n", array_map('strval', $blocks)));

        if ($root === null) {
            $result['errors'][] = 'answer is not parseable HTML';

            return $result;
        }

        $order = [];
        $previous = null;

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                if (trim((string) $node->textContent) !== '') {
                    $result['errors'][] = 'text outside sections: '.self::excerpt((string) $node->textContent);
                }

                continue;
            }

            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->nodeName);

            if ($tag === 'h1' && $order === [] && $result['title'] === null) {
                $title = trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags(self::innerHtml($document, $node)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $result['title'] = $title === '' ? null : $title;

                continue;
            }

            if ($tag !== 'section' || ! $node->hasAttribute('data-wf-leaf')) {
                $result['errors'][] = "content outside sections: <{$tag}> ".self::excerpt((string) $node->textContent);

                continue;
            }

            $raw = trim($node->getAttribute('data-wf-leaf'));
            $n = ctype_digit($raw) ? (int) $raw : null;

            if ($n === null || ! in_array($n, $expected, true)) {
                $result['errors'][] = "unknown section [{$raw}]";
                $previous = null;

                continue;
            }

            $inner = self::innerHtml($document, $node);

            if (array_key_exists($n, $result['sections'])) {
                if ($previous === $n) {
                    $result['sections'][$n] .= "\n".$inner;

                    continue;
                }

                $result['errors'][] = "section [{$n}] appears twice, with other content in between";

                continue;
            }

            $result['sections'][$n] = $inner;
            $order[] = $n;
            $previous = $n;
        }

        foreach ($expected as $n) {
            if (! array_key_exists($n, $result['sections'])) {
                $result['errors'][] = "section [{$n}] is missing";
            }
        }

        foreach ($result['sections'] as $n => $html) {
            if (trim(strip_tags($html)) === '' && ! str_contains($html, '<img')) {
                $result['errors'][] = "section [{$n}] is empty";
            }
        }

        $sentOrder = array_values(array_filter($expected, fn ($n) => in_array($n, $order, true)));

        if ($order !== $sentOrder) {
            $result['warnings'][] = 'sections came back in a different order: '.implode(',', $order);
        }

        return $result;
    }

    /**
     * Parse an HTML fragment as UTF-8 into a container element.
     *
     * The XML declaration is what makes libxml read UTF-8 (without it every umlaut
     * breaks), and the container keeps top-level text that NOIMPLIED would drop —
     * the same two lessons LegacyPlainLanguageDocument::chunks() records.
     *
     * @return array{0: DOMDocument, 1: DOMElement|null}
     */
    public static function load(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="wf-plain-language-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return [$document, $child];
            }
        }

        return [$document, null];
    }

    public static function innerHtml(DOMDocument $document, DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= (string) $document->saveHTML($child);
        }

        return $html;
    }

    private static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('~\s+~u', ' ', $text));

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 59).'…' : $text;
    }
}
