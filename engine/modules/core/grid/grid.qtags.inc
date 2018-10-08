<?php
/**
 * Implements GRID_CAROUSEL qtag.
 *
 * A carousel allowing use of grid layout.
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
function qtag_GRID_CAROUSEL($env, $target, $attributes) {
	$attributes['module'] = 'grid';
  $attributes['tpl'] = 'grid';
  $attributes['flickity_theme'] = 'actionbutton';
  $attributes['pageDots'] = 'true';
  $attributes['editable'] = 'false';
    
	return qtag_CAROUSEL($env, $target, $attributes);
}
