<?php

declare(strict_types=1);

namespace NumenCode\Bricks\FormWidgets;

use Backend\FormWidgets\Repeater;
use Lang;
use NumenCode\Bricks\Classes\BrickHost;
use NumenCode\Bricks\Classes\BrickIds;
use NumenCode\Bricks\Classes\FieldBuilder;
use NumenCode\Bricks\Classes\SharedTranslations;
use NumenCode\Bricks\Classes\UnknownBricks;
use Request;
use Str;
use Winter\Storm\Exception\ApplicationException;
use Winter\Storm\Html\Helper as HtmlHelper;

/**
 * The editor of a bricks field with a shared structure (translation: fields): one list of bricks for every language.
 *
 * - The default language: a plain repeater in group mode – the structure (which bricks, their order, every value).
 * - Another language (the language buttons above the bricks): the same bricks, structure locked; only the fields
 *   marked `translatable: true` are editable, filled with that language's translation, the default value shown as a
 *   placeholder (or a hint below the field); the other fields are read-only.
 *
 * Like Winter.Translate's MLRepeater, the values that aren't shown live in hidden inputs ("lockers", JSON): the
 * structure while another language is shown, and every other language's translations. On save the structure is the
 * field's value, and the translations are stored per language in FieldBuilder::translationsKey() (see
 * SharedTranslations for the format). Without Winter.Translate (or with one language) it is a plain bricks repeater.
 *
 * Renders the core repeater's partials and assets (its view and asset paths come after this widget's own).
 *
 * Bricks inside bricks: in the default language a brick's bricks fields are plain repeaters. While translating, each
 * becomes a SharedBricks "driven by its parent" (config NESTED_OPTION: the default language's bricks of that list and
 * the language's name): locked like the parent, with the same per-field placeholders, without language buttons or
 * lockers of its own; the parent extracts the translations of the whole tree from the posted items.
 *
 * A brick's repeater whose items have translatable fields (e.g. the gallery's images with their captions) is shown the
 * same way while translating: a nested SharedBricks in form mode (NESTED_OPTION "key": "<code>.<repeater field>",
 * see SharedTranslations::translatableOf()), its items locked, their translatable fields editable.
 */
class SharedBricks extends Repeater
{
    public const SWITCH_HANDLER = 'onSwitchBricksLocale';

    public const TRANSLATE_HANDLER = 'onTranslateBricks';

    /**
     * The value of _bricks_translate for every brick (else a brick id).
     */
    public const TRANSLATE_ALL = '*';

    /**
     * Brick form fields left out while translating (options of the structure, not of the language).
     */
    protected const STRUCTURE_FIELDS = ['_advanced', FieldBuilder::ANCHOR_FIELD, FieldBuilder::CLASS_FIELD, FieldBuilder::HIDDEN_FIELD];

    /**
     * Fields kept as they are while translating.
     */
    protected const KEPT_FIELDS = [FieldBuilder::ID_FIELD, UnknownBricks::NOTICE_FIELD];

    /**
     * The config of a nested list while translating: ['structure' => the default language's bricks (or items),
     * 'locale' => the default language's name]; for a repeater's items also 'key' (their translatable fields' key) and
     * 'fields' (those fields).
     */
    public const NESTED_OPTION = 'bricksNested';

    /**
     * @var array<string, mixed>|null NESTED_OPTION of a nested list shown while translating (null: a field of the form)
     */
    protected ?array $nested = null;

    protected $defaultAlias = 'sharedbricks';

    protected string $defaultLocale = '';

    protected string $activeLocale = '';

    /**
     * @var array<string, string> Enabled languages: code => name (empty without Winter.Translate or with one language)
     */
    protected array $locales = [];

    /**
     * @var array<string, array<int, string>> The translatable fields of every brick type (group code => names)
     */
    protected array $translatable = [];

    /**
     * @var array<string, array<int, string>>|null The translatable fields that may be machine-translated (as
     * $translatable, without `machineTranslation: false`)
     */
    protected ?array $machineTranslatable = null;

    /**
     * @var array<int, array<string, mixed>> The default language's bricks while another language is shown
     */
    protected array $structure = [];

    /**
     * @var array<string, array<string, array<string, mixed>>> Translations of the other languages: locale => map
     */
    protected array $translations = [];

    /**
     * @var array<int, array<string, mixed>>|null The items shown while translating (structure + translations)
     */
    protected ?array $translationItems = null;

    public function init()
    {
        $nested = $this->getConfig(self::NESTED_OPTION);

        if (is_array($nested)) {
            // The default language's bricks of this list; the values shown come from the parent brick's form data
            $this->nested = $nested;
            $this->structure = array_values(array_filter((array) ($nested['structure'] ?? []), 'is_array'));
            $this->translatable = isset($nested['key'])
                ? [(string) $nested['key'] => array_map('strval', (array) ($nested['fields'] ?? []))]
                : SharedTranslations::translatableByGroup((array) $this->getConfig('groups', []));
        } else {
            $this->initLocales();
        }

        // Before the repeater makes the item forms (in its init()), which depend on the language shown
        if ($this->locales) {
            $this->translatable = SharedTranslations::translatableByGroup((array) $this->getConfig('groups', []));
            $this->initLockers((bool) post($this->alias . '_loaded'));
        }

        parent::init();

        $this->viewPath = array_values(array_unique(array_merge(
            (array) $this->viewPath,
            [$this->guessViewPathFrom(Repeater::class, '/partials')],
        )));
    }

    /**
     * The core repeater's assets (called by the constructor, before init()).
     */
    protected function loadAssets()
    {
        $this->assetPath = $this->guessViewPathFrom(Repeater::class, '/assets', true);

        parent::loadAssets();
    }

    public function render()
    {
        if (!$this->locales) {
            return parent::render();
        }

        $this->vars['repeater'] = parent::render();
        $this->vars['locales'] = $this->locales;
        $this->vars['defaultLocale'] = $this->defaultLocale;
        $this->vars['activeLocale'] = $this->activeLocale;
        $this->vars['translating'] = $this->isTranslating();
        $this->vars['structureJson'] = $this->isTranslating() ? $this->json($this->structure) : '';
        $this->vars['translationsJson'] = array_map(fn ($map) => $this->json($map), $this->translations);
        $this->vars['switchHandler'] = $this->getEventHandler(self::SWITCH_HANDLER);
        $this->vars['translateHandler'] = $this->getEventHandler(self::TRANSLATE_HANDLER);
        $this->vars['providers'] = $this->isTranslating() ? static::providers() : [];

        return $this->makePartial('sharedbricks');
    }

    /**
     * The structure; the translations of every other language are set on the model.
     */
    public function getSaveValue($value)
    {
        if (!$this->locales) {
            return parent::getSaveValue($value);
        }

        [$structure, $translations] = $this->collect($value);
        $host = $this->host();

        if ($host && ($key = $host->key($this->fieldName)) !== null) {
            foreach ($translations as $locale => $map) {
                $host->setTranslated(FieldBuilder::translationsKey($key), $map, $locale);
            }
        }

        return $structure ?: null;
    }

    /**
     * Shows the bricks in another language (posted: _bricks_locale).
     *
     * @return array<string, string>
     * @throws ApplicationException
     */
    public function onSwitchBricksLocale(): array
    {
        $target = (string) post('_bricks_locale');

        if (!isset($this->locales[$target])) {
            throw new ApplicationException(Lang::get('numencode.bricks::lang.shared.unknown_locale'));
        }

        [$structure, $translations] = $this->collect(post($this->formField->getName()) ?: []);

        $this->activeLocale = $target;
        $this->structure = $structure;
        $this->translations = $translations;
        $this->translationItems = $this->isTranslating()
            ? SharedTranslations::merge($structure, $translations[$target] ?? [], $this->translatable, false)
            : null;

        $this->showItems($this->translationItems ?? $structure);

        return ['#' . $this->getId('shared') => $this->render()];
    }

    /**
     * Machine-translates the default language's values into the language shown, with a Winter.Translate provider
     * (posted: _provider; _bricks_translate = a brick id – all its translatable fields – or TRANSLATE_ALL – the
     * empty translatable fields of every brick).
     *
     * @return array<string, string>
     * @throws ApplicationException
     */
    public function onTranslateBricks(): array
    {
        $providers = static::providers();
        $provider = (string) post('_provider');
        $provider = isset($providers[$provider]) ? $provider : (string) array_key_first($providers);

        if (!$this->isTranslating() || $provider === '') {
            throw new ApplicationException(Lang::get('numencode.bricks::lang.shared.no_provider'));
        }

        [$structure, $translations] = $this->collect(post($this->formField->getName()) ?: []);
        $target = (string) post('_bricks_translate');
        $map = $translations[$this->activeLocale] ?? [];
        $sources = $this->translationSources($structure, $target, $map);

        if ($sources) {
            try {
                $translated = \Winter\Translate\Providers\ProviderFactory::create($provider)->translate(
                    array_column($sources, 2),
                    $this->activeLocale,
                    $this->defaultLocale,
                );
            } catch (\Throwable $exception) {
                throw new ApplicationException(Lang::get('numencode.bricks::lang.shared.translate_failed', [
                    'message' => $exception->getMessage(),
                ]));
            }

            foreach ($sources as $index => [$id, $field]) {
                if (is_string($translated[$index] ?? null)) {
                    $map[$id][$field] = $translated[$index];
                }
            }
        }

        $this->structure = $structure;
        $this->translations = $translations;
        $this->translations[$this->activeLocale] = SharedTranslations::clean($map, $structure, $this->translatable);
        $this->translationItems = SharedTranslations::merge($structure, $this->translations[$this->activeLocale], $this->translatable, false);

        $this->showItems($this->translationItems);

        return ['#' . $this->getId('shared') => $this->render()];
    }

    /**
     * The values to machine-translate: [id, field, value] of the translatable fields of every brick (TRANSLATE_ALL:
     * only the untranslated ones – "all" keeps what is already translated) or of one brick and the bricks inside it
     * (translated again).
     *
     * @param array<int|string, mixed> $bricks
     * @param array<string, array<string, mixed>> $map The language's translations
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    protected function translationSources(array $bricks, string $target, array $map, bool $inTarget = false): array
    {
        $sources = [];

        foreach ($bricks as $brick) {
            if (!is_array($brick)) {
                continue;
            }

            $id = (string) ($brick[FieldBuilder::ID_FIELD] ?? '');
            $code = (string) ($brick['_group'] ?? '');
            $selected = $inTarget || $target === self::TRANSLATE_ALL || $target === $id;
            // The brick, and the items of its repeaters with translatable fields (only those for machine translation)
            $machine = $this->machineTranslatable ??= SharedTranslations::translatableByGroup((array) $this->getConfig('groups', []), true);
            $units = [[$brick, $machine[$code] ?? []]];

            foreach (SharedTranslations::itemLists($brick) as $name => $items) {
                foreach ($items as $item) {
                    $units[] = [$item, $machine[$code . SharedTranslations::ITEM_SEPARATOR . $name] ?? []];
                }
            }

            foreach ($selected ? $units : [] as [$unit, $fields]) {
                $unitId = (string) ($unit[FieldBuilder::ID_FIELD] ?? '');

                foreach (BrickIds::isValid($unitId) ? $fields : [] as $field) {
                    $value = $unit[$field] ?? null;

                    if (!is_string($value) || SharedTranslations::isEmpty($value)) {
                        continue;
                    }

                    if ($target === self::TRANSLATE_ALL && !SharedTranslations::isEmpty($map[$unitId][$field] ?? null)) {
                        continue;
                    }

                    $sources[] = [$unitId, $field, $value];
                }
            }

            foreach (SharedTranslations::nestedLists($brick) as $list) {
                array_push($sources, ...$this->translationSources($list, $target, $map, $selected && $target !== self::TRANSLATE_ALL));
            }
        }

        return $sources;
    }

    /**
     * Winter.Translate's machine translation providers that can translate (with an API key): name => label.
     *
     * @return array<string, string>
     */
    public static function providers(): array
    {
        if (!class_exists(\Winter\Translate\Providers\ProviderFactory::class)) {
            return [];
        }

        $providers = [];

        foreach ((array) \Config::get('winter.translate::providers', []) as $name => $config) {
            if (is_array($config) && !empty($config['key'])) {
                $providers[(string) $name] = (string) ($config['label'] ?? Str::title((string) $name));
            }
        }

        return $providers;
    }

    public function isTranslating(): bool
    {
        return $this->nested !== null || ($this->locales && $this->activeLocale !== $this->defaultLocale);
    }

    /**
     * The name of the default language (in the hints of untranslated fields).
     */
    protected function defaultLocaleName(): string
    {
        if ($this->nested !== null) {
            return (string) ($this->nested['locale'] ?? '');
        }

        return $this->locales[$this->defaultLocale] ?? $this->defaultLocale;
    }

    protected function initLocales(): void
    {
        if (!class_exists(\Winter\Translate\Models\Locale::class) || !\Winter\Translate\Models\Locale::isAvailable()) {
            return;
        }

        $this->locales = array_map('strval', \Winter\Translate\Models\Locale::listEnabled());
        $this->defaultLocale = (string) \Winter\Translate\Classes\Translator::instance()->getDefaultLocale();
        $posted = post($this->alias . '_locale');
        $this->activeLocale = is_string($posted) && isset($this->locales[$posted]) ? $posted : $this->defaultLocale;
    }

    /**
     * The lockers: posted (the form was loaded), else the stored translations.
     */
    protected function initLockers(bool $loaded): void
    {
        $others = array_diff(array_keys($this->locales), [$this->defaultLocale]);

        if ($loaded) {
            $posted = (array) post($this->alias . '_translations', []);

            foreach ($others as $locale) {
                $this->translations[$locale] = static::decode($posted[$locale] ?? null);
            }

            if ($this->isTranslating()) {
                $this->structure = array_values(array_filter(static::decode(post($this->alias . '_structure')), 'is_array'));
                $postedMap = SharedTranslations::extract((array) (post($this->formField->getName()) ?: []), $this->translatable);
                $this->translationItems = SharedTranslations::merge($this->structure, $postedMap, $this->translatable, false);
            }

            return;
        }

        $host = $this->host();
        $key = $host?->key($this->fieldName);

        foreach ($others as $locale) {
            $this->translations[$locale] = $host && $key !== null
                ? $host->translated(FieldBuilder::translationsKey($key), $locale)
                : [];
        }
    }

    protected function processGroupMode()
    {
        parent::processGroupMode();

        // A repeater's items (form mode) keep the fields given by the parent brick
        if ($this->useGroups) {
            $this->translatable = SharedTranslations::translatableByGroup((array) $this->groupDefinitions);
        }
    }

    /**
     * While translating, an item's form has only the language's fields editable (see translationFields()).
     */
    protected function makeItemFormWidget($index = 0, $groupCode = null)
    {
        // A repeater's items (form mode)
        if ($this->nested !== null && isset($this->nested['key']) && !$this->useGroups) {
            $form = $this->form;
            $fields = (array) ($this->makeConfig($form)->fields ?? []);
            $this->form = ['fields' => $this->translationFields($fields, (string) $this->nested['key'], $this->structureBrick($index))];

            try {
                return parent::makeItemFormWidget($index, $groupCode);
            } finally {
                $this->form = $form;
            }
        }

        if (!$this->isTranslating() || !$groupCode || !isset($this->groupDefinitions[$groupCode])) {
            return parent::makeItemFormWidget($index, $groupCode);
        }

        $fields = $this->groupDefinitions[$groupCode]['fields'];
        $brick = $this->structureBrick($index);
        $this->groupDefinitions[$groupCode]['fields'] = $this->translationFields((array) $fields, (string) $groupCode, $brick);

        try {
            return parent::makeItemFormWidget($index, $groupCode);
        } finally {
            $this->groupDefinitions[$groupCode]['fields'] = $fields;
        }
    }

    /**
     * The default language's brick of an item: by its id (a nested list's items come from the parent's form data), else
     * at its position.
     *
     * @return array<string, mixed>
     */
    protected function structureBrick(mixed $index): array
    {
        $id = ((array) $this->getValueFromIndex($index))[FieldBuilder::ID_FIELD] ?? null;

        foreach (BrickIds::isValid($id) ? $this->structure : [] as $brick) {
            if (($brick[FieldBuilder::ID_FIELD] ?? null) === $id) {
                return $brick;
            }
        }

        return $this->structure[(int) $index] ?? [];
    }

    protected function getValueFromIndex($index)
    {
        if ($this->translationItems !== null) {
            return $this->translationItems[(int) $index] ?? [];
        }

        return parent::getValueFromIndex($index);
    }

    /**
     * A brick type's fields while translating: translatable fields editable (the default value as a placeholder or
     * hint), other fields read-only, the structure's options left out; a bricks field shows its bricks the same way (a
     * nested SharedBricks, NESTED_OPTION).
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $brick The brick in the default language
     * @return array<string, mixed>
     */
    protected function translationFields(array $fields, string $groupCode, array $brick): array
    {
        $translatable = $this->translatable[$groupCode] ?? [];
        $result = [];
        $hasNested = false;

        foreach ($fields as $name => $config) {
            if (in_array($name, self::STRUCTURE_FIELDS, true)) {
                continue;
            }

            if (in_array($name, self::KEPT_FIELDS, true) || !is_array($config)) {
                $result[$name] = $config;
            } elseif ($itemFields = $this->translatable[$groupCode . SharedTranslations::ITEM_SEPARATOR . $name] ?? []) {
                // A repeater whose items have translatable fields (e.g. the gallery's captions)
                $hasNested = true;
                $result[$name] = array_merge($config, [
                    'type'              => FieldBuilder::SHARED_WIDGET,
                    self::NESTED_OPTION => [
                        'structure' => is_array($brick[$name] ?? null) ? array_values($brick[$name]) : [],
                        'locale'    => $this->defaultLocaleName(),
                        'key'       => $groupCode . SharedTranslations::ITEM_SEPARATOR . $name,
                        'fields'    => $itemFields,
                    ],
                ]);
            } elseif (isset($config['containerAttributes']['data-bricks'])) {
                $hasNested = true;
                $result[$name] = array_merge($config, [
                    'type'              => FieldBuilder::SHARED_WIDGET,
                    self::NESTED_OPTION => [
                        'structure' => is_array($brick[$name] ?? null) ? array_values($brick[$name]) : [],
                        'locale'    => $this->defaultLocaleName(),
                    ],
                ]);
            } elseif (in_array($name, $translatable, true)) {
                $result[$name] = $this->withDefaultHint($config, $brick[$name] ?? null);
            } else {
                $result[$name] = ['readOnly' => true] + $config;
            }
        }

        if (!$translatable && !$hasNested && !str_contains($groupCode, SharedTranslations::ITEM_SEPARATOR)) {
            $result = ['_bricks_nothing' => [
                'type'    => 'section',
                'comment' => Lang::get('numencode.bricks::lang.shared.nothing'),
            ]] + $result;
        }

        return $result;
    }

    /**
     * Shows the default value: as the placeholder of a text input, else below the field (and, muted, in the collapsed
     * brick's title while the field is empty).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function withDefaultHint(array $config, mixed $default): array
    {
        if (!is_scalar($default) || SharedTranslations::isEmpty($default)) {
            return $config;
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $default), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // A collapsed brick's title shows it (muted) while the field is empty (assets/js/bricks.js)
        $config['containerAttributes'] = array_merge((array) ($config['containerAttributes'] ?? []), [
            'data-bricks-default' => Str::limit($text, 200),
        ]);

        if (in_array($config['type'] ?? 'text', ['text', 'textarea'], true)) {
            return ['placeholder' => $text] + $config;
        }

        return array_merge($config, [
            'comment'     => Lang::get('numencode.bricks::lang.shared.default_value', [
                'locale' => $this->defaultLocaleName(),
                'value'  => Str::limit($text, 120),
            ]),
            'commentHtml' => false,
        ]);
    }

    /**
     * The structure and every other language's translations, from the posted items and lockers.
     *
     * @param mixed $value The posted items (of the language shown)
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, array<string, mixed>>>}
     */
    protected function collect(mixed $value): array
    {
        $items = is_array($value) && $value ? (array) parent::getSaveValue($value) : [];

        // Ids now (also of the bricks inside bricks), so the translations of new bricks can be stored under them
        $structure = BrickIds::bricks(array_values(array_filter(
            $this->isTranslating() ? $this->structure : $items,
            'is_array',
        )), $this->host()?->types() ?? []);

        $translations = [];
        $unknown = $this->unknownCodes();

        foreach ($this->translations as $locale => $map) {
            if ($this->isTranslating() && $locale === $this->activeLocale) {
                // Bricks of missing types show nothing to edit: their stored translations stay
                $map = SharedTranslations::extract($items, $this->translatable) + array_intersect_key(
                    $map,
                    array_flip(array_column(array_filter(SharedTranslations::flatten($structure), fn ($brick) => in_array($brick['_group'] ?? '', $unknown, true)), FieldBuilder::ID_FIELD)),
                );
            }

            $translations[$locale] = SharedTranslations::clean($map, $structure, $this->translatable, $unknown);
        }

        return [$structure, $translations];
    }

    /**
     * The groups of brick types that no longer exist (UnknownBricks: their form has the notice field).
     *
     * @return array<int, string>
     */
    protected function unknownCodes(): array
    {
        $codes = [];

        foreach ((array) $this->getConfig('groups', []) as $code => $group) {
            if (isset($group['fields'][UnknownBricks::NOTICE_FIELD])) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /**
     * Replaces the repeater's items (as if they were posted) and makes their forms again.
     *
     * @param array<int, array<string, mixed>> $items
     */
    protected function showItems(array $items): void
    {
        $this->formWidgets = [];
        $this->formField->value = $items;

        $requestData = Request::all();
        array_set($requestData, implode('.', HtmlHelper::nameToArray($this->formField->getName())), $items);
        Request::merge($requestData);

        $this->processItems();
    }

    protected function host(): ?BrickHost
    {
        return is_object($this->model) ? BrickHost::of($this->model, []) : null;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected static function decode(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    protected function json(array $value): string
    {
        return json_encode($value ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
