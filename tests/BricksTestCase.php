<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests;

use System\Tests\Bootstrap\PluginTestCase;

/**
 * Base test case: loads Winter.Pages and Winter.Translate when they are installed.
 */
abstract class BricksTestCase extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (class_exists(\Winter\Pages\Plugin::class)) {
            $this->instantiatePlugin('Winter.Pages');
        }

        if (class_exists(\Winter\Translate\Plugin::class)) {
            $this->instantiatePlugin('Winter.Translate');
        }
    }
}
