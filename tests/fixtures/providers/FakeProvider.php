<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Fixtures\Providers;

use Winter\Translate\Providers\TranslationProvider;

/**
 * A machine translation "service" for the tests: "[<target> from <source>] <text>".
 */
class FakeProvider implements TranslationProvider
{
    /**
     * @var array<int, array<int, string>> The inputs of every call
     */
    public static array $calls = [];

    public function __construct(array $config = [])
    {
    }

    public function translate(array $input, string $targetLocale, string $currentLocale): array
    {
        static::$calls[] = $input;

        return array_map(fn (string $text) => "[{$targetLocale} from {$currentLocale}] {$text}", $input);
    }
}
