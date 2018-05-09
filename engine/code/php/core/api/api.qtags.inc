<?php
/**
 * Implements RANDOM qtag.
 *
 * Renders a random number.
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
function qtag_RANDOM($env, $target, $attributes) {
  $min = isset($attributes['min']) ? $attributes['min'] : 0;
  $max = isset($attributes['max']) ? $attributes['max'] : 1000000;

  return rand($min, $max);

}
