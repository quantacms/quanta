<?php

// TODO: Load singleton library...

/**
 * Implements qtag FA_ICON.
 *
 * Renders a FontAwesome icon.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_FA_ICON($env, $target, $attributes) {
  return '<i class="fa fa-' . $target . '"></i>';
}

