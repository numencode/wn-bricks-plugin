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
     * @param array<int, string> $sharedKeys The ones with a shared structure (translation: fields)
     */
    public function __construct(object $model, protected array $fieldKeys, protected array $sharedKeys = [])
    {
        parent::__construct($model);
    }

    public function fields(): array
    {
        return $this->fieldKeys;
    }

    /**
     * Models use the active theme's brick types (the website's), like their forms (ModelIntegration).
     */
    public function theme(): ?\Cms\Classes\Theme
    {
        return \Cms\Classes\Theme::getActiveTheme();
    }

    public function sharedFields(): array
    {
        return array_values(array_intersect($this->fieldKeys, $this->sharedKeys));
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
        return $this->usesTranslate()
            && !$this->isShared($field)
            && in_array($field, (array) $this->model->getTranslatableAttributes(), true);
    }

    protected function usesTranslate(): bool
    {
        return method_exists($this->model, 'isClassExtendedWith')
            && $this->model->isClassExtendedWith('Winter.Translate.Behaviors.TranslatableModel');
    }

    /**
     * The translations attribute has no column: Winter.Translate stores it per language, and the default language
     * never sets it.
     *
     * @throws \Winter\Storm\Exception\SystemException When the model declares the shared list itself translatable
     */
    public function registerTranslations(string $field): void
    {
        if (!$this->usesTranslate()) {
            return;
        }

        $translatable = (array) $this->model->getTranslatableAttributes();

        if (in_array($field, $translatable, true)) {
            throw new \Winter\Storm\Exception\SystemException(sprintf(
                'The bricks field "%s" of %s has a shared structure (translation: fields), so it must not be translatable itself: remove it from $translatable (its translations are stored in "%s").',
                $field,
                get_class($this->model),
                FieldBuilder::translationsKey($field),
            ));
        }

        if (!in_array($key = FieldBuilder::translationsKey($field), $translatable, true)) {
            $this->model->addTranslatableAttributes($key);
        }
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
