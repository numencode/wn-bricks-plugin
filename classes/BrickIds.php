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
     * Assigns ids to the bricks of all bricks fields of a static page or model, in every language.
     *
     * @param Page|BrickHost $host
     */
    public static function assign(object $host): void
    {
        $host = BrickHost::of($host);

        foreach ($host->fields() as $field) {
            $bricks = $host->current($field);

            if ($bricks && ($assigned = static::bricks($bricks)) !== $bricks) {
                $host->setCurrent($field, $assigned);
            }

            if (!$host->isTranslatable($field)) {
                continue;
            }

            foreach ($host->otherLocales() as $locale) {
                $translated = $host->translated($field, $locale);

                if ($translated && ($assigned = static::bricks($translated)) !== $translated) {
                    $host->setTranslated($field, $assigned, $locale);
                }
            }
        }
    }

    /**
     * A list of bricks with an id on every brick (unique within the list).
     *
     * @param array<int|string, mixed> $bricks
     * @return array<int|string, mixed>
     */
    public static function bricks(array $bricks): array
    {
        $seen = [];

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
                $bricks[$index] = $brick;
            }

            $seen[$id] = true;
        }

        return $bricks;
    }
}
