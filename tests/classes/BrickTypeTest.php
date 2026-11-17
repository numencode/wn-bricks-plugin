<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use NumenCode\Bricks\Classes\BrickType;
use NumenCode\Bricks\Classes\InvalidBrickException;
use NumenCode\Bricks\Tests\BricksTestCase;

class BrickTypeTest extends BricksTestCase
{
    public function testBuildsATypeFromADefinition(): void
    {
        $type = BrickType::fromArray('quote', [
            'name'        => 'Quote',
            'description' => 'A quote',
            'icon'        => 'icon-quote-left',
            'fields'      => ['quote' => ['type' => 'textarea']],
        ], '/path/quote.htm', '/path/quote.yaml');

        $this->assertSame('quote', $type->code);
        $this->assertSame('Quote', $type->label());
        $this->assertSame('A quote', $type->comment());
        $this->assertSame('icon-quote-left', $type->icon);
        $this->assertSame(['quote' => ['type' => 'textarea']], $type->fields);
        $this->assertSame('/path/quote.htm', $type->markupPath);
        $this->assertSame('/path/quote.yaml', $type->source);
        $this->assertFalse($type->isComponent());
    }

    public function testDefaultsAndTranslatedLabels(): void
    {
        $type = BrickType::fromArray('heading', [
            'name'   => 'numencode.bricks::lang.bricks.heading.name',
            'fields' => ['text' => ['type' => 'text']],
        ], '/path/heading.htm');

        $this->assertSame(BrickType::DEFAULT_ICON, $type->icon);
        $this->assertSame('', $type->comment());
        $this->assertSame(trans('numencode.bricks::lang.bricks.heading.name'), $type->label());
        $this->assertNotSame('numencode.bricks::lang.bricks.heading.name', $type->label());
    }

    public function testAMarkupOnlyTypeIsValid(): void
    {
        $type = BrickType::fromArray('divider', ['name' => 'Divider'], '/path/divider.htm');

        $this->assertSame([], $type->fields);
        $this->assertSame([], $type->permissions);
        $this->assertTrue($type->isAllowedFor(null));
    }

    public function testPermissions(): void
    {
        $single = BrickType::fromArray('html', ['name' => 'HTML', 'permissions' => 'acme.use_html'], '/path/html.htm');
        $list = BrickType::fromArray('html', ['name' => 'HTML', 'permissions' => ['acme.a', 'acme.b']], '/path/html.htm');

        $this->assertSame(['acme.use_html'], $single->permissions);
        $this->assertSame(['acme.a', 'acme.b'], $list->permissions);
        $this->assertFalse($single->isAllowedFor(null));

        $user = new \Backend\Models\User();
        $user->permissions = ['acme.b' => 1];
        $this->assertFalse($single->isAllowedFor($user));
        $this->assertTrue($list->isAllowedFor($user), 'any of the permissions is enough');
    }

    public function testTitleFrom(): void
    {
        $fields = ['url' => ['type' => 'text'], 'title' => ['type' => 'text']];

        $this->assertNull(BrickType::fromArray('video', ['name' => 'Video', 'fields' => $fields], 'v.htm')->titleFrom);
        $this->assertSame('title', BrickType::fromArray('video', [
            'name'      => 'Video',
            'fields'    => $fields,
            'titleFrom' => 'title',
        ], 'v.htm')->titleFrom);

        // A component's properties are only known later, so any name is accepted
        $this->assertSame('group', BrickType::fromArray('counters', [
            'name'      => 'Counters',
            'component' => 'NumenCode\Widgets\Components\Counters',
            'titleFrom' => 'group',
        ])->titleFrom);
    }

    public function testEditUrlPattern(): void
    {
        $make = fn (array $config) => BrickType::fromArray('box', ['name' => 'Box'] + $config, 'b.htm');

        $this->assertNull($make([])->editUrlPattern());
        $this->assertNull($make(['editUrl' => ' '])->editUrlPattern());

        // A path is a backend URL; the placeholders keep their braces
        $this->assertSame(\Backend::url('acme/boxes/update') . '/{id}', $make(['editUrl' => '/acme/boxes/update/{id}'])->editUrlPattern());
        $this->assertSame('https://example.com/edit/{id}', $make(['editUrl' => 'https://example.com/edit/{id}'])->editUrlPattern());

        // A component brick: the component's static bricksEditUrl()
        require_once $this->fixturePath('components/Greeting.php');
        $component = fn (string $class) => BrickType::fromArray('box', ['name' => 'Box', 'component' => $class]);

        $this->assertSame(\Backend::url('acme/greetings/update') . '/{name}', $component(\NumenCode\Bricks\Tests\Fixtures\Components\Greeting::class)->editUrlPattern());
        $this->assertNull($component(\Cms\Components\ViewBag::class)->editUrlPattern(), 'components without the method');
    }

    public function testAComponentBrickNeedsNoFieldsOrMarkup(): void
    {
        $type = BrickType::fromArray('counters', [
            'name'      => 'Counters',
            'component' => 'NumenCode\Widgets\Components\Counters',
        ]);

        $this->assertTrue($type->isComponent());
        $this->assertNull($type->markupPath);
        $this->assertSame([], $type->fields);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string|null, 3: string}>
     */
    public static function invalidDefinitions(): array
    {
        $fields = ['text' => ['type' => 'text']];

        return [
            'uppercase code'          => ['Quote', ['name' => 'Q', 'fields' => $fields], 'm.htm', 'the code must start'],
            'code with a dot'         => ['a.b', ['name' => 'Q', 'fields' => $fields], 'm.htm', 'the code must start'],
            'unknown key'             => ['q', ['name' => 'Q', 'fields' => $fields, 'colour' => 'red'], 'm.htm', 'unknown key(s) colour'],
            'missing name'            => ['q', ['fields' => $fields], 'm.htm', '"name" is required'],
            'empty name'              => ['q', ['name' => ' ', 'fields' => $fields], 'm.htm', '"name" is required'],
            'icon not a string'       => ['q', ['name' => 'Q', 'icon' => ['x'], 'fields' => $fields], 'm.htm', '"icon" must be a string'],
            'fields not a list'       => ['q', ['name' => 'Q', 'fields' => 'text'], 'm.htm', '"fields" must be a list'],
            'field without config'    => ['q', ['name' => 'Q', 'fields' => ['text']], 'm.htm', 'each field must be'],
            'reserved field _group'   => ['q', ['name' => 'Q', 'fields' => ['_group' => []]], 'm.htm', '"_group" is reserved'],
            'reserved field brick'    => ['q', ['name' => 'Q', 'fields' => ['brick' => []]], 'm.htm', '"brick" is reserved'],
            'no markup'               => ['q', ['name' => 'Q', 'fields' => $fields], null, 'no markup found'],
            'no fields, no markup'    => ['q', ['name' => 'Q'], null, 'no markup found'],
            'permissions not strings' => ['q', ['name' => 'Q', 'permissions' => [['x']]], 'm.htm', '"permissions" must be'],
            'permissions a number'    => ['q', ['name' => 'Q', 'permissions' => 5], 'm.htm', '"permissions" must be'],
            'titleFrom not a string'  => ['q', ['name' => 'Q', 'fields' => $fields, 'titleFrom' => ['text']], 'm.htm', '"titleFrom" must be a string'],
            'titleFrom unknown field' => ['q', ['name' => 'Q', 'fields' => $fields, 'titleFrom' => 'title'], 'm.htm', 'names the field "title"'],
        ];
    }

    /**
     * @dataProvider invalidDefinitions
     * @param array<string, mixed> $config
     */
    public function testInvalidDefinitionsAreRejected(string $code, array $config, ?string $markup, string $message): void
    {
        $this->expectException(InvalidBrickException::class);
        $this->expectExceptionMessage($message);

        BrickType::fromArray($code, $config, $markup, '/path/def.yaml');
    }

    public function testErrorsNameTheTypeAndTheFile(): void
    {
        try {
            BrickType::fromArray('quote', ['fields' => []], null, '/path/quote.yaml');
            $this->fail('No exception thrown');
        } catch (InvalidBrickException $exception) {
            $this->assertStringContainsString('Brick type "quote" (/path/quote.yaml)', $exception->getMessage());
        }
    }

    public function testAMissingDefinitionFileIsRejected(): void
    {
        $this->expectException(InvalidBrickException::class);
        $this->expectExceptionMessage('the definition file does not exist');

        BrickType::fromYaml('nope', $this->fixturePath('bricks/nope.yaml'));
    }

    public function testInvalidYamlIsRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'brick') . '.yaml';
        file_put_contents($file, "name: [unclosed\n");

        try {
            $this->expectException(InvalidBrickException::class);
            BrickType::fromYaml('broken', $file, '/path/broken.htm');
        } finally {
            @unlink($file);
        }
    }
}
