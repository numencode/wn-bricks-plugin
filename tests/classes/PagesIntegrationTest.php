<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Widgets\Form;
use Event;
use NumenCode\Bricks\Classes\PagesIntegration;
use NumenCode\Bricks\Tests\BricksTestCase;
use ReflectionClass;
use Winter\Pages\Classes\Page;
use Winter\Storm\Exception\SystemException;

class PagesIntegrationTest extends BricksTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Page::class)) {
            $this->markTestSkipped('Winter.Pages is not installed.');
        }

        $this->useFixtureTheme();
    }

    /**
     * A form widget with the given fields, without running its init() (no controller or rendering needed).
     *
     * @param array<string, array<string, mixed>> $secondaryFields
     */
    protected function makeForm(object $model, array $secondaryFields, bool $nested = false): Form
    {
        /** @var Form $form */
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $form->model = $model;
        $form->isNested = $nested;
        $form->fields = ['viewBag[title]' => ['type' => 'text']];
        $form->tabs = ['fields' => ['viewBag[bricks]' => ['type' => 'bricks', 'tab' => 'Content', 'allow' => 'text, quote']]];
        $form->secondaryTabs = ['fields' => $secondaryFields];

        return $form;
    }

    public function testBricksFieldsBecomeRepeatersInEveryContainer(): void
    {
        $page = new Page();
        $form = $this->makeForm($page, ['viewBag[aside]' => ['type' => 'bricks', 'ignore' => 'heading']]);

        PagesIntegration::extendForm($form);

        $bricks = $form->tabs['fields']['viewBag[bricks]'];
        $aside = $form->secondaryTabs['fields']['viewBag[aside]'];

        $this->assertSame('repeater', $bricks['type']);
        $this->assertSame(['text', 'quote'], array_keys($bricks['groups']));
        $this->assertSame('Content', $bricks['tab']);
        $this->assertSame('repeater', $aside['type']);
        $this->assertArrayNotHasKey('heading', $aside['groups']);
        $this->assertArrayHasKey('quote', $aside['groups']);
        $this->assertSame(['type' => 'text'], $form->fields['viewBag[title]']);
    }

    public function testBricksFieldsAreRegisteredAsTranslatableOnce(): void
    {
        $page = new Page();

        PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks']], $page, null, true);
        PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks']], $page, null, true);

        $this->assertSame(1, count(array_keys($page->translatable, 'viewBag[bricks]', true)));
    }

    public function testWithoutTranslateNothingIsRegistered(): void
    {
        $page = new Page();
        $before = $page->translatable;

        $fields = PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks']], $page, null, false);

        $this->assertSame('repeater', $fields['viewBag[bricks]']['type']);
        $this->assertSame($before, $page->translatable);
    }

    public function testTranslateTurnsTheFieldIntoAMultilingualRepeater(): void
    {
        if (!class_exists(\Winter\Translate\Plugin::class)) {
            $this->markTestSkipped('Winter.Translate is not installed.');
        }

        $form = $this->makeForm(new Page(), []);

        // The real event: this plugin's listener first, then Translate's (priority -1)
        Event::fire('backend.form.extendFieldsBefore', [$form]);

        $this->assertSame('mlrepeater', $form->tabs['fields']['viewBag[bricks]']['type']);
        $this->assertContains('viewBag[bricks]', $form->model->translatable);
    }

    public function testOtherFormsAreNotTouched(): void
    {
        $model = new \stdClass();
        $form = $this->makeForm(new Page(), [], nested: true);
        $other = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $other->model = $model;
        $other->fields = ['content' => ['type' => 'bricks']];

        PagesIntegration::extendForm($form);
        PagesIntegration::extendForm($other);

        $this->assertSame('bricks', $form->tabs['fields']['viewBag[bricks]']['type']);
        $this->assertSame('bricks', $other->fields['content']['type']);
    }

    public function testAFieldWithoutBrickTypesIsReported(): void
    {
        $this->expectException(SystemException::class);
        $this->expectExceptionMessage('The bricks field "viewBag[bricks]" offers no brick types');

        PagesIntegration::replaceFields(['viewBag[bricks]' => ['type' => 'bricks', 'allow' => 'nope']], new Page(), null, false);
    }
}
