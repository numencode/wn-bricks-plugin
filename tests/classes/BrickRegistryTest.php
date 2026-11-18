<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\InvalidBrickException;
use NumenCode\Bricks\Tests\BricksTestCase;

class BrickRegistryTest extends BricksTestCase
{
    protected function registry(): BrickRegistry
    {
        return BrickRegistry::instance();
    }

    protected function registerCallout(string $code = 'callout'): void
    {
        $path = $this->fixturePath('bricks/callout');

        $this->registry()->registerCallback(fn (BrickRegistry $registry) => $registry->registerBricks([$code => $path], 'Test'));
    }

    public function testThePluginBaseSet(): void
    {
        $this->useFixtureTheme('bricktest');
        $types = $this->registry()->all();

        $this->assertArrayHasKey('text', $types);
        $this->assertStringEndsWith('plugins/numencode/bricks/bricks/text.htm', str_replace('\\', '/', $types['text']->markupPath));
        $this->assertSame('richeditor', $types['text']->fields['content']['type']);
        $this->assertNotSame($types['text']->name, $types['text']->label());
    }

    public function testCallbacksRegisterTypes(): void
    {
        $this->useFixtureTheme('bricktest');
        $this->registerCallout();

        $callout = $this->registry()->get('callout');

        $this->assertNotNull($callout);
        $this->assertSame('Callout', $callout->label());
        $this->assertSame('A highlighted note', $callout->comment());
        $this->assertStringEndsWith('fixtures/bricks/callout.htm', str_replace('\\', '/', $callout->markupPath));
        $this->assertTrue($this->registry()->has('callout'));
        $this->assertSame('Test', $callout->plugin, 'the owner given to registerBricks()');
        $this->assertFalse($this->registry()->has('nope'));
    }

    public function testLaterRegistrationsReplaceEarlierOnes(): void
    {
        $this->useFixtureTheme('bricktest');
        $this->registerCallout('text');

        $this->assertSame('Callout', $this->registry()->get('text')->label());
    }

    public function testThemeAddsAndReplacesTypes(): void
    {
        $this->useFixtureTheme('bricktest');
        $types = $this->registry()->all();

        // The base set and the theme's own types are general
        $this->assertSame('', $types['text']->plugin);
        $this->assertSame('', $types['quote']->plugin);

        // New type with its markup in the theme's partials/bricks/
        $this->assertSame('Quote', $types['quote']->label());
        $this->assertStringEndsWith('themes/bricktest/partials/bricks/quote.htm', str_replace('\\', '/', $types['quote']->markupPath));

        // Replaced fields; without a theme partial the plugin's markup stays the fallback
        $this->assertSame('Theme heading', $types['heading']->label());
        $this->assertSame(['text'], array_keys($types['heading']->fields));
        $this->assertStringEndsWith('plugins/numencode/bricks/bricks/heading.htm', str_replace('\\', '/', $types['heading']->markupPath));
        $this->assertStringEndsWith('themes/bricktest/bricks/heading.yaml', str_replace('\\', '/', $types['heading']->source));
    }

    public function testAThemeDefinitionStaysInTheSectionOfTheTypeItReplaces(): void
    {
        $this->useFixtureTheme('bricktest');
        $this->registry()->registerCallback(fn (BrickRegistry $registry) => $registry->registerBricks([
            'heading' => $this->fixturePath('bricks/callout'),
        ], 'Acme.Shop'));

        $this->assertSame('Theme heading', $this->registry()->get('heading')->label());
        $this->assertSame('Acme.Shop', $this->registry()->get('heading')->plugin);
    }

    public function testThemeDefinitionsWinOverCallbacks(): void
    {
        $this->useFixtureTheme('bricktest');
        $this->registerCallout('quote');

        $this->assertSame('Quote', $this->registry()->get('quote')->label());
    }

    public function testFilterKeepsTheAllowedOrderAndDropsIgnoredAndUnknownCodes(): void
    {
        $this->useFixtureTheme('bricktest');

        $this->assertSame(['quote', 'text'], array_keys($this->registry()->filter(['quote', 'nope', 'text'])));
        $this->assertSame(['quote'], array_keys($this->registry()->filter(['quote', 'text'], ['text'])));

        $all = array_keys($this->registry()->all());
        $this->assertSame(array_values(array_diff($all, ['heading'])), array_keys($this->registry()->filter([], ['heading'])));
    }

    public function testTypesAreKeptPerThemeUntilTheCacheIsCleared(): void
    {
        $theme = $this->useFixtureTheme('bricktest');
        $first = $this->registry()->get('quote', $theme);

        $this->assertSame($first, $this->registry()->get('quote', $theme));

        $this->registry()->clearCache();

        $this->assertNotSame($first, $this->registry()->get('quote', $theme));
        $this->assertEquals($first, $this->registry()->get('quote', $theme));
    }

    public function testAnInvalidThemeDefinitionIsReported(): void
    {
        $this->useFixtureTheme('broken');

        $this->expectException(InvalidBrickException::class);
        $this->expectExceptionMessageMatches('/Brick type "oops" \(.*oops\.yaml\): unknown key\(s\) colour/');

        $this->registry()->all();
    }
}
