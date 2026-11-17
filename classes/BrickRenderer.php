<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Cms\Classes\Controller;
use Config;
use Log;
use Winter\Pages\Classes\Page;

/**
 * Renders bricks on the frontend.
 *
 * Markup of a brick: the theme partial bricks/<code>.htm, else the type's own <code>.htm, rendered with the brick's
 * values plus `brick` = {code, index, field}. Component bricks render their component (added by ComponentBricks).
 * Bricks of unknown types are skipped (a warning is logged in debug mode).
 */
class BrickRenderer
{
    public const DEFAULT_FIELD = 'bricks';

    /**
     * @var array<string, array<int, string>> Bricks field names per layout file
     */
    protected static array $fieldNames = [];

    public function __construct(protected Controller $controller)
    {
    }

    /**
     * Twig: renderBricks() / renderBricks('aside') renders a bricks field of the current static page;
     * renderBricks(list) renders a list (component bricks only when it is the page's "bricks" field).
     *
     * @param string|array<int, array<string, mixed>>|null $bricks
     */
    public static function renderBricksFunction(string|array|null $bricks = null): string
    {
        if (!$controller = Controller::getController()) {
            return '';
        }

        $renderer = new static($controller);

        return is_array($bricks)
            ? $renderer->renderList($bricks)
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

        return $staticPage instanceof Page ? $this->renderList(static::bricks($staticPage, $field), $field) : '';
    }

    /**
     * @param array<int, mixed> $bricks
     */
    public function renderList(array $bricks, string $field = self::DEFAULT_FIELD): string
    {
        $html = '';

        foreach (array_values($bricks) as $index => $brick) {
            if (is_array($brick)) {
                $html .= $this->renderBrick($brick, $index, $field);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $brick
     */
    public function renderBrick(array $brick, int $index = 0, string $field = self::DEFAULT_FIELD): string
    {
        $code = (string) ($brick['_group'] ?? '');
        $type = BrickRegistry::instance()->get($code, $this->controller->getTheme());

        if (!$type) {
            $this->warn(sprintf('unknown brick type "%s" (field "%s", index %d) skipped', $code, $field, $index));

            return '';
        }

        if ($type->isComponent()) {
            return $this->renderComponent(ComponentBricks::alias($field, $index));
        }

        $vars = array_diff_key($brick, array_flip(BrickType::RESERVED_FIELDS));
        $vars['brick'] = ['code' => $code, 'index' => $index, 'field' => $field];

        $html = $this->controller->renderPartial('bricks/' . $code, $vars, false);

        if ($html === false && $type->markupPath !== null) {
            $html = $this->renderFile($type->markupPath, $vars);
        }

        return is_string($html) ? $html : '';
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
        $layout = $staticPage->getLayoutObject();

        if (!$layout) {
            return [];
        }

        $key = $layout->getFilePath() . '@' . $layout->mtime;

        if (!isset(static::$fieldNames[$key])) {
            static::$fieldNames[$key] = [];

            foreach ($staticPage->listLayoutSyntaxFields() as $name => $config) {
                if (($config['type'] ?? null) === PagesIntegration::FIELD_TYPE) {
                    static::$fieldNames[$key][] = (string) $name;
                }
            }
        }

        return static::$fieldNames[$key];
    }

    protected function renderComponent(string $alias): string
    {
        if (!$this->controller->findComponentByName($alias)) {
            $this->warn(sprintf('component brick "%s" is not on the page (only page fields can render component bricks)', $alias));

            return '';
        }

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
