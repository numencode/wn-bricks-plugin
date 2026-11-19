<?php

declare(strict_types=1);

namespace NumenCode\Bricks\Classes;

/**
 * Where the bricks fields of a backend form are stored: a static page's viewBag (PageHost) or attributes of a model
 * (ModelHost). Gives the save checks (PermissionGuard, EmptyOptions) and UnknownBricks one way to read and write a
 * field's bricks – the values being saved and the stored ones, in the default language and (with Winter.Translate)
 * every other enabled language.
 *
 * A field is named by its key: the viewBag key ("bricks") or the attribute ("bricks"); formName() gives the form
 * field name ("viewBag[bricks]" or "bricks").
 */
abstract class BrickHost
{
    public function __construct(public readonly object $model)
    {
    }

    /**
     * Wraps a static page or model; a host stays itself.
     *
     * @param array<int, string>|null $fields A model's bricks attributes (a static page reads its layout's fields)
     */
    public static function of(object $model, ?array $fields = null): self
    {
        if ($model instanceof self) {
            return $model;
        }

        if (class_exists(\Winter\Pages\Classes\Page::class) && $model instanceof \Winter\Pages\Classes\Page) {
            return new PageHost($model, $fields);
        }

        return new ModelHost($model, (array) $fields);
    }

    /**
     * The keys of the bricks fields.
     *
     * @return array<int, string>
     */
    abstract public function fields(): array;

    /**
     * The form field name of a field key.
     */
    abstract public function formName(string $field): string;

    /**
     * The field key of a form field name, or null when it isn't one of this host's possible fields.
     */
    abstract public function key(string $formName): ?string;

    /**
     * The bricks being saved (or loaded) in the default language.
     *
     * @return array<int|string, mixed>
     */
    abstract public function current(string $field): array;

    /**
     * @param array<int|string, mixed> $bricks
     */
    abstract public function setCurrent(string $field, array $bricks): void;

    /**
     * The stored bricks in the default language ([] for a new record).
     *
     * @return array<int|string, mixed>
     */
    abstract public function stored(string $field): array;

    /**
     * Whether a field has translations (Winter.Translate on this model, and the field translatable).
     */
    abstract public function isTranslatable(string $field): bool;

    /**
     * The attribute Winter.Translate stores a field's translation under.
     */
    abstract protected function translationKey(string $field): string;

    /**
     * A field's value in an array of translated attributes.
     *
     * @param array<string, mixed> $attributes
     */
    abstract protected function translatedValue(array $attributes, string $field): mixed;

    /**
     * Makes a field translatable, when the host decides that itself (a static page; a model declares it).
     */
    public function makeTranslatable(string $field): void
    {
    }

    /**
     * The enabled languages other than the default one (none without Winter.Translate).
     *
     * @return array<int, string>
     */
    public function otherLocales(): array
    {
        if (!class_exists(\Winter\Translate\Models\Locale::class)) {
            return [];
        }

        $default = \Winter\Translate\Classes\Translator::instance()->getDefaultLocale();

        return array_values(array_filter(
            array_map('strval', array_keys(\Winter\Translate\Models\Locale::listEnabled())),
            fn (string $locale) => $locale !== $default,
        ));
    }

    /**
     * The bricks being saved in another language (the stored ones when that language wasn't edited).
     *
     * @return array<int|string, mixed>
     */
    public function translated(string $field, string $locale): array
    {
        return static::decode($this->translatedValue((array) $this->model->getTranslateAttributes($locale), $field));
    }

    /**
     * The stored bricks in another language.
     *
     * @return array<int|string, mixed>
     */
    public function storedTranslated(string $field, string $locale): array
    {
        return static::decode($this->translatedValue((array) $this->model->getTranslatableOriginals($locale), $field));
    }

    /**
     * @param array<int|string, mixed> $bricks
     */
    public function setTranslated(string $field, array $bricks, string $locale): void
    {
        $this->model->setAttributeTranslated($this->translationKey($field), $bricks, $locale);
    }

    /**
     * Every list of bricks: [being saved, stored], each a list of [field key => bricks] per language.
     *
     * @return array{0: array<int, array<string, array<int|string, mixed>>>, 1: array<int, array<string, array<int|string, mixed>>>}
     */
    public function versions(): array
    {
        $saved = [];
        $stored = [];

        foreach ($this->fields() as $field) {
            $saved[0][$field] = $this->current($field);
            $stored[0][$field] = $this->stored($field);

            if (!$this->isTranslatable($field)) {
                continue;
            }

            foreach ($this->otherLocales() as $index => $locale) {
                // Loads the stored translation first, when it isn't loaded yet (then both are the stored version)
                $saved[$index + 1][$field] = $this->translated($field, $locale);
                $stored[$index + 1][$field] = $this->storedTranslated($field, $locale);
            }
        }

        return [array_values($saved), array_values($stored)];
    }

    /**
     * A list of bricks from a stored value: an array, or JSON (a model's jsonable attribute before it is decoded).
     *
     * @return array<int|string, mixed>
     */
    public static function decode(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }
}
