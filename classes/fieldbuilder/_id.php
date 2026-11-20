<?php
/*
 * The brick's stable id (FieldBuilder::ID_FIELD), posted back unchanged. New bricks have none yet: BrickIds assigns
 * one when the page or model is saved.
 */
$id = is_scalar($formValue) ? (string) $formValue : '';
?>
<?php if ($id !== ''): ?>
    <input type="hidden" name="<?= e($formField->getName()) ?>" value="<?= e($id) ?>" data-bricks-id>
<?php endif ?>
