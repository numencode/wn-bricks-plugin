<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * The bricks fields of a model (e.g. a blog post): attributes holding a list of bricks – jsonable, and translatable
 * when the model uses Winter.Translate's TranslatableModel and lists the attribute in $translatable.
 */
class ModelHost extends BrickHost
{
    /**
     * @param array<int, string> $fieldKeys The bricks attributes (from the form's "type: bricks" fields)
     */
    public function __construct(object $model, protected array $fieldKeys)
    {
        parent::__construct($model);
    }

    public function fields(): array
    {
        return $this->fieldKeys;
    }

    public function formName(string $field): string
    {
        return $field;
    }

    public function key(string $formName): ?string
    {
        // Plain attributes only ("bricks", or "bricks@update" for one form context); "relation[attribute]" fields belong
        // to another model
        $name = explode('@', $formName)[0];

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : null;
    }

    public function current(string $field): array
    {
        return static::decode($this->model->getAttribute($field));
    }

    public function setCurrent(string $field, array $bricks): void
    {
        $this->model->setAttribute($field, $bricks);
    }

    public function stored(string $field): array
    {
        return $this->model->exists ? static::decode($this->model->getOriginal($field)) : [];
    }

    public function isTranslatable(string $field): bool
    {
        return method_exists($this->model, 'isClassExtendedWith')
            && $this->model->isClassExtendedWith('Winter.Translate.Behaviors.TranslatableModel')
            && in_array($field, (array) $this->model->getTranslatableAttributes(), true);
    }

    protected function translationKey(string $field): string
    {
        return $field;
    }

    protected function translatedValue(array $attributes, string $field): mixed
    {
        return $attributes[$field] ?? null;
    }
}
