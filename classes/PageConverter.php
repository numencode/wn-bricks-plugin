<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Config;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Str;
use Winter\Storm\Exception\ApplicationException;

/**
 * Turns the HTML content of a page (Winter.Pages' rich editor) into a list of bricks (bricks:convert-pages).
 *
 * Each top-level element becomes:
 *
 * - a snippet <figure data-snippet data-component data-property-*> → the component brick of that component, with the
 *   properties as its values
 * - with $split (the default):
 *   - <h2>, <h3>, <h4> with plain text (an id becomes the anchor) → a "heading" brick
 *   - <hr> → a "divider" brick
 *   - a <p> or <figure> holding only an image from the media library (+ a <figcaption>) → an "image" brick
 * - everything else, in runs between those → one "text" brick per run (empty paragraphs at its ends dropped)
 *
 * Only the types the theme has (and with the fields used here) are created; the rest stays in text bricks. Bricks are
 * stored like the editor stores them: the fields, then "_group".
 */
class PageConverter
{
    /**
     * @var array<int, string>
     */
    protected array $warnings = [];

    /**
     * @param array<string, BrickType> $types The theme's brick types (BrickRegistry::all())
     * @param bool $split Make heading, image and divider bricks (else only text and component bricks)
     */
    public function __construct(protected array $types, protected bool $split = true)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function convert(string $html): array
    {
        $this->warnings = [];

        if (trim($html) === '') {
            return [];
        }

        if (!$this->canMake('text', 'content')) {
            throw new ApplicationException('The theme has no "text" brick type with a "content" field to put the content in.');
        }

        $bricks = [];
        $run = [];

        foreach ($this->topLevelNodes($html) as $node) {
            $brick = $node instanceof DOMElement ? $this->brickOf($node) : null;

            if ($brick === null) {
                $run[] = $node;
                continue;
            }

            $this->flush($run, $bricks);
            $bricks[] = $brick;
        }

        $this->flush($run, $bricks);

        return $bricks;
    }

    /**
     * Problems found by the last convert(), e.g. snippets that have no brick type.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<int, DOMNode>
     */
    protected function topLevelNodes(string $html): array
    {
        $dom = new DOMDocument();

        // The meta tag makes libxml read UTF-8; the errors are about HTML5 elements (figure, ...) it doesn't know
        $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
            . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        $body = $dom->getElementsByTagName('body')->item(0);

        return $body ? iterator_to_array($body->childNodes) : [];
    }

    /**
     * The brick an element becomes on its own, or null when it belongs to a text brick.
     *
     * @return array<string, mixed>|null
     */
    protected function brickOf(DOMElement $element): ?array
    {
        $tag = strtolower($element->tagName);

        if ($tag === 'figure' && ($element->hasAttribute('data-snippet') || $element->hasAttribute('data-component'))) {
            return $this->componentBrick($element);
        }

        if ($this->snippetsIn($element)) {
            $this->warnings[] = 'A snippet inside other HTML (e.g. a table) stays in a text brick, where it isn\'t rendered.';
        }

        if (!$this->split) {
            return null;
        }

        return match ($tag) {
            'h2', 'h3', 'h4' => $this->headingBrick($element),
            'hr'             => $this->canMake('divider') ? ['_group' => 'divider'] : null,
            'p', 'figure'    => $this->imageBrick($element),
            default          => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function componentBrick(DOMElement $figure): ?array
    {
        $snippet = $figure->getAttribute('data-snippet');
        $class = ltrim($figure->getAttribute('data-component'), '\\');
        $type = $this->componentType($snippet, $class);

        if (!$type) {
            $this->warnings[] = sprintf(
                'The snippet "%s"%s has no brick type; it stays in a text brick, where it isn\'t rendered.',
                $snippet,
                $class === '' ? '' : " ({$class})",
            );

            return null;
        }

        // Like Winter.Pages: HTML attribute names are lowercase, the component's properties may not be
        $properties = [];

        foreach (array_keys(ComponentBricks::fields($type)) as $name) {
            $properties[strtolower((string) $name)] = (string) $name;
        }

        $brick = [];

        foreach ($figure->attributes as $attribute) {
            if (!str_starts_with($attribute->name, 'data-property-')) {
                continue;
            }

            $property = substr($attribute->name, strlen('data-property-'));

            if (!isset($properties[strtolower($property)])) {
                $this->warnings[] = "The property \"{$property}\" of the snippet \"{$snippet}\" is not a field of the brick \"{$type->code}\"; it was left out.";
                continue;
            }

            $brick[$properties[strtolower($property)]] = $attribute->value;
        }

        return $brick + ['_group' => $type->code];
    }

    /**
     * The component brick type of a snippet: its snippet brick, else a brick type of that component (an explicit
     * definition before a snippet brick).
     */
    protected function componentType(string $snippet, string $class): ?BrickType
    {
        $code = ComponentBricks::SNIPPET_PREFIX . Str::kebab($snippet);

        if ($snippet !== '' && isset($this->types[$code]) && $this->types[$code]->isComponent()
            && ($class === '' || ltrim((string) $this->types[$code]->component, '\\') === $class)) {
            return $this->types[$code];
        }

        if ($class === '') {
            return null;
        }

        $matches = array_filter($this->types, fn (BrickType $type) => $type->isComponent() && ltrim((string) $type->component, '\\') === $class);

        // An explicit definition of the component before its snippet brick
        foreach ($matches as $matchCode => $type) {
            if (!str_starts_with((string) $matchCode, ComponentBricks::SNIPPET_PREFIX)) {
                return $type;
            }
        }

        return $matches ? reset($matches) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function headingBrick(DOMElement $heading): ?array
    {
        // Plain text only: links, formatting or attributes such as a style would be lost in a heading brick
        if (!$this->canMake('heading', 'text') || $this->hasElements($heading) || $this->hasAttributesExcept($heading, ['id'])) {
            return null;
        }

        $text = $this->text($heading);

        if ($text === '') {
            return null;
        }

        $brick = ['text' => $text];

        if ($this->hasField('heading', 'level')) {
            $brick['level'] = strtolower($heading->tagName);
        }

        if ($heading->getAttribute('id') !== '') {
            $brick[FieldBuilder::ANCHOR_FIELD] = $heading->getAttribute('id');
        }

        return $brick + ['_group' => 'heading'];
    }

    /**
     * A <p> with only an image, or a <figure> with an image and an optional <figcaption>.
     *
     * @return array<string, mixed>|null
     */
    protected function imageBrick(DOMElement $element): ?array
    {
        if (!$this->canMake('image', 'image')) {
            return null;
        }

        $image = null;
        $caption = null;

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && trim(str_replace("\u{a0}", ' ', $child->textContent)) === '') {
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->tagName) === 'img' && $image === null) {
                $image = $child;
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption' && $caption === null
                && strtolower($element->tagName) === 'figure' && !$this->hasElements($child)) {
                $caption = $this->text($child);
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->tagName) === 'br') {
                continue;
            }

            return null;
        }

        $path = $image ? $this->mediaPath($image->getAttribute('src')) : null;

        if ($path === null) {
            return null;
        }

        $brick = ['image' => $path];

        if ($this->hasField('image', 'alt') && trim($image->getAttribute('alt')) !== '') {
            $brick['alt'] = trim($image->getAttribute('alt'));
        }

        if ($this->hasField('image', 'caption') && (string) $caption !== '') {
            $brick['caption'] = $caption;
        }

        return $brick + ['_group' => 'image'];
    }

    /**
     * The media library path of an image URL (/storage/app/media/a/b.jpg → /a/b.jpg), or null for other images.
     */
    protected function mediaPath(string $src): ?string
    {
        $media = (string) Config::get('cms.storage.media.path', '/storage/app/media');
        $mediaPath = '/' . trim((string) (parse_url($media, PHP_URL_PATH) ?? ''), '/');
        $host = parse_url($src, PHP_URL_HOST);

        if ($host !== null && $host !== false && !in_array($host, array_filter([
            parse_url((string) Config::get('app.url'), PHP_URL_HOST),
            parse_url($media, PHP_URL_HOST),
        ]), true)) {
            return null;
        }

        $path = rawurldecode((string) parse_url($src, PHP_URL_PATH));

        return $mediaPath !== '/' && str_starts_with($path, $mediaPath . '/') ? substr($path, strlen($mediaPath)) : null;
    }

    /**
     * Adds the collected nodes as a text brick (without empty paragraphs and whitespace at its ends).
     *
     * @param array<int, DOMNode> $run
     * @param array<int, array<string, mixed>> $bricks
     */
    protected function flush(array &$run, array &$bricks): void
    {
        while ($run && $this->isEmpty($run[0])) {
            array_shift($run);
        }

        while ($run && $this->isEmpty($run[count($run) - 1])) {
            array_pop($run);
        }

        if ($run) {
            $html = '';

            foreach ($run as $node) {
                $html .= $node->ownerDocument->saveHTML($node);
            }

            $bricks[] = ['content' => trim($html), '_group' => 'text'];
        }

        $run = [];
    }

    /**
     * Whitespace, <br>, and paragraphs or divs with nothing visible (e.g. <p><br></p>, <p>&nbsp;</p>).
     */
    protected function isEmpty(DOMNode $node): bool
    {
        if ($node instanceof DOMText) {
            return trim(str_replace("\u{a0}", ' ', $node->textContent)) === '';
        }

        if (!$node instanceof DOMElement) {
            return $node->nodeType === XML_COMMENT_NODE;
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'br') {
            return true;
        }

        if (!in_array($tag, ['p', 'div'], true) || trim(str_replace("\u{a0}", ' ', $node->textContent)) !== '') {
            return false;
        }

        foreach ($node->getElementsByTagName('*') as $child) {
            if (strtolower($child->tagName) !== 'br') {
                return false;
            }
        }

        return true;
    }

    protected function snippetsIn(DOMElement $element): bool
    {
        foreach ($element->getElementsByTagName('figure') as $figure) {
            if ($figure->hasAttribute('data-snippet')) {
                return true;
            }
        }

        return false;
    }

    protected function hasElements(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $allowed
     */
    protected function hasAttributesExcept(DOMElement $element, array $allowed): bool
    {
        foreach ($element->attributes as $attribute) {
            if (!in_array(strtolower($attribute->name), $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text of an element, with whitespace (also non-breaking spaces) collapsed.
     */
    protected function text(DOMElement $element): string
    {
        return trim((string) preg_replace('/[\s\x{a0}]+/u', ' ', $element->textContent));
    }

    /**
     * Whether the theme has the (non-component) type, with the field when given.
     */
    protected function canMake(string $code, ?string $field = null): bool
    {
        return isset($this->types[$code]) && !$this->types[$code]->isComponent()
            && ($field === null || $this->hasField($code, $field));
    }

    protected function hasField(string $code, string $field): bool
    {
        return isset($this->types[$code]->fields[$field]);
    }
}
