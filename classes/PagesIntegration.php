<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

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

    public static function register(): void
    {
        Event::listen('backend.form.extendFieldsBefore', [static::class, 'extendForm']);
        Event::listen('cms.page.initComponents', [ComponentBricks::class, 'addComponents']);
    }

    public static function extendForm(Form $form): void
    {
        // Nested forms (e.g. a brick's own form inside the repeater) share the page model; bricks don't nest (yet)
        if ($form->isNested || !$form->model instanceof Page) {
            return;
        }

        $theme = Theme::getEditTheme();
        $translatable = PluginManager::instance()->exists('Winter.Translate');

        $form->fields = static::replaceFields((array) $form->fields, $form->model, $theme, $translatable);

        foreach (['tabs', 'secondaryTabs'] as $container) {
            if (!empty($form->{$container}['fields'])) {
                $fields = static::replaceFields($form->{$container}['fields'], $form->model, $theme, $translatable);
                $form->{$container} = array_merge($form->{$container}, ['fields' => $fields]);
            }
        }
    }

    /**
     * Replaces the bricks fields of one field list with repeaters and registers them as translatable attributes.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     * @throws SystemException When a field offers no brick types
     */
    public static function replaceFields(array $fields, Page $page, ?Theme $theme, bool $translatable): array
    {
        foreach ($fields as $name => $config) {
            if (!is_array($config) || ($config['type'] ?? null) !== self::FIELD_TYPE) {
                continue;
            }

            $types = BrickRegistry::instance()->filter(
                FieldBuilder::codes($config['allow'] ?? []),
                FieldBuilder::codes($config['ignore'] ?? []),
                $theme,
            );

            if (!$types) {
                throw new SystemException(sprintf('The bricks field "%s" offers no brick types (check "allow" and "ignore").', $name));
            }

            $fields[$name] = FieldBuilder::field($config, $types);

            if ($translatable && !in_array($name, $page->translatable, true)) {
                $page->translatable[] = $name;
            }
        }

        return $fields;
    }
}
