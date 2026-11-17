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
            'no fields, no component' => ['q', ['name' => 'Q'], 'm.htm', 'either "fields" or "component"'],
            'no markup'               => ['q', ['name' => 'Q', 'fields' => $fields], null, 'no markup found'],
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
