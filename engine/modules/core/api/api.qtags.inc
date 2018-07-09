<?php
/**
 * Implements QUANTA qtag.
 *
 * Renders a "Powered by Quanta" link. Support is appreciated!...
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
function qtag_QUANTA($env, $target, $attributes) {

	return '<span class="powered-by-quanta">Powered by <a href="https://www.quantacms.com">Quanta CMS</a></span>';
}
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
