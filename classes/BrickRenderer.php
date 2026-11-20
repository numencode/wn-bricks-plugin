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
 *
 * Bricks inside bricks (a type's `type: bricks` fields, e.g. the columns of Columns): each nested list is rendered
 * before its brick; the field's variable is the HTML (`{{ column_1|raw }}`) and `brick.lists.<field>` the rendered
 * children (html, code, id, index, values) for markup that places them itself (tabs, accordions). A nested list's
 * name is its path, e.g. "bricks.2.column_1" (component aliases brick_bricks_2_column_1_0, preview markers).
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

    /**
     * Whether the bricks being rendered get preview markers (renderList()'s $markers, for the nested bricks too).
     */
    protected bool $markers = false;

    public function __construct(protected Controller $controller)
    {
    }

    /**
     * Twig: renderBricks() / renderBricks('aside') renders a bricks field of the current static page;
     * renderBricks(post, 'bricks') renders a model's bricks field in the visitor's language (with the translations of a
     * shared structure); renderBricks(list) / renderBricks(list, 'name') renders a list (renderBricks(post.bricks,
     * 'post')). The name (a model's: the attribute, or a third argument) keeps its component bricks apart from the
     * page's own ("bricks" = the page's field).
     *
     * @param string|array<int, array<string, mixed>>|object|null $bricks
     */
    public static function renderBricksFunction(string|array|object|null $bricks = null, ?string $name = null, ?string $alias = null): string
    {
        if (!$controller = Controller::getController()) {
            return '';
        }

        $renderer = new static($controller);

        if (is_object($bricks)) {
            $field = $name ?? self::DEFAULT_FIELD;

            return $renderer->renderList(static::modelBricks($bricks, $field, $controller->getTheme()), $alias ?? $field);
        }

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
        // The bricks inside these bricks get markers too
        $outer = $this->markers;
        $this->markers = $markers;

        foreach (array_values($bricks) as $index => $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $brickHtml = $this->renderBrick($brick, $index, $field);

            // In the editor's preview, bricks that show something get markers (nothing for hidden or empty bricks)
            $html .= $markers && trim($brickHtml) !== '' ? static::previewMarkers($brickHtml, $field, $index) : $brickHtml;
        }

        $this->markers = $outer;

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
        $lists = [];

        foreach (array_keys($type->bricksFields()) as $name) {
            $children = $this->renderChildren(static::listOf($brick[$name] ?? []), static::path($field, $index, $name));
            $vars[$name] = new \Twig\Markup(implode('', array_column($children, 'html')), 'UTF-8');
            $lists[$name] = $children;
        }

        $vars['brick'] = [
            'code'   => $code,
            'index'  => $index,
            'field'  => $field,
            'id'     => (string) ($brick[FieldBuilder::ID_FIELD] ?? ''),
            'anchor' => $anchor,
            'class'  => $class,
            'lists'  => $lists,
        ];

        // Check the theme partial first: renderPartial() keeps the merged variables when it returns false for a
        // missing partial, so the next brick (and the page) would see this brick's values
        if (Partial::loadCached($this->controller->getTheme(), 'bricks/' . $code)) {
            return (string) $this->controller->renderPartial('bricks/' . $code, $vars);
        }

        return $type->markupPath !== null ? $this->renderFile($type->markupPath, $vars) : '';
    }

    /**
     * The name of a nested list: the path of its brick and field, e.g. "bricks.2.column_1".
     */
    public static function path(string $field, int $index, string $name): string
    {
        return $field . '.' . $index . '.' . $name;
    }

    /**
     * The rendered bricks of a nested list that show something: html (with preview markers in the editor's preview),
     * code, id, index and values (the brick's own fields).
     *
     * @param array<int, array<string, mixed>> $bricks
     * @return array<int, array<string, mixed>>
     */
    protected function renderChildren(array $bricks, string $field): array
    {
        $children = [];

        foreach ($bricks as $index => $brick) {
            $html = $this->renderBrick($brick, $index, $field);

            if (trim($html) === '') {
                continue;
            }

            $children[] = [
                'html'   => new \Twig\Markup($this->markers ? static::previewMarkers($html, $field, $index) : $html, 'UTF-8'),
                'code'   => (string) ($brick['_group'] ?? ''),
                'id'     => (string) ($brick[FieldBuilder::ID_FIELD] ?? ''),
                'index'  => $index,
                'values' => array_diff_key($brick, array_flip(BrickType::RESERVED_FIELDS)),
            ];
        }

        return $children;
    }

    /**
     * The bricks stored in a field of a static page (its viewBag, in the visitor's locale).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function bricks(Page $staticPage, string $field): array
    {
        // A shared structure: the default language's list (a language's own list is ignored) with the visitor's
        // language's translations (merged into the viewBag by Winter.Translate)
        if (in_array($field, static::sharedFieldNames($staticPage), true)) {
            $structure = ((array) ($staticPage->attributes['viewBag'] ?? []))[$field] ?? [];

            return SharedTranslations::merge(
                static::listOf($structure),
                $staticPage->viewBag[FieldBuilder::translationsKey($field)] ?? [],
                static::translatableFields($staticPage->getThemeAttribute()),
            );
        }

        return static::listOf($staticPage->viewBag[$field] ?? []);
    }

    /**
     * A model's bricks field in the model's language: its list (translated, when the list is translatable), with the
     * translations of a shared structure (the attribute <field>_translations, stored by Winter.Translate).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function modelBricks(object $model, string $field, ?\Cms\Classes\Theme $theme = null): array
    {
        $bricks = static::listOf(BrickHost::decode($model->{$field} ?? null));

        if (
            !method_exists($model, 'isClassExtendedWith')
            || !$model->isClassExtendedWith('Winter.Translate.Behaviors.TranslatableModel')
        ) {
            return $bricks;
        }

        $locale = $model->translateContext();

        if ($locale === \Winter\Translate\Classes\Translator::instance()->getDefaultLocale()) {
            return $bricks;
        }

        $map = $model->getAttributeTranslated(FieldBuilder::translationsKey($field), $locale);

        return $map ? SharedTranslations::merge($bricks, BrickHost::decode($map), static::translatableFields($theme)) : $bricks;
    }

    /**
     * The translatable fields of every brick type (SharedTranslations::translatableOf(): code => field names, also
     * "<code>.<repeater>" => the repeater items' fields).
     *
     * @return array<string, array<int, string>>
     */
    public static function translatableFields(?\Cms\Classes\Theme $theme = null): array
    {
        $fields = [];

        foreach (BrickRegistry::instance()->all($theme) as $code => $type) {
            $fields += $type->isComponent() ? [$code => []] : SharedTranslations::translatableOf((string) $code, $type->fields);
        }

        return $fields;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listOf(mixed $bricks): array
    {
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
