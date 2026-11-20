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
    data-switch-handler="<?= e($switchHandler) ?>">

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
