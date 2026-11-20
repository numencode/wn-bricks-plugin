<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * Walks a list of bricks with its nested lists (bricks inside bricks: a type's `type: bricks` fields, e.g. the columns
 * of Columns). Which fields hold nested lists comes from the brick types; bricks of unknown types have none (their
 * values are kept as they are).
 */
final class BrickTree
{
    /**
     * A brick's nested lists: field name => list (the bricks fields of its type that hold a list).
     *
     * @param array<string, mixed> $brick
     * @param array<string, BrickType> $types
     * @return array<string, array<int|string, mixed>>
     */
    public static function children(array $brick, array $types): array
    {
        $type = $types[(string) ($brick['_group'] ?? '')] ?? null;
        $lists = [];

        foreach (array_keys($type?->bricksFields() ?? []) as $name) {
            if (is_array($brick[$name] ?? null)) {
                $lists[$name] = $brick[$name];
            }
        }

        return $lists;
    }

    /**
     * Every brick of a list and of its nested lists, a brick before its children, keyed by its path: the index for a
     * brick of the list, e.g. "2.column_1.0" for the first brick in the column_1 of the third brick.
     *
     * @param array<int|string, mixed> $bricks
     * @param array<string, BrickType> $types
     * @return \Generator<string, array<string, mixed>>
     */
    public static function flatten(array $bricks, array $types, string $prefix = ''): \Generator
    {
        foreach ($bricks as $index => $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $path = $prefix . $index;

            yield $path => $brick;

            foreach (static::children($brick, $types) as $name => $list) {
                yield from static::flatten($list, $types, $path . '.' . $name . '.');
            }
        }
    }

    /**
     * Applies $callback to every brick of a list and of its nested lists. The callback gets a brick and returns it
     * (changed or not); the nested lists of the returned brick are walked next.
     *
     * @param array<int|string, mixed> $bricks
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     * @param array<string, BrickType> $types
     * @return array<int|string, mixed>
     */
    public static function map(array $bricks, callable $callback, array $types): array
    {
        foreach ($bricks as $index => $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $brick = $callback($brick);

            foreach (static::children($brick, $types) as $name => $list) {
                $brick[$name] = static::map($list, $callback, $types);
            }

            $bricks[$index] = $brick;
        }

        return $bricks;
    }
}
