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

  if (Amp::isActive($env)) {
    // Google AMP carousel.
    $attributes['module'] = 'grid';
    $attributes['tpl'] = 'grid-carousel-amp';
    $attributes['carousel_width'] = '400';
    $attributes['carousel_height'] = '260';
    $attributes['carousel_autoplay'] = 'true';
    return qtag_AMP_CAROUSEL($env, $target, $attributes);

  } else {
    // Classic flickity carousel.
    $attributes['module'] = 'grid';
    $attributes['tpl'] = 'grid-carousel';
    $attributes['flickity_theme'] = 'actionbutton';
    $attributes['pageDots'] = 'true';
    $attributes['editable'] = 'false';	  

    return qtag_CAROUSEL($env, $target, $attributes);
  }

}
