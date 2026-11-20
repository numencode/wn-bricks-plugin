<?php
/*
 * A bricks field with a shared structure (SharedBricks): the language buttons, the repeater (in the language shown)
 * and the lockers – the structure while another language is shown, and every other language's translations (JSON).
 * assets/js/bricks.js switches the language with the switch handler.
 */
?>
<div
    id="<?= $this->getId('shared') ?>"
    class="bricks-shared<?= $translating ? ' is-translating' : '' ?>"
    data-shared-bricks
    data-switch-handler="<?= e($switchHandler) ?>"
    <?php if ($providers): ?>
        data-translate-handler="<?= e($translateHandler) ?>"
        data-translate-label="<?= e(trans('numencode.bricks::lang.shared.translate_brick', ['default' => $locales[$defaultLocale] ?? $defaultLocale])) ?>"
    <?php endif ?>>

    <div class="bricks-locales" role="group" aria-label="<?= e(trans('numencode.bricks::lang.shared.language')) ?>">
        <span class="bricks-locales-label"><?= e(trans('numencode.bricks::lang.shared.language')) ?></span>
        <?php foreach ($locales as $code => $name): ?>
            <button
                type="button"
                class="btn btn-xs <?= $code === $activeLocale ? 'btn-primary active' : 'btn-default' ?>"
                data-bricks-locale="<?= e($code) ?>"
                title="<?= e($name) ?>"
                aria-pressed="<?= $code === $activeLocale ? 'true' : 'false' ?>">
                <?= e(strtoupper((string) $code)) ?>
            </button>
        <?php endforeach ?>

        <?php if ($providers): ?>
            <span class="bricks-machine-translation">
                <?php if (count($providers) > 1): ?>
                    <select class="form-control input-sm" data-bricks-provider aria-label="<?= e(trans('numencode.bricks::lang.shared.provider')) ?>">
                        <?php foreach ($providers as $name => $label): ?>
                            <option value="<?= e($name) ?>"><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" data-bricks-provider value="<?= e((string) array_key_first($providers)) ?>">
                <?php endif ?>
                <button type="button" class="btn btn-xs btn-default" data-bricks-translate="*">
                    <?= e(trans('numencode.bricks::lang.shared.translate_all', ['default' => $locales[$defaultLocale] ?? $defaultLocale])) ?>
                </button>
            </span>
        <?php endif ?>
    </div>

    <?php if ($translating): ?>
        <p class="bricks-translating-notice">
            <?= e(trans('numencode.bricks::lang.shared.translating', [
                'locale'  => $locales[$activeLocale] ?? $activeLocale,
                'default' => $locales[$defaultLocale] ?? $defaultLocale,
            ])) ?>
        </p>
    <?php endif ?>

    <?= $repeater ?>

    <input type="hidden" name="<?= e($this->alias . '_locale') ?>" value="<?= e($activeLocale) ?>" data-bricks-active-locale>
    <?php if ($translating): ?>
        <input type="hidden" name="<?= e($this->alias . '_structure') ?>" value="<?= e($structureJson) ?>">
    <?php endif ?>
    <?php foreach ($translationsJson as $locale => $json): ?>
        <input type="hidden" name="<?= e($this->alias . '_translations[' . $locale . ']') ?>" value="<?= e($json) ?>">
    <?php endforeach ?>
</div>
