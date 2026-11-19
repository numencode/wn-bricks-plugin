<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Fixtures\Models;

use Winter\Storm\Database\Model;

/**
 * Test model with a translatable bricks attribute (table created by ModelIntegrationTest).
 */
class Record extends Model
{
    public const TABLE = 'numencode_bricks_test_records';

    public $table = self::TABLE;

    public $implement = ['@Winter.Translate.Behaviors.TranslatableModel'];

    /**
     * @var array<int, string>
     */
    public $translatable = ['bricks'];

    /**
     * @var array<int, string>
     */
    protected $jsonable = ['bricks', 'aside'];

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
