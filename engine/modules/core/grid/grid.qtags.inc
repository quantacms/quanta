<?php

function qtag_GRID_CAROUSEL($env, $target, $attributes) {
	$attributes['module'] = 'grid';
  $attributes['tpl'] = 'grid';
  $attributes['flickity_theme'] = 'actionbutton';
  $attributes['pageDots'] = 'false';
  $attributes['editable'] = 'false';
    
	return qtag_CAROUSEL($env, $target, $attributes);
}