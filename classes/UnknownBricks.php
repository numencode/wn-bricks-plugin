<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Lang;
use Winter\Pages\Classes\Page;

/**
 * Keeps bricks whose type no longer exists (a theme definition was deleted, a plugin removed, a snippet switched off).
 *
 * The repeater only saves the fields of a brick's group, and such a brick has no group, so saving the page would drop
 * its content. So the bricks field gets a group for each missing type found in the page's stored bricks (in any
 * locale): a notice, plus one invisible field per stored value that posts the value back unchanged (hidden inputs).
 * The brick can be moved and deleted; the type is hidden in the "Add brick" list and isn't rendered on the website.
 */
class UnknownBricks
{
    public const ICON = 'icon-question-circle';

    /**
     * The field showing the notice; it posts nothing, so it's never saved.
     */
    public const NOTICE_FIELD = '_bricks_unknown';

    /**
     * Brick types for the missing types among a field's stored bricks.
     *
     * @param Page|BrickHost $host
     * @param string $field The form field name, e.g. viewBag[bricks] (a static page) or bricks (a model)
     * @param array<string, BrickType> $known
     * @return array<string, BrickType>
     */
    public static function types(object $host, string $field, array $known): array
    {
        $types = [];

        foreach (static::storedKeys($host, $field) as $code => $keys) {
            if (!isset($known[$code])) {
                $types[$code] = static::type((string) $code, $keys);
            }
        }

        return $types;
    }

    /**
     * The value keys of a field's stored bricks per type code, from every locale.
     *
     * @param Page|BrickHost $host
     * @return array<string, array<int, string>>
     */
    public static function storedKeys(object $host, string $field): array
    {
        $host = BrickHost::of($host, []);
        $key = $host->key($field);

        if ($key === null) {
            return [];
        }

        $keys = [];
        $host = BrickHost::of($host->model, [$key]);
        [$saved, $stored] = $host->versions();

        foreach (array_merge($saved, $stored) as $lists) {
            foreach ($lists[$key] ?? [] as $brick) {
                if (!is_array($brick) || !is_string($code = $brick['_group'] ?? null) || $code === '') {
                    continue;
                }

                foreach (array_keys($brick) as $key) {
                    if (!in_array($key, BrickType::RESERVED_FIELDS, true)) {
                        $keys[$code][(string) $key] = true;
                    }
                }

                $keys[$code] ??= [];
            }
        }

        return array_map('array_keys', $keys);
    }

    /**
     * The placeholder type of a missing type, with the given value keys.
     *
     * @param array<int, string> $keys
     */
    public static function type(string $code, array $keys): BrickType
    {
        $fields = [self::NOTICE_FIELD => [
            'type' => 'partial',
            'path' => '$/numencode/bricks/classes/unknownbricks/_notice.php',
        ]];

        foreach ($keys as $key) {
            $fields[$key] = [
                'type'     => 'partial',
                'path'     => '$/numencode/bricks/classes/unknownbricks/_value.php',
                'cssClass' => 'bricks-unknown-value',
            ];
        }

        // The repeater translates group names without parameters, so the name is passed translated
        return new BrickType(
            code: $code,
            name: Lang::get('numencode.bricks::lang.unknown.name', ['code' => $code]),
            description: 'numencode.bricks::lang.unknown.description',
            icon: self::ICON,
            fields: $fields,
            source: 'missing type',
        );
    }
}
