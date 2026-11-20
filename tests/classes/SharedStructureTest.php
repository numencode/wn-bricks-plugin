<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Event;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\Classes\ModelHost;
use NumenCode\Bricks\Classes\PagesIntegration;
use NumenCode\Bricks\FormWidgets\SharedBricks;
use NumenCode\Bricks\Tests\BricksTestCase;
use NumenCode\Bricks\Tests\Fixtures\Models\Record;
use ReflectionClass;
use Backend\Widgets\Form;
use Winter\Pages\Classes\Page;
use Winter\Storm\Exception\SystemException;

/**
 * Bricks fields with a shared structure (translation: fields): the option, the widget and where the translations
 * are registered.
 */
class SharedStructureTest extends BricksTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Page::class) || !class_exists(\Winter\Translate\Plugin::class)) {
            $this->markTestSkipped('Winter.Pages and Winter.Translate are needed.');
        }

        $this->useFixtureTheme();
    }

    public function testTheTranslationOption(): void
    {
        $this->assertFalse(FieldBuilder::isShared([]));
        $this->assertFalse(FieldBuilder::isShared(['translation' => '']));
        $this->assertFalse(FieldBuilder::isShared(['translation' => 'lists']));
        $this->assertTrue(FieldBuilder::isShared(['translation' => 'fields']));
        $this->assertSame('bricks_translations', FieldBuilder::translationsKey('bricks'));
    }

    public function testAnUnknownTranslationModeIsReported(): void
    {
        $this->expectException(SystemException::class);
        $this->expectExceptionMessage('Unknown bricks "translation" option "per-field"');

        FieldBuilder::isShared(['translation' => 'per-field']);
    }

    public function testASharedFieldUsesItsWidgetAndTheOptionIsNotPassedOn(): void
    {
        $field = FieldBuilder::field(['type' => 'bricks', 'translation' => 'fields'], []);

        $this->assertSame(FieldBuilder::SHARED_WIDGET, $field['type']);
        $this->assertArrayNotHasKey('translation', $field);
        $this->assertSame('repeater', FieldBuilder::field(['type' => 'bricks', 'translation' => 'lists'], [])['type']);
    }

    public function testTheWidgetIsRegistered(): void
    {
        $this->assertSame(
            SharedBricks::class,
            \Backend\Classes\WidgetManager::instance()->resolveFormWidget(FieldBuilder::SHARED_WIDGET),
        );
    }

    public function testAStaticPageRegistersTheTranslationsInsteadOfTheList(): void
    {
        $page = new Page();

        $fields = PagesIntegration::replaceFields(
            ['viewBag[bricks]' => ['type' => 'bricks', 'translation' => 'fields']],
            $page,
            null,
            true,
        );

        $this->assertSame(FieldBuilder::SHARED_WIDGET, $fields['viewBag[bricks]']['type']);
        $this->assertContains('viewBag[bricks_translations]', $page->translatable);
        $this->assertNotContains('viewBag[bricks]', $page->translatable);
        $this->assertArrayHasKey('locales', json_decode($fields['viewBag[bricks]']['containerAttributes']['data-bricks'], true));
    }

    public function testTranslateLeavesASharedFieldAlone(): void
    {
        /** @var Form $form */
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $form->model = new Page();
        $form->isNested = false;
        $form->fields = [];
        $form->tabs = ['fields' => ['viewBag[bricks]' => ['type' => 'bricks', 'tab' => 'Content', 'translation' => 'fields']]];
        $form->secondaryTabs = ['fields' => []];

        Event::fire('backend.form.extendFieldsBefore', [$form]);

        $this->assertSame(FieldBuilder::SHARED_WIDGET, $form->tabs['fields']['viewBag[bricks]']['type']);
    }

    public function testAModelGetsTheTranslationsAttribute(): void
    {
        $record = new Record();
        $host = new ModelHost($record, ['bricks', 'aside'], ['aside']);

        PagesIntegration::replaceFields(['aside' => ['type' => 'bricks', 'translation' => 'fields']], $host, null, true);

        $this->assertContains('aside_translations', $record->getTranslatableAttributes());
        $this->assertTrue($host->isShared('aside'));
        $this->assertTrue($host->hasTranslations('aside'));
        $this->assertFalse($host->isTranslatable('aside'), 'a shared list has no list per language');
        $this->assertFalse($host->isShared('bricks'));
        $this->assertTrue($host->isTranslatable('bricks'));
    }

    public function testASharedListMustNotBeTranslatableItself(): void
    {
        $host = new ModelHost(new Record(), ['bricks'], ['bricks']);

        $this->expectException(SystemException::class);
        $this->expectExceptionMessage('must not be translatable itself');

        PagesIntegration::replaceFields(['bricks' => ['type' => 'bricks', 'translation' => 'fields']], $host, null, true);
    }

    public function testModelIntegrationFindsTheSharedFields(): void
    {
        /** @var Form $form */
        $form = (new ReflectionClass(Form::class))->newInstanceWithoutConstructor();
        $form->model = new Record();
        $form->fields = [
            'bricks' => ['type' => 'bricks'],
            'aside'  => ['type' => 'bricks', 'translation' => 'fields'],
        ];

        $this->assertSame(['bricks', 'aside'], \NumenCode\Bricks\Classes\ModelIntegration::fieldKeys($form));
        $this->assertSame(['aside'], \NumenCode\Bricks\Classes\ModelIntegration::fieldKeys($form, true));
    }
}
