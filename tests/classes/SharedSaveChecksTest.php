<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Models\User;
use Cms\Classes\Theme;
use Illuminate\Database\Schema\Blueprint;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\ModelHost;
use NumenCode\Bricks\Classes\PermissionGuard;
use NumenCode\Bricks\Classes\SharedTranslations;
use NumenCode\Bricks\Classes\UnknownBricks;
use NumenCode\Bricks\Tests\BricksTestCase;
use NumenCode\Bricks\Tests\Fixtures\Models\Record;
use Schema;
use Winter\Storm\Exception\ValidationException;
use Winter\Translate\Models\Locale;

/**
 * The save checks of a shared structure (translation: fields) on a model's "aside" field: permissions in every
 * language's version, and translations of bricks whose type no longer exists.
 */
class SharedSaveChecksTest extends BricksTestCase
{
    protected Theme $theme;

    public function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Locale::class)) {
            $this->markTestSkipped('Winter.Translate is needed.');
        }

        require_once $this->fixturePath('models/Record.php');

        Schema::create(Record::TABLE, function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->mediumText('bricks')->nullable();
            $table->mediumText('aside')->nullable();
            $table->timestamps();
        });

        Locale::create(['code' => 'sl', 'name' => 'Slovenščina', 'is_enabled' => true]);
        Locale::clearCache();

        $this->theme = $this->useFixtureTheme();
        BrickRegistry::instance()->registerCallback(
            fn (BrickRegistry $registry) => $registry->registerBricks(['secret' => $this->fixturePath('bricks/secret')], 'Test'),
        );
    }

    public function tearDown(): void
    {
        Schema::dropIfExists(Record::TABLE);

        parent::tearDown();
    }

    protected function record(): Record
    {
        $record = Record::create([
            'title' => 'Post',
            'aside' => [
                ['text' => 'Secret', '_group' => 'secret', '_id' => 's1'],
                ['content' => '<p>Text</p>', '_group' => 'text', '_id' => 't1'],
            ],
        ]);
        $record->setAttributeTranslated('aside_translations', ['s1' => ['text' => 'Skrivnost']], 'sl');
        $record->save();

        return Record::find($record->id);
    }

    protected function host(Record $record): ModelHost
    {
        return new ModelHost($record, ['aside'], ['aside']);
    }

    protected function editor(): User
    {
        $user = new User();
        $user->permissions = [];

        return $user;
    }

    public function testEveryLanguagesVersionIsTheStructureWithItsTranslations(): void
    {
        [$saved, $stored] = $this->host($this->record())->versions();

        $this->assertCount(2, $saved);
        $this->assertSame('Secret', $saved[0]['aside'][0]['text']);
        $this->assertSame('Skrivnost', $saved[1]['aside'][0]['text']);
        $this->assertSame('<p>Text</p>', $saved[1]['aside'][1]['content'], 'untranslated: the default value');
        $this->assertSame($saved, $stored);
    }

    public function testTranslationsOfOtherBricksCanBeChanged(): void
    {
        $record = $this->record();
        $record->setAttributeTranslated('aside_translations', ['s1' => ['text' => 'Skrivnost'], 't1' => ['content' => '<p>Besedilo</p>']], 'sl');

        PermissionGuard::check($this->host($record), $this->editor(), $this->theme);

        $this->addToAssertionCount(1);
    }

    public function testChangingATranslationOfABrickWithoutThePermissionIsRejected(): void
    {
        $record = $this->record();
        $record->setAttributeTranslated('aside_translations', ['s1' => ['text' => 'Changed']], 'sl');

        $this->expectException(ValidationException::class);

        PermissionGuard::check($this->host($record), $this->editor(), $this->theme);
    }

    public function testTranslationsOfMissingTypesAreKept(): void
    {
        $structure = [['_group' => 'gone', '_id' => 'g1', 'title' => 'Old'], ['_group' => 'quote', '_id' => 'q1', 'quote' => 'Q']];
        $map = ['g1' => ['title' => 'Star', '_group' => 'x'], 'q1' => ['author' => 'Not translatable']];

        $this->assertSame(
            ['g1' => ['title' => 'Star']],
            SharedTranslations::clean($map, $structure, ['quote' => ['quote']], ['gone']),
        );
    }

    public function testUnknownTypesAreFoundInEveryLanguagesVersion(): void
    {
        $record = $this->record();
        $record->aside = [['_group' => 'gone', '_id' => 'g1', 'title' => 'Old']];
        $record->setAttributeTranslated('aside_translations', ['g1' => ['title' => 'Star']], 'sl');

        $this->assertSame(['title'], UnknownBricks::storedKeys($this->host($record), 'aside')['gone']);
    }
}
