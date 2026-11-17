<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Tests\Fixtures\Components;

use Cms\Classes\ComponentBase;

/**
 * Test component for component bricks.
 */
class Greeting extends ComponentBase
{
    /**
     * @var array<int, string> Names greeted in onRun(), to check the component lifecycle
     */
    public static array $runs = [];

    public function componentDetails(): array
    {
        return ['name' => 'Greeting', 'description' => 'Says hello'];
    }

    public function defineProperties(): array
    {
        return [
            'name' => [
                'title'       => 'Name',
                'description' => 'Who to greet',
                'type'        => 'string',
                'default'     => 'World',
                'validation'  => ['required' => ['message' => 'Required']],
            ],
            'tone' => [
                'title'       => 'Tone',
                'type'        => 'dropdown',
                'placeholder' => 'Pick a tone',
            ],
            'loud' => [
                'title' => 'Loud',
                'type'  => 'checkbox',
            ],
            'size' => [
                'title'   => 'Size',
                'type'    => 'dropdown',
                'options' => ['s' => 'Small', 'l' => 'Large'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getToneOptions(): array
    {
        return ['warm' => 'Warm', 'cool' => 'Cool'];
    }

    public function onRun(): void
    {
        static::$runs[] = (string) $this->property('name');
    }

    /**
     * @return array<string, string>
     */
    public function onGreet(): array
    {
        return ['greeting' => 'Hi ' . $this->property('name')];
    }
}
