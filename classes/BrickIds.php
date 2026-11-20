<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Winter\Pages\Classes\Page;

/**
 * Every brick has a stable id (FieldBuilder::ID_FIELD): a short random string that stays the same when the brick is
 * moved or edited. Translations of a shared brick structure are stored per id (translation: fields).
 *
 * Ids are assigned when a static page or model is saved (model.saveInternal, together with EmptyOptions), in every
 * language: bricks without one get a new id, and a repeated id (e.g. a brick duplicated in the browser) is replaced in
 * all but its first brick. Duplicated and pasted bricks lose their id in the editor (BrickDuplicator), so they get a
 * new one on save.
 *
 * The items of a brick's repeaters with translatable fields (BrickType::translatableItems(), e.g. the gallery's images)
 * get ids too, unique in the same tree: a shared structure translates them per item.
 */
class BrickIds
{
    /**
     * The length of a new id; 36^10 possible ids, so a collision within one field is practically impossible.
     */
    public const LENGTH = 10;

    protected const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public static function register(): void
    {
        Page::extend(function (Page $page) {
            $page->bindEvent('model.saveInternal', fn () => static::assign($page), EmptyOptions::PRIORITY);
        });
    }

    public static function generate(): string
    {
        $id = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $id .= self::ALPHABET[random_int(0, $max)];
        }

        return $id;
    }

    /**
     * Whether a value is a usable id: a short string of letters, digits, "-" and "_".
     */
    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1;
    }

    /**
     * Assigns ids to the bricks of all bricks fields of a static page or model (nested bricks included), in every
     * language.
     *
     * @param Page|BrickHost $host
     */
    public static function assign(object $host): void
    {
        $host = BrickHost::of($host);
        $types = $host->types();

        foreach ($host->fields() as $field) {
            $bricks = $host->current($field);

            if ($bricks && ($assigned = static::bricks($bricks, $types)) !== $bricks) {
                $host->setCurrent($field, $assigned);
            }

            if (!$host->isTranslatable($field)) {
                continue;
            }

            foreach ($host->otherLocales() as $locale) {
                $translated = $host->translated($field, $locale);

                if ($translated && ($assigned = static::bricks($translated, $types)) !== $translated) {
                    $host->setTranslated($field, $assigned, $locale);
                }
            }
        }
    }

    /**
     * A list of bricks with an id on every brick, unique within the list and its nested lists (bricks inside bricks,
     * walked with $types; without them only the list itself).
     *
     * @param array<int|string, mixed> $bricks
     * @param array<string, BrickType> $types
     * @return array<int|string, mixed>
     */
    public static function bricks(array $bricks, array $types = []): array
    {
        $seen = [];

        return static::assignIds($bricks, $types, $seen);
    }

    /**
     * @param array<int|string, mixed> $items
     * @param array<string, bool> $seen
     * @return array<int|string, mixed>
     */
    protected static function assignItemIds(array $items, array &$seen): array
    {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item[FieldBuilder::ID_FIELD] ?? null;

            if (!static::isValid($id) || isset($seen[$id])) {
                do {
                    $id = static::generate();
                } while (isset($seen[$id]));

                $items[$index][FieldBuilder::ID_FIELD] = $id;
            }

            $seen[$id] = true;
        }

        return $items;
    }

    /**
     * @param array<int|string, mixed> $bricks
     * @param array<string, BrickType> $types
     * @param array<string, bool> $seen The ids given so far (in the whole tree)
     * @return array<int|string, mixed>
     */
    protected static function assignIds(array $bricks, array $types, array &$seen): array
    {
        foreach ($bricks as $index => $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $id = $brick[FieldBuilder::ID_FIELD] ?? null;

            if (!static::isValid($id) || isset($seen[$id])) {
                do {
                    $id = static::generate();
                } while (isset($seen[$id]));

                $brick[FieldBuilder::ID_FIELD] = $id;
            }

            $seen[$id] = true;
            $type = $types[(string) ($brick['_group'] ?? '')] ?? null;

            // The items of repeaters with translatable fields (e.g. the gallery's images)
            foreach (array_keys($type?->translatableItems() ?? []) as $name) {
                if (is_array($brick[$name] ?? null)) {
                    $brick[$name] = static::assignItemIds($brick[$name], $seen);
                }
            }

            foreach (BrickTree::children($brick, $types) as $name => $list) {
                $brick[$name] = static::assignIds($list, $types, $seen);
            }

            $bricks[$index] = $brick;
        }

        return $bricks;
    }
}
