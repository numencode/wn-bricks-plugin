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
 * Server-side check of brick type permissions (e.g. numencode.bricks.use_html) when a static page or a model with
 * bricks fields (ModelIntegration) is saved.
 *
 * The backend form only hides the types a user lacks the permission for and makes their fields read-only; the
 * browser can still send anything. So on save, every brick of such a type (in every language) must be identical to
 * a brick of that type already stored in that record, in any language. Moving, removing and copying stored bricks
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

    /**
     * Showing or hiding a brick, its anchor, CSS class and id aren't changes of its content (like moving it).
     */
    public const OPTION_KEYS = [FieldBuilder::HIDDEN_FIELD, FieldBuilder::ANCHOR_FIELD, FieldBuilder::CLASS_FIELD, FieldBuilder::ID_FIELD];

    public static function register(): void
    {
        Page::extend(function (Page $page) {
            $page->bindEvent('model.saveInternal', function () use ($page) {
                static::check($page, BackendAuth::getUser());
            }, self::PRIORITY);
        });
    }

    /**
     * @param Page|BrickHost $host A static page (its layout's bricks fields) or a model's bricks fields
     * @throws ValidationException When the user added or changed a brick of a type they lack the permissions for
     */
    public static function check(object $host, ?User $user, ?Theme $theme = null): void
    {
        if (!$user) {
            return;
        }

        $types = BrickRegistry::instance()->all($theme ?? Theme::getEditTheme());
        $denied = array_filter($types, fn (BrickType $type) => !$type->isAllowedFor($user));

        $host = BrickHost::of($host);

        if (!$denied || !$host->fields()) {
            return;
        }

        [$saved, $stored] = $host->versions();
        $allowed = [];

        foreach ($stored as $lists) {
            foreach (static::bricksOf($lists, $denied, $types) as $brick) {
                $allowed[static::fingerprint($brick)] = true;
            }
        }

        foreach ($saved as $lists) {
            foreach (static::bricksOf($lists, $denied, $types) as $field => $brick) {
                if (!isset($allowed[static::fingerprint($brick)])) {
                    throw new ValidationException([$host->formName((string) $field) => Lang::get(
                        'numencode.bricks::lang.permissions.denied',
                        ['type' => $denied[$brick['_group']]->label()],
                    )]);
                }
            }
        }
    }

    /**
     * The bricks of the denied types in lists of bricks (field => bricks), nested bricks included (a brick moved into
     * or out of a container keeps its content), as [field, brick] pairs.
     *
     * @param array<string, array<int|string, mixed>> $lists
     * @param array<string, BrickType> $denied
     * @param array<string, BrickType> $types All types (to find the nested lists)
     * @return \Generator<string, array<string, mixed>>
     */
    protected static function bricksOf(array $lists, array $denied, array $types = []): \Generator
    {
        foreach ($lists as $field => $bricks) {
            foreach (BrickTree::flatten((array) $bricks, $types) as $brick) {
                if (isset($denied[$brick['_group'] ?? ''])) {
                    yield $field => $brick;
                }
            }
        }
    }

    /**
     * A brick's content, independent of how it was stored or sent: line endings, surrounding whitespace, empty
     * values and the order of keys don't count (the order of list items does). Neither do the options (OPTION_KEYS)
     * of the brick and of the bricks inside it.
     */
    public static function fingerprint(array $brick): string
    {
        return json_encode(static::normalize(array_diff_key($brick, array_flip(self::OPTION_KEYS))), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return trim(str_replace(["\r\n", "\r"], "\n", (string) (is_bool($value) ? (int) $value : $value)));
        }

        $result = [];

        // A brick (also a nested one): its options aren't content; nor is a repeater item's id
        if (isset($value['_group'])) {
            $value = array_diff_key($value, array_flip(self::OPTION_KEYS));
        } elseif (!array_is_list($value)) {
            unset($value[FieldBuilder::ID_FIELD]);
        }

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
