<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Backend\Facades\BackendAuth;
use Backend\Widgets\Form;
use Cms\Classes\Theme;
use Event;
use System\Classes\PluginManager;
use Winter\Storm\Database\Model;

/**
 * Bricks fields on any model: a field `type: bricks` in a model's fields.yaml (or added by another plugin, e.g.
 * NumenCode.BlogExtension on blog posts) becomes a repeater of bricks, like on static pages.
 *
 * - The attribute must be jsonable (a text column). With Winter.Translate it's translatable when the model uses
 *   TranslatableModel and lists the attribute in $translatable (Translate then shows one list per language).
 * - The brick types are the active theme's (the website's).
 * - On save: empty brick options are dropped and brick type permissions are checked (EmptyOptions, PermissionGuard),
 *   like on static pages.
 * - On the website: {{ renderBricks(record.bricks, 'name') }} – see BrickRenderer.
 */
class ModelIntegration
{
    /**
     * Models whose save checks are bound (one form can extend its fields more than once).
     *
     * @var \WeakMap<object, bool>|null
     */
    protected static ?\WeakMap $guarded = null;

    public static function register(): void
    {
        Event::listen('backend.form.extendFieldsBefore', [static::class, 'extendForm']);
    }

    public static function extendForm(Form $form): void
    {
        $model = $form->model;

        // Static pages have their own integration; nested forms (a brick's own form) don't hold bricks fields (yet)
        if ($form->isNested || !$model instanceof Model) {
            return;
        }

        $host = new ModelHost($model, static::fieldKeys($form));

        if (!$host->fields()) {
            return;
        }

        $theme = Theme::getActiveTheme();
        $translatable = PluginManager::instance()->exists('Winter.Translate');
        $user = BackendAuth::getUser();

        $form->fields = PagesIntegration::replaceFields((array) $form->fields, $host, $theme, $translatable, $user);

        foreach (['tabs', 'secondaryTabs'] as $container) {
            if (!empty($form->{$container}['fields'])) {
                $fields = PagesIntegration::replaceFields($form->{$container}['fields'], $host, $theme, $translatable, $user);
                $form->{$container} = array_merge($form->{$container}, ['fields' => $fields]);
            }
        }

        static::guard($host, $theme);

        if ($controller = $form->getController()) {
            $controller->addCss('/plugins/numencode/bricks/assets/css/bricks.css', 'NumenCode.Bricks');
            $controller->addJs('/plugins/numencode/bricks/assets/js/bricks.js', 'NumenCode.Bricks');
        }
    }

    /**
     * The attributes of the form's "type: bricks" fields (in all field containers).
     *
     * @return array<int, string>
     */
    public static function fieldKeys(Form $form): array
    {
        $keys = [];
        $host = new ModelHost($form->model, []);

        foreach ([(array) $form->fields, (array) ($form->tabs['fields'] ?? []), (array) ($form->secondaryTabs['fields'] ?? [])] as $fields) {
            foreach ($fields as $name => $config) {
                if (is_array($config) && ($config['type'] ?? null) === PagesIntegration::FIELD_TYPE && ($key = $host->key((string) $name)) !== null) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Binds the save checks to the model being edited (once).
     */
    protected static function guard(ModelHost $host, ?Theme $theme): void
    {
        static::$guarded ??= new \WeakMap();

        if (isset(static::$guarded[$host->model])) {
            return;
        }

        static::$guarded[$host->model] = true;

        // Before Winter.Translate's model.saveInternal listener (priority 0), which stores the translations
        $host->model->bindEvent('model.saveInternal', function () use ($host, $theme) {
            EmptyOptions::clean($host);
            BrickIds::assign($host);
            PermissionGuard::check($host, BackendAuth::getUser(), $theme);
        }, PermissionGuard::PRIORITY);
    }
}
