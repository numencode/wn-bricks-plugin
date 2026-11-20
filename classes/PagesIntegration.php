<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Backend\Facades\BackendAuth;
use Backend\Models\User;
use Backend\Widgets\Form;
use Cms\Classes\Theme;
use Event;
use System\Classes\PluginManager;
use Winter\Pages\Classes\Page;
use Winter\Storm\Exception\SystemException;

/**
 * Winter.Pages integration: a layout variable {variable type="bricks" name="bricks"} becomes a repeater of bricks
 * in the static page form, translatable with Winter.Translate.
 *
 * Pages adds the layout's syntax fields in the form's own form.extendFieldsBefore event. The global
 * backend.form.extendFieldsBefore event fires after it; this listener (default priority) runs before
 * Winter.Translate's (priority -1), so Translate sees a translatable "repeater" and turns it into an "mlrepeater".
 */
class PagesIntegration
{
    public const FIELD_TYPE = 'bricks';

    /**
     * The tab of Winter.Pages' page settings (layout, hidden, …).
     */
    public const SETTINGS_TAB = 'cms::lang.editor.settings';

    public static function register(): void
    {
        Event::listen('backend.form.extendFieldsBefore', [static::class, 'extendForm']);
        // After Winter.Pages adds its Preview tab (priority PHP_INT_MIN + 1)
        Event::listen('backend.form.extendFieldsBefore', [static::class, 'movePreview'], PHP_INT_MIN);
        Event::listen('cms.page.initComponents', [ComponentBricks::class, 'addComponents']);
        BrickDuplicator::register();
        ModelIntegration::register();

        if (class_exists(Page::class)) {
            PermissionGuard::register();
            EmptyOptions::register();
            BrickIds::register();
        }

        if (class_exists(\Winter\Pages\Controllers\Index::class)) {
            \Winter\Pages\Controllers\Index::extend(function ($controller) {
                $controller->addCss('/plugins/numencode/bricks/assets/css/bricks.css', 'NumenCode.Bricks');
                $controller->addJs('/plugins/numencode/bricks/assets/js/bricks.js', 'NumenCode.Bricks');
            });
        }
    }

    public static function extendForm(Form $form): void
    {
        // Nested forms (e.g. a brick's own form inside the repeater) share the page model; bricks don't nest (yet)
        if ($form->isNested || !$form->model instanceof Page) {
            return;
        }

        $theme = Theme::getEditTheme();
        $translatable = PluginManager::instance()->exists('Winter.Translate');
        $user = BackendAuth::getUser();

        $form->fields = static::replaceFields((array) $form->fields, $form->model, $theme, $translatable, $user);

        foreach (['tabs', 'secondaryTabs'] as $container) {
            if (!empty($form->{$container}['fields'])) {
                $fields = static::replaceFields($form->{$container}['fields'], $form->model, $theme, $translatable, $user);
                $form->{$container} = array_merge($form->{$container}, ['fields' => $fields]);
            }
        }
    }

    /**
     * Winter.Pages shows its Preview in the lower (secondary) tabs, which only get the height the upper tabs leave.
     * A bricks field in the upper tabs (NumenCode.Fundamentals' pages_viewbag_primary_tabs, on by default, puts the
     * layout's fields there; or placement="primary") grows with every brick, so the Preview shrinks to a strip.
     * In that case the Preview becomes an upper tab next to the bricks, with a height of its own (assets/css/bricks.css),
     * and the tabs are ordered for editing bricks: Content, Preview, Meta, Settings (sortTabs()).
     */
    public static function movePreview(Form $form): void
    {
        if ($form->isNested || !$form->model instanceof Page) {
            return;
        }

        $secondary = (array) ($form->secondaryTabs['fields'] ?? []);
        $preview = $secondary['_preview'] ?? null;

        if (!is_array($preview) || !static::hasBricksField((array) ($form->tabs['fields'] ?? []))) {
            return;
        }

        unset($secondary['_preview']);
        $tab = $preview['tab'] ?? 'winter.pages::lang.editor.preview';
        $preview['cssClass'] = trim(($preview['cssClass'] ?? '') . ' bricks-preview');

        $form->tabs = array_merge($form->tabs, [
            'fields' => static::sortTabs($form->tabs['fields'] + ['_preview' => $preview], $tab),
            'icons'  => (array) ($form->tabs['icons'] ?? []) + [$tab => 'icon-crosshairs'],
        ]);
        $form->secondaryTabs = array_merge((array) $form->secondaryTabs, ['fields' => $secondary]);
    }

    /**
     * Orders the upper tabs for editing bricks: the tabs with bricks fields, the Preview, the other tabs (e.g. Meta),
     * the page settings last. A form shows its tabs in the order of their first field, and opens the first one.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function sortTabs(array $fields, string $previewTab): array
    {
        $tabs = [];

        foreach ($fields as $name => $config) {
            $tabs[is_array($config) ? (string) ($config['tab'] ?? '') : ''][$name] = $config;
        }

        $rank = fn (string $tab) => match (true) {
            static::hasBricksField($tabs[$tab]) => 0,
            $tab === $previewTab                => 1,
            $tab === self::SETTINGS_TAB         => 3,
            default                             => 2,
        };

        // Stable: tabs of the same rank keep their order
        $order = array_keys($tabs);
        usort($order, fn ($a, $b) => $rank((string) $a) <=> $rank((string) $b));

        return array_merge(...array_map(fn ($tab) => $tabs[$tab], $order));
    }

    /**
     * Whether a field list contains a bricks field (already replaced by FieldBuilder: it carries data-bricks).
     *
     * @param array<string, mixed> $fields
     */
    public static function hasBricksField(array $fields): bool
    {
        foreach ($fields as $config) {
            if (is_array($config) && isset($config['containerAttributes']['data-bricks'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The languages for assets/js/bricks.js (Winter.Translate): the default locale, the enabled locales' names and the
     * texts of the hint shown above an empty list in another language (":locale" / ":default" are filled in by the
     * browser). An empty list isn't stored, so the website shows the default language's bricks there.
     *
     * @return array<string, mixed>
     */
    public static function localeConfig(): array
    {
        if (!class_exists(\Winter\Translate\Classes\Translator::class)) {
            return [];
        }

        return [
            'default' => \Winter\Translate\Classes\Translator::instance()->getDefaultLocale(),
            'names'   => (object) \Winter\Translate\Models\Locale::listEnabled(),
            'notice'  => \Lang::get('numencode.bricks::lang.fallback.notice'),
            'copy'    => \Lang::get('numencode.bricks::lang.fallback.copy'),
        ];
    }

    /**
     * Replaces the bricks fields of one field list with repeaters and registers them as translatable attributes.
     *
     * Offered in "Add brick": the types allowed by the field's allow/ignore that the backend user has the
     * permissions for. All other types stay in the repeater (hidden, read-only without the permission), so
     * existing bricks of those types keep their values when the page is saved.
     *
     * Used for static pages and for models (ModelIntegration): $host is where the fields are stored. A static page's
     * fields are made translatable here; a model's are translatable when the model declares them.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param Page|BrickHost $host
     * @param bool $translatable Whether Winter.Translate is installed
     * @return array<string, array<string, mixed>>
     * @throws SystemException When the field's allow/ignore leave no brick types
     */
    public static function replaceFields(array $fields, object $host, ?Theme $theme, bool $translatable, ?User $user = null): array
    {
        $host = BrickHost::of($host, []);
        $registry = BrickRegistry::instance();
        $all = $registry->all($theme);
        $denied = array_keys(array_filter($all, fn (BrickType $type) => !$type->isAllowedFor($user)));

        foreach ($fields as $name => $config) {
            if (!is_array($config) || ($config['type'] ?? null) !== self::FIELD_TYPE) {
                continue;
            }

            $types = $registry->filter(
                FieldBuilder::codes($config['allow'] ?? []),
                FieldBuilder::codes($config['ignore'] ?? []),
                $theme,
            );

            if (!$types) {
                throw new SystemException(sprintf('The bricks field "%s" offers no brick types (check "allow" and "ignore").', $name));
            }

            $offered = array_diff_key($types, array_flip($denied));
            // Stored bricks whose type no longer exists keep their content (hidden in "Add brick", like $all's rest)
            $known = $all + UnknownBricks::types($host, (string) $name, $all);
            $key = $host->key((string) $name) ?? (string) $name;
            $fieldTranslatable = $translatable && ($host instanceof PageHost || $host->isTranslatable($key));
            $client = $fieldTranslatable ? ['locales' => static::localeConfig()] : [];
            $fields[$name] = FieldBuilder::field($config, $offered, $known, $denied, $client);

            if ($fieldTranslatable) {
                $host->makeTranslatable($key);
            }
        }

        return $fields;
    }
}
