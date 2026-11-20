<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Cms\Classes\Controller;
use Cms\Classes\Partial;
use Config;
use Log;
use Str;
use Winter\Pages\Classes\Page;

/**
 * Renders bricks on the frontend.
 *
 * Markup of a brick: the theme partial bricks/<code>.htm, else the type's own <code>.htm, rendered with the brick's
 * values plus `brick` = {code, index, field}. Component bricks render their component: the one ComponentBricks added
 * to the page for a static page's field, else one created while rendering (e.g. a blog post's bricks:
 * renderBricks(post.bricks, 'post')) – its onRun() runs then, but its AJAX handlers can't be reached.
 * Bricks of unknown types are skipped (a warning is logged in debug mode).
 */
class BrickRenderer
{
    public const DEFAULT_FIELD = 'bricks';

    /**
     * @var array<string, array<string, array<string, mixed>>> Bricks fields (name => definition) per layout file
     */
    protected static array $fieldNames = [];

    /**
     * Components created while rendering, per controller: alias => true.
     *
     * @var \WeakMap<Controller, array<string, bool>>|null
     */
    protected static ?\WeakMap $created = null;

    public function __construct(protected Controller $controller)
    {
    }

    /**
     * Twig: renderBricks() / renderBricks('aside') renders a bricks field of the current static page;
     * renderBricks(list) / renderBricks(list, 'name') renders a list, e.g. a model's (renderBricks(post.bricks, 'post'));
     * the name keeps its component bricks apart from the page's own ("bricks" = the page's field).
     *
     * @param string|array<int, array<string, mixed>>|null $bricks
     */
    public static function renderBricksFunction(string|array|null $bricks = null, ?string $name = null): string
    {
        if (!$controller = Controller::getController()) {
            return '';
        }

        $renderer = new static($controller);

        return is_array($bricks)
            ? $renderer->renderList($bricks, $name ?? self::DEFAULT_FIELD)
            : $renderer->renderField($bricks ?? self::DEFAULT_FIELD);
    }

    /**
     * Twig: renderBrick(brick, loop.index0) renders one brick of the page's "bricks" field (or of `field`).
     *
     * @param array<string, mixed> $brick
     */
    public static function renderBrickFunction(array $brick, int $index = 0, string $field = self::DEFAULT_FIELD): string
    {
        $controller = Controller::getController();

        return $controller ? (new static($controller))->renderBrick($brick, $index, $field) : '';
    }

    /**
     * Renders a bricks field of the current static page.
     */
    public function renderField(string $field = self::DEFAULT_FIELD): string
    {
        $staticPage = $this->controller->getPage()?->apiBag['staticPage'] ?? null;

        if (!$staticPage instanceof Page) {
            return '';
        }

        return $this->renderList(static::bricks($staticPage, $field), $field, static::isPreview($this->controller));
    }

    /**
     * Whether the request is Winter.Pages' preview of the page being edited (its URL /winter.pages/preview/<form>).
     */
    public static function isPreview(Controller $controller): bool
    {
        return Str::startsWith((string) $controller->getPage()?->url, '/winter.pages/preview/');
    }

    /**
     * A rendered brick between invisible markers, so the editor (assets/js/bricks.js) can scroll the preview to it and
     * highlight it: data-bricks-preview="<field>:<index>" before, data-bricks-preview-end after.
     */
    public static function previewMarkers(string $html, string $field, int $index): string
    {
        return sprintf(
            '<span data-bricks-preview="%s:%d" hidden></span>%s<span data-bricks-preview-end hidden></span>',
            e($field),
            $index,
            $html,
        );
    }

    /**
     * @param array<int, mixed> $bricks
     */
    public function renderList(array $bricks, string $field = self::DEFAULT_FIELD, bool $markers = false): string
    {
        $html = '';

        foreach (array_values($bricks) as $index => $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $brickHtml = $this->renderBrick($brick, $index, $field);

            // In the editor's preview, bricks that show something get markers (nothing for hidden or empty bricks)
            $html .= $markers && trim($brickHtml) !== '' ? static::previewMarkers($brickHtml, $field, $index) : $brickHtml;
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $brick
     */
    /**
     * A brick's anchor as an HTML id: a slug (e.g. "Our team" → "our-team"), '' when empty.
     */
    public static function anchor(mixed $value): string
    {
        return is_string($value) ? Str::slug($value) : '';
    }

    /**
     * A brick's CSS classes: only letters, digits, "-" and "_" per class, separated by single spaces.
     */
    public static function cssClass(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $classes = array_map(fn (string $class) => preg_replace('/[^A-Za-z0-9_-]/', '', $class), preg_split('/\s+/', $value) ?: []);

        return implode(' ', array_unique(array_filter($classes, 'strlen')));
    }

    /**
     * Whether a brick is hidden on the website ("_hidden", set with the eye button in the editor).
     *
     * @param array<string, mixed> $brick
     */
    public static function isHidden(array $brick): bool
    {
        return !empty($brick[FieldBuilder::HIDDEN_FIELD]);
    }

    public function renderBrick(array $brick, int $index = 0, string $field = self::DEFAULT_FIELD): string
    {
        // Hidden in the editor (the eye button): kept, but not shown
        if (static::isHidden($brick)) {
            return '';
        }

        $code = (string) ($brick['_group'] ?? '');
        $type = BrickRegistry::instance()->get($code, $this->controller->getTheme());

        if (!$type) {
            $this->warn(sprintf('unknown brick type "%s" (field "%s", index %d) skipped', $code, $field, $index));

            return '';
        }

        $anchor = static::anchor($brick[FieldBuilder::ANCHOR_FIELD] ?? '');
        $class = static::cssClass($brick[FieldBuilder::CLASS_FIELD] ?? '');

        if ($type->isComponent()) {
            $html = $this->renderComponent(ComponentBricks::alias($field, $index), $type, $brick);

            // The component's markup can't use them, so a wrapper carries the anchor and CSS class (only when set)
            return ($anchor === '' && $class === '') || trim($html) === '' ? $html : sprintf(
                '<div%s%s>%s</div>',
                $anchor === '' ? '' : ' id="' . e($anchor) . '"',
                $class === '' ? '' : ' class="' . e($class) . '"',
                $html,
            );
        }

        $vars = array_diff_key($brick, array_flip(BrickType::RESERVED_FIELDS));
        $vars['brick'] = [
            'code'   => $code,
            'index'  => $index,
            'field'  => $field,
            'id'     => (string) ($brick[FieldBuilder::ID_FIELD] ?? ''),
            'anchor' => $anchor,
            'class'  => $class,
        ];

        // Check the theme partial first: renderPartial() keeps the merged variables when it returns false for a
        // missing partial, so the next brick (and the page) would see this brick's values
        if (Partial::loadCached($this->controller->getTheme(), 'bricks/' . $code)) {
            return (string) $this->controller->renderPartial('bricks/' . $code, $vars);
        }

        return $type->markupPath !== null ? $this->renderFile($type->markupPath, $vars) : '';
    }

    /**
     * The bricks stored in a field of a static page (its viewBag, in the visitor's locale).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function bricks(Page $staticPage, string $field): array
    {
        $bricks = $staticPage->viewBag[$field] ?? [];

        return is_array($bricks) ? array_values(array_filter($bricks, 'is_array')) : [];
    }

    /**
     * The names of the layout's bricks fields ({variable type="bricks" name="..."}).
     *
     * @return array<int, string>
     */
    public static function fieldNames(Page $staticPage): array
    {
        return array_keys(static::fieldConfigs($staticPage));
    }

    /**
     * The names of the layout's bricks fields with a shared structure (translation="fields").
     *
     * @return array<int, string>
     */
    public static function sharedFieldNames(Page $staticPage): array
    {
        return array_keys(array_filter(static::fieldConfigs($staticPage), [FieldBuilder::class, 'isShared']));
    }

    /**
     * The layout's bricks fields: name => definition (cached per layout file and modification time).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fieldConfigs(Page $staticPage): array
    {
        $layout = $staticPage->getLayoutObject();

        if (!$layout) {
            return [];
        }

        $key = $layout->getFilePath() . '@' . $layout->mtime;

        if (!isset(static::$fieldNames[$key])) {
            static::$fieldNames[$key] = [];

            foreach ($staticPage->listLayoutSyntaxFields() as $name => $config) {
                if (($config['type'] ?? null) === PagesIntegration::FIELD_TYPE) {
                    static::$fieldNames[$key][(string) $name] = (array) $config;
                }
            }
        }

        return static::$fieldNames[$key];
    }

    /**
     * The page's component of a static page's component brick, else a component created now (lists of bricks from
     * elsewhere, e.g. a model). A created component gets a free alias (a list rendered twice, or one per post in a
     * loop, doesn't reuse another one's component) and its onRun() runs before it is rendered.
     *
     * @param array<string, mixed> $brick
     */
    protected function renderComponent(string $alias, BrickType $type, array $brick): string
    {
        static::$created ??= new \WeakMap();
        $created = static::$created[$this->controller] ?? [];
        $class = ltrim((string) $type->component, '\\');
        $component = $this->controller->findComponentByName($alias);

        if ($component && !isset($created[$alias]) && ltrim(get_class($component), '\\') === $class) {
            return (string) $this->controller->renderComponent($alias);
        }

        $base = $alias;

        for ($n = 2; $this->controller->findComponentByName($alias) || isset($created[$alias]); $n++) {
            $alias = $base . '_' . $n;
        }

        try {
            $properties = array_diff_key($brick, array_flip(BrickType::RESERVED_FIELDS));
            $component = $this->controller->addComponent($class, $alias, $properties, true);
        } catch (\Throwable $exception) {
            $this->warn(sprintf('component brick "%s" (%s) could not be created: %s', $alias, $class, $exception->getMessage()));

            return '';
        }

        $created[$alias] = true;
        static::$created[$this->controller] = $created;
        $component->onRun();

        return (string) $this->controller->renderComponent($alias);
    }

    /**
     * Renders a markup file with the CMS controller's Twig environment.
     *
     * @param array<string, mixed> $vars
     */
    protected function renderFile(string $path, array $vars): string
    {
        $partial = new BrickPartial($path);

        $this->controller->getLoader()->setObject($partial);

        return $this->controller->getTwig()->load($partial->getFilePath())->render(array_merge($this->controller->vars, $vars));
    }

    protected function warn(string $message): void
    {
        if (Config::get('app.debug')) {
            Log::warning('NumenCode.Bricks: ' . $message);
        }
    }
}
