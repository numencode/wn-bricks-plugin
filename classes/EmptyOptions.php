<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Winter\Pages\Classes\Page;

/**
 * Every brick form has the optional anchor and CSS class fields; empty text fields are posted as "", so without this
 * every brick would store `_anchor = ""` and `_class = ""`. Before a static page is saved (model.saveInternal, before
 * Winter.Translate writes the other languages' files), empty options are dropped from its bricks in every language.
 */
class EmptyOptions
{
    /**
     * Before PermissionGuard (100) and Winter.Translate (0); the guard ignores these options anyway.
     */
    public const PRIORITY = 110;

    /**
     * The brick options that aren't stored when empty.
     */
    public const KEYS = [FieldBuilder::ANCHOR_FIELD, FieldBuilder::CLASS_FIELD, FieldBuilder::HIDDEN_FIELD];

    public static function register(): void
    {
        Page::extend(function (Page $page) {
            $page->bindEvent('model.saveInternal', fn () => static::clean($page), self::PRIORITY);
        });
    }

    /**
     * Drops the empty options from the bricks of all bricks fields of a static page or model, in every language.
     *
     * @param Page|BrickHost $host
     */
    public static function clean(object $host): void
    {
        $host = BrickHost::of($host);

        foreach ($host->fields() as $field) {
            $bricks = $host->current($field);

            if ($bricks) {
                $cleaned = static::bricks($bricks);

                if ($cleaned !== $bricks) {
                    $host->setCurrent($field, $cleaned);
                }
            }

            if (!$host->isTranslatable($field)) {
                continue;
            }

            foreach ($host->otherLocales() as $locale) {
                $translated = $host->translated($field, $locale);
                $cleaned = static::bricks($translated);

                if ($translated && $cleaned !== $translated) {
                    $host->setTranslated($field, $cleaned, $locale);
                }
            }
        }
    }

    /**
     * @param array<int|string, mixed> $bricks
     * @return array<int|string, mixed>
     */
    public static function bricks(array $bricks): array
    {
        foreach ($bricks as $index => $brick) {
            if (!is_array($brick)) {
                continue;
            }

            foreach (self::KEYS as $key) {
                if (array_key_exists($key, $brick) && (is_scalar($brick[$key]) || $brick[$key] === null) && trim((string) $brick[$key]) === '') {
                    unset($brick[$key]);
                }
            }

            $bricks[$index] = $brick;
        }

        return $bricks;
    }
}
