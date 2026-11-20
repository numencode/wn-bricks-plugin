<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

use Winter\Pages\Classes\Page;

/**
 * The bricks fields of a Winter.Pages static page: keys of its viewBag (form fields "viewBag[<key>]"), the layout's
 * {variable type="bricks"} fields. Translations: Winter.Translate's TranslatableCmsObject, attribute "viewBag[<key>]".
 */
class PageHost extends BrickHost
{
    /**
     * @param array<int, string>|null $fields The field keys (default: the layout's bricks fields)
     */
    public function __construct(Page $page, protected ?array $fieldKeys = null)
    {
        parent::__construct($page);
    }

    public function fields(): array
    {
        return $this->fieldKeys ??= BrickRenderer::fieldNames($this->model);
    }

    public function theme(): ?\Cms\Classes\Theme
    {
        return $this->model->getThemeAttribute() ?? \Cms\Classes\Theme::getEditTheme();
    }

    public function sharedFields(): array
    {
        return array_values(array_intersect($this->fields(), BrickRenderer::sharedFieldNames($this->model)));
    }

    public function formName(string $field): string
    {
        return 'viewBag[' . $field . ']';
    }

    public function key(string $formName): ?string
    {
        return preg_match('/^viewBag\[([^\]]+)\]$/', $formName, $match) ? $match[1] : null;
    }

    public function current(string $field): array
    {
        return static::decode(((array) ($this->model->attributes['viewBag'] ?? []))[$field] ?? null);
    }

    public function setCurrent(string $field, array $bricks): void
    {
        $viewBag = (array) ($this->model->attributes['viewBag'] ?? []);
        $viewBag[$field] = $bricks;
        $this->model->attributes['viewBag'] = $viewBag;
    }

    public function stored(string $field): array
    {
        return $this->model->exists ? static::decode(((array) $this->model->getOriginal('viewBag'))[$field] ?? null) : [];
    }

    public function isTranslatable(string $field): bool
    {
        return $this->usesTranslate() && !$this->isShared($field);
    }

    protected function usesTranslate(): bool
    {
        return $this->model->isClassExtendedWith('Winter.Translate.Behaviors.TranslatableCmsObject');
    }

    public function registerTranslations(string $field): void
    {
        $this->makeTranslatable(FieldBuilder::translationsKey($field));
    }

    public function makeTranslatable(string $field): void
    {
        $name = $this->formName($field);

        if (!in_array($name, $this->model->translatable, true)) {
            $this->model->translatable[] = $name;
        }
    }

    protected function translationKey(string $field): string
    {
        return $this->formName($field);
    }

    protected function translatedValue(array $attributes, string $field): mixed
    {
        return ((array) ($attributes['viewBag'] ?? []))[$field] ?? null;
    }
}
