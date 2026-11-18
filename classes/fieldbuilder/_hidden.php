<?php
/*
 * Whether the brick is hidden on the website (FieldBuilder::HIDDEN_FIELD). assets/js/bricks.js toggles it with the
 * eye button: enabled = hidden (posts 1), disabled = shown (not posted, so nothing is stored).
 */
$hidden = !empty($formValue);
?>
<input
    type="hidden"
    name="<?= e($formField->getName()) ?>"
    value="1"
    data-bricks-hidden-flag
    <?= $hidden ? '' : 'disabled' ?>>
