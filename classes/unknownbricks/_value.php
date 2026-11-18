<?php
/*
 * One stored value of a brick whose type no longer exists, posted back unchanged as hidden inputs (nested values as
 * nested names). See NumenCode\Bricks\Classes\UnknownBricks.
 */
$render = function (string $name, mixed $value) use (&$render): void {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $render($name . '[' . $key . ']', $item);
        }

        return;
    }

    if ($value === null) {
        return;
    }

    echo '<input type="hidden" name="' . e($name) . '" value="' . e((string) (is_bool($value) ? (int) $value : $value)) . '">';
};

$render($formField->getName(), $formValue);
