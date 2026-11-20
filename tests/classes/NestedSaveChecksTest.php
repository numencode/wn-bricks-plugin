<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Classes;

use Backend\Models\User;
use Cms\Classes\Theme;
use Illuminate\Database\Schema\Blueprint;
use NumenCode\Bricks\Classes\BrickDuplicator;
use NumenCode\Bricks\Classes\BrickIds;
use NumenCode\Bricks\Classes\BrickRegistry;
use NumenCode\Bricks\Classes\EmptyOptions;
use NumenCode\Bricks\Classes\ModelHost;
use NumenCode\Bricks\Classes\PermissionGuard;
use NumenCode\Bricks\Classes\UnknownBricks;
use NumenCode\Bricks\Tests\BricksTestCase;
use NumenCode\Bricks\Tests\Fixtures\Models\Record;
use Schema;
use Winter\Storm\Exception\ValidationException;

/**
 * The save checks with bricks inside bricks (the fixture container "box" with its bricks field "inner") on a
 * model's untranslated "aside" field.
 */
class NestedSaveChecksTest extends BricksTestCase
{
    protected Theme $theme;

    public function setUp(): void
    {
        parent::setUp();

        require_once $this->fixturePath('models/Record.php');

        Schema::create(Record::TABLE, function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->mediumText('bricks')->nullable();
            $table->mediumText('aside')->nullable();
            $table->timestamps();
        });

        $this->theme = $this->useFixtureTheme();
        BrickRegistry::instance()->registerCallback(fn (BrickRegistry $registry) => $registry->registerBricks([
            'secret' => $this->fixturePath('bricks/secret'),
            'box'    => $this->fixturePath('bricks/box'),
        ], 'Test'));
    }

    public function tearDown(): void
    {
        Schema::dropIfExists(Record::TABLE);

        parent::tearDown();
    }

    protected function record(): Record
    {
        $record = Record::create(['title' => 'Post', 'aside' => [
            ['_group' => 'box', '_id' => 'b1', 'title' => 'Box', 'inner' => [
                ['_group' => 'secret', '_id' => 's1', 'text' => 'Secret'],
                ['_group' => 'text', '_id' => 't1', 'content' => '<p>Text</p>'],
            ]],
        ]]);

        return Record::find($record->id);
    }

    protected function host(Record $record): ModelHost
    {
        return new ModelHost($record, ['aside']);
    }

    protected function editor(): User
    {
        $user = new User();
        $user->permissions = [];

        return $user;
    }

    public function testANestedBrickWithoutThePermissionMayBeMovedButNotChanged(): void
    {
        $record = $this->record();
        $aside = $record->aside;
        $secret = $aside[0]['inner'][0];
        unset($aside[0]['inner'][0]);
        $aside[0]['inner'][1]['content'] = '<p>Changed</p>';
        $aside[] = $secret + ['_hidden' => 1, '_anchor' => 'moved'];
        $record->aside = $aside;

        PermissionGuard::check($this->host($record), $this->editor(), $this->theme);
        $this->addToAssertionCount(1);

        $aside[1]['text'] = 'Changed';
        $record->aside = $aside;

        $this->expectException(ValidationException::class);
        PermissionGuard::check($this->host($record), $this->editor(), $this->theme);
    }

    public function testANewNestedBrickWithoutThePermissionIsRejected(): void
    {
        $record = $this->record();
        $aside = $record->aside;
        $aside[0]['inner'][] = ['_group' => 'secret', 'text' => 'New'];
        $record->aside = $aside;

        $this->expectException(ValidationException::class);
        PermissionGuard::check($this->host($record), $this->editor(), $this->theme);
    }

    public function testOptionsOfNestedBricksArentContent(): void
    {
        $box = ['_group' => 'box', 'title' => 'Box', 'inner' => [['_group' => 'text', 'content' => 'A', '_id' => 'x']]];
        $changed = $box;
        $changed['inner'][0] += ['_hidden' => 1, '_class' => 'wide'];
        $changed['inner'][0]['_id'] = 'y';

        $this->assertSame(PermissionGuard::fingerprint($box), PermissionGuard::fingerprint($changed));

        $changed['inner'][0]['content'] = 'B';
        $this->assertNotSame(PermissionGuard::fingerprint($box), PermissionGuard::fingerprint($changed));
    }

    public function testEmptyOptionsAndIdsOfNestedBricks(): void
    {
        $record = $this->record();
        $aside = $record->aside;
        $aside[0]['inner'][1] += ['_anchor' => ' ', '_class' => 'kept'];
        $aside[0]['inner'][] = ['_group' => 'text', 'content' => 'New', '_hidden' => ''];
        $record->aside = $aside;
        $host = $this->host($record);

        EmptyOptions::clean($host);
        BrickIds::assign($host);
        $inner = $record->aside[0]['inner'];

        $this->assertArrayNotHasKey('_anchor', $inner[1]);
        $this->assertSame('kept', $inner[1]['_class']);
        $this->assertArrayNotHasKey('_hidden', $inner[2]);
        $this->assertSame('t1', $inner[1]['_id']);
        $this->assertTrue(BrickIds::isValid($inner[2]['_id']));
    }

    public function testMissingTypesInsideContainersAreFound(): void
    {
        $record = $this->record();
        $aside = $record->aside;
        $aside[0]['inner'][] = ['_group' => 'gone', 'title' => 'Old', 'items' => ['a']];
        $record->aside = $aside;

        $types = UnknownBricks::types($this->host($record), 'aside', BrickRegistry::instance()->all($this->theme));

        $this->assertSame(['gone'], array_keys($types));
        $this->assertArrayHasKey('items', $types['gone']->fields);
    }

    public function testACopyLosesTheIdsOfTheBricksInsideIt(): void
    {
        $copy = BrickDuplicator::withoutIds(['_group' => 'box', '_id' => 'b1', 'inner' => [
            ['_group' => 'text', '_id' => 't1', 'content' => 'A'],
        ], 'images' => [['image' => 'a.jpg', '_id' => 'not a brick']]]);

        $this->assertArrayNotHasKey('_id', $copy);
        $this->assertArrayNotHasKey('_id', $copy['inner'][0]);
        $this->assertSame('A', $copy['inner'][0]['content']);
        $this->assertSame('not a brick', $copy['images'][0]['_id'], 'only bricks (values with a type)');
    }
}
