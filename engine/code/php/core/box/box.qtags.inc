<?php
/**
 * Implements BOX qtag.
 *
 * A simple block, boxed and non editable by default.
 */
function qtag_BOX($env, $target, $attributes) {
  // Check if the box is editable.
  if (!isset($attributes['editable'])) {
    $attributes['editable'] = BOX_NON_EDITABLE;
  }
  // TODO: accept sizing variables.
  $box = new Box($env, NULL, NULL, $attributes);
  if (!empty($attributes['box'])) {
    unset($attributes['box']);
  }
  $box->setHtml(qtag_BLOCK($env, $target, $attributes));
  return $box->render();
}

/**
 * Implements qtag_CLOSE.
 *
 * Ajax-close a container.
 */
function qtag_CLOSE($env, $target, $attributes) {
  return '<a class="close-button" href="#">X Close</a>';
}