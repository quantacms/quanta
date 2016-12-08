<?php

/**
 * Detect content JUMPER and eventually create HTML.
 */
function wiki_PRODUCTS($attributes, $value, $string, $env) {

  $dirlist = new ProductList($env, $value, 'products', array('sortbytime' => 'asc') + $attributes);
  return $dirlist->render();
}

/**
 * Detect content JUMPER and eventually create HTML.
 */
function wiki_PRODUCTATTRIBUTE($attributes, $value, $string, $env) {
  $node = empty($node) ? $env->getData('node') : new Node($env, $node);

  switch($attributes['name']) {

    case 'price':
        return $node->json->price;
      break;

    case 'availability':
      return $node->json->availability;
      break;

    default:
      return '';
      break;
  }
}

function wiki_PRODUCTADD($attributes, $father, $string, $env) {
  $father = empty($father) ? $env->getData('node') : new Node($env, $father);
  $type = isset($attributes['type']) ? $attributes['type'] : 'node';
  $widget = isset($attributes['widget']) ? $attributes['widget'] : 'tabs';
  $label = isset($attributes['label']) ? $attributes['label'] : 'add';
  return '<a href="#" data-type="' . $type . '" data-widget = "' . $widget . '" class="product-add-link" rel="' . $father->name . '">' . $label . '</a>';
}

function wiki_PRODUCTEDIT($attributes, $node, $string, $env) {
  $node = empty($node) ? $env->getData('node') : new Node($env, $node);
  $type = isset($attributes['type']) ? $attributes['type'] : 'node';
  $widget = isset($attributes['widget']) ? $attributes['widget'] : 'tabs';
  $label = isset($attributes['label']) ? $attributes['label'] : 'edit';
  return '<a href="#" data-type="' . $type . '" data-widget = "' . $widget . '" class="product-edit-link" rel="' . $node->name . '">' . $label . '</a>';
}