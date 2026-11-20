<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use NumenCode\Bricks\Classes\BrickValidator;
use NumenCode\Bricks\Tests\BricksTestCase;

class BrickValidatorTest extends BricksTestCase
{
    /**
     * The issues as "level code file: message" (file name only), for readable assertions.
     *
     * @param array<int, array{level: string, code: string, file: string, message: string}> $issues
     * @return array<int, string>
     */
    protected function lines(array $issues): array
    {
        return array_map(
            fn (array $issue) => sprintf('%s %s %s: %s', $issue['level'], $issue['code'], basename($issue['file']), $issue['message']),
            $issues,
        );
    }

    public function testAValidThemeHasNoIssues(): void
    {
        $this->registerGreeting();
        $theme = $this->useFixtureTheme('bricktest');

        $this->assertSame([], $this->lines((new BrickValidator($theme))->validate()));
    }

    public function testAnUnregisteredComponentIsAnError(): void
    {
        $theme = $this->useFixtureTheme('bricktest');

        $this->assertSame([
            'error greeting greeting.yaml: the component NumenCode\Bricks\Tests\Fixtures\Components\Greeting is not registered (a plugin\'s registerComponents())',
        ], $this->lines((new BrickValidator($theme))->validate()));
    }

    public function testProblemsInTheThemeAreReported(): void
    {
        $this->registerGreeting();
        $theme = $this->useFixtureTheme('validation');
        $lines = $this->lines((new BrickValidator($theme))->validate());

        $expected = [
            // An invalid definition doesn't stop the others from being checked
            'error oops oops.yaml: unknown key(s) colour; allowed: name, description, icon, fields, component, permissions, titleFrom, editUrl',
            // Labels, permissions, field types (nested too), Twig
            'warning teaser teaser.yaml: "name" is the lang key "acme.nope::lang.teaser.name", which doesn\'t exist',
            'warning teaser teaser.yaml: the permission "acme.nope.teasers" is not registered',
            'error teaser teaser.yaml: the field "title" has an unknown type "txt"',
            'error teaser teaser.yaml: the field "images.image" has an unknown type "mediafindr"',
            'warning teaser teaser.yaml: "label" of the field "images.image" is the lang key "numencode.bricks::lang.fields.nope", which doesn\'t exist',
            'warning teaser teaser.yaml: an option of the field "style" is the lang key "numencode.bricks::lang.fields.nope_option", which doesn\'t exist',
            'error teaser teaser.htm: Twig: Unknown "nofilter" filter. Did you mean "filter"? (line 1)',
            // Component bricks
            'warning greeting greeting.yaml: the field "mood" is not a property of the component NumenCode\Bricks\Tests\Fixtures\Components\Greeting',
            'warning greeting greeting.yaml: "titleFrom" names "nope", which is not a field or property of the brick',
            'warning greeting greeting.htm: the partial is not used: a component brick renders its component',
            'error missing missing.yaml: the component class Acme\Nope\Components\Nope doesn\'t exist',
            // Partials without a type ("_helper" is a helper partial)
            'warning orphan orphan.htm: the partial is not used: there is no brick type with this code',
            // Bricks inside bricks: allow/ignore codes, the translation option, a bricks field inside another field
            'warning stack stack.yaml: the bricks field "items" has a "translation" option, which is ignored: nested bricks are translated with their brick',
            'warning stack stack.yaml: "allow" of the bricks field "items" names the brick type "nope", which doesn\'t exist',
            'warning stack stack.yaml: "ignore" of the bricks field "items" names the brick type "gone", which doesn\'t exist',
            'error stack stack.yaml: the bricks field "slides.content" is inside another field; bricks fields must be fields of the brick itself',
            // Layouts: the bricks fields' translation option
            'error wrong translated.htm: the bricks field: Unknown bricks "translation" option "per-field" (use "lists" or "fields").',
        ];

        // The base set is valid; the order of the codes follows the registry
        $this->assertEqualsCanonicalizing($expected, $lines);
    }

    public function testThemeFiles(): void
    {
        $theme = $this->useFixtureTheme('validation');
        $validator = new BrickValidator($theme);

        $this->assertTrue($validator->isThemeFile($theme->getPath() . '/bricks/oops.yaml'));
        $this->assertFalse($validator->isThemeFile(plugins_path('numencode/bricks/bricks/text.yaml')));
    }
}
