<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Event;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\ComponentBricks;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\Tests\BricksTestCase;
use NumenCode\Bricks\Tests\Fixtures\Components\Greeting;
use Winter\Pages\Classes\Snippet;
use Winter\Pages\Classes\SnippetManager;

class ComponentBricksTest extends BricksTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->registerGreeting();

        if (class_exists(SnippetManager::class)) {
            SnippetManager::forgetInstance();
        }

        $this->useFixtureTheme();
    }

    protected function addSnippet(string $code, string $class = Greeting::class): void
    {
        Event::listen('pages.snippets.listSnippets', function (SnippetManager $manager) use ($code, $class) {
            $snippet = new Snippet();
            $snippet->initFromComponentInfo($class, $code);
            $manager->addSnippet($snippet);
        });
    }

    public function testFieldsAreGeneratedFromComponentProperties(): void
    {
        $fields = ComponentBricks::fields(BrickRegistry::instance()->get('greeting'));

        $this->assertSame(['name', 'tone', 'loud', 'size'], array_keys($fields));
        $this->assertSame(['label' => 'Name', 'type' => 'text', 'comment' => 'Who to greet', 'default' => 'World', 'required' => true], $fields['name']);
        $this->assertSame('dropdown', $fields['size']['type']);
        $this->assertSame(['s' => 'Small', 'l' => 'Large'], $fields['size']['options']);
    }

    public function testDynamicOptionsPlaceholderAndOverrides(): void
    {
        $fields = ComponentBricks::fields(BrickRegistry::instance()->get('greeting'));

        // Options from getToneOptions(), the placeholder kept, the label from the definition's override
        $this->assertSame('dropdown', $fields['tone']['type']);
        $this->assertSame(['warm' => 'Warm', 'cool' => 'Cool'], $fields['tone']['options']);
        $this->assertSame('Pick a tone', $fields['tone']['placeholder']);
        $this->assertSame('Mood', $fields['tone']['label']);
        $this->assertSame(['label' => 'Loud', 'type' => 'checkbox', 'hidden' => true], $fields['loud']);
    }

    public function testComponentFieldsReachTheRepeaterGroups(): void
    {
        $groups = FieldBuilder::groups(BrickRegistry::instance()->filter(['greeting']));

        $this->assertSame('Greeting brick', $groups['greeting']['name']);
        $this->assertSame('text', $groups['greeting']['fields']['name']['type']);
    }

    public function testSnippetComponentsBecomeBrickTypes(): void
    {
        if (!class_exists(SnippetManager::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        $this->addSnippet('helloSnippet');
        $type = BrickRegistry::instance()->get('snippet-hello-snippet');

        $this->assertNotNull($type);
        $this->assertSame(Greeting::class, $type->component);
        $this->assertSame('Greeting', $type->label());
        $this->assertSame('Says hello', $type->comment());
        $this->assertSame(ComponentBricks::DEFAULT_ICON, $type->icon);
    }

    public function testSnippetsCanBeLimitedOrDisabled(): void
    {
        if (!class_exists(SnippetManager::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        $this->addSnippet('helloSnippet');

        config(['numencode.bricks::snippets' => ['Some\\Other\\Component']]);
        $this->assertFalse(BrickRegistry::instance()->has('snippet-hello-snippet'));

        config(['numencode.bricks::snippets' => ['\\' . Greeting::class]]);
        BrickRegistry::instance()->clearCache();
        $this->assertTrue(BrickRegistry::instance()->has('snippet-hello-snippet'));

        config(['numencode.bricks::snippets' => false]);
        BrickRegistry::instance()->clearCache();
        $this->assertFalse(BrickRegistry::instance()->has('snippet-hello-snippet'));
    }

    public function testSnippetCodesDoNotCollideWithExplicitTypes(): void
    {
        if (!class_exists(SnippetManager::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        // The theme's bricks/greeting.yaml and a snippet with the code "greeting": both are offered
        $this->addSnippet('greeting');

        $this->assertSame('Greeting brick', BrickRegistry::instance()->get('greeting')->label());
        $this->assertSame('Greeting', BrickRegistry::instance()->get('snippet-greeting')->label());
    }

    public function testAliases(): void
    {
        $this->assertSame('brick_bricks_0', ComponentBricks::alias('bricks', 0));
        $this->assertSame('brick_side_bar_12', ComponentBricks::alias('side-bar', 12));
    }
}
