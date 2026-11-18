<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Backend\Facades\BackendAuth;
use Backend\Models\User;
use Cms\Classes\Theme;
use Lang;
use Winter\Pages\Classes\Page;
use Winter\Storm\Exception\ValidationException;

/**
 * Server-side check of brick type permissions (e.g. numencode.bricks.use_html) when a static page is saved.
 *
 * The backend form only hides the types a user lacks the permission for and makes their fields read-only; the
 * browser can still send anything. So on save, every brick of such a type (in every language) must be identical to
 * a brick of that type already stored on the page, in any language. Moving, removing and copying stored bricks
 * (e.g. copying a translation) stay allowed; adding a new one or changing one is rejected.
 *
 * Only saves by a backend user are checked; console commands and other code without a backend user are not.
 */
class PermissionGuard
{
    /**
     * Runs before Winter.Translate's model.saveInternal listener (priority 0), which writes the translation files.
     */
    public const PRIORITY = 100;

    public static function register(): void
    {
        Page::extend(function (Page $page) {
            $page->bindEvent('model.saveInternal', function () use ($page) {
                static::check($page, BackendAuth::getUser());
            }, self::PRIORITY);
        });
    }

    /**
     * @throws ValidationException When the user added or changed a brick of a type they lack the permissions for
     */
    public static function check(Page $page, ?User $user, ?Theme $theme = null): void
    {
        if (!$user) {
            return;
        }

        $denied = array_filter(
            BrickRegistry::instance()->all($theme ?? Theme::getEditTheme()),
            fn (BrickType $type) => !$type->isAllowedFor($user),
        );

        $fields = $denied ? BrickRenderer::fieldNames($page) : [];

        if (!$fields) {
            return;
        }

        [$saved, $stored] = static::versions($page);
        $allowed = [];

        foreach ($stored as $viewBag) {
            foreach (static::bricksOf($viewBag, $fields, $denied) as $brick) {
                $allowed[static::fingerprint($brick)] = true;
            }
        }

        foreach ($saved as $viewBag) {
            foreach (static::bricksOf($viewBag, $fields, $denied) as $field => $brick) {
                if (!isset($allowed[static::fingerprint($brick)])) {
                    throw new ValidationException(['viewBag[' . $field . ']' => Lang::get(
                        'numencode.bricks::lang.permissions.denied',
                        ['type' => $denied[$brick['_group']]->label()],
                    )]);
                }
            }
        }
    }

    /**
     * The viewBag to be saved and the stored one, of the default language and (with Winter.Translate) of every
     * other enabled language.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    /**
     * All viewBags of the page: the current and the stored one, of the default locale and every other enabled locale.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function viewBags(Page $page): array
    {
        [$saved, $stored] = static::versions($page);

        return array_merge($saved, $stored);
    }

    protected static function versions(Page $page): array
    {
        $saved = [(array) ($page->attributes['viewBag'] ?? [])];
        $stored = [$page->exists ? (array) $page->getOriginal('viewBag') : []];

        if (!$page->isClassExtendedWith('Winter.Translate.Behaviors.TranslatableCmsObject')) {
            return [$saved, $stored];
        }

        $default = \Winter\Translate\Classes\Translator::instance()->getDefaultLocale();

        foreach (array_keys(\Winter\Translate\Models\Locale::listEnabled()) as $locale) {
            if ($locale === $default) {
                continue;
            }

            // Loads the stored translation first, when it isn't loaded yet (then both are the stored version)
            $saved[] = (array) ($page->getTranslateAttributes($locale)['viewBag'] ?? []);
            $stored[] = (array) ($page->getTranslatableOriginals($locale)['viewBag'] ?? []);
        }

        return [$saved, $stored];
    }

    /**
     * The bricks of the denied types in the given fields of a viewBag, as [field, brick] pairs.
     *
     * @param array<string, mixed> $viewBag
     * @param array<int, string> $fields
     * @param array<string, BrickType> $denied
     * @return \Generator<string, array<string, mixed>>
     */
    protected static function bricksOf(array $viewBag, array $fields, array $denied): \Generator
    {
        foreach ($fields as $field) {
            foreach ((array) ($viewBag[$field] ?? []) as $brick) {
                if (is_array($brick) && isset($denied[$brick['_group'] ?? ''])) {
                    yield $field => $brick;
                }
            }
        }
    }

    /**
     * A brick's content, independent of how it was stored or sent: line endings, surrounding whitespace, empty
     * values and the order of keys don't count (the order of list items does).
     */
    public static function fingerprint(array $brick): string
    {
        // Showing or hiding a brick isn't a change of its content (like moving it)
        unset($brick[FieldBuilder::HIDDEN_FIELD]);

        return json_encode(static::normalize($brick), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return trim(str_replace(["\r\n", "\r"], "\n", (string) (is_bool($value) ? (int) $value : $value)));
        }

        $result = [];

        foreach ($value as $key => $item) {
            $item = static::normalize($item);

            if ($item !== '' && $item !== []) {
                $result[$key] = $item;
            }
        }

        if (array_is_list($value)) {
            return array_values($result);
        }

        ksort($result);

        return $result;
    }
}
