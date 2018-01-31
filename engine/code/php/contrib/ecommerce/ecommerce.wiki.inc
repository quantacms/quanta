<?php

/**
 * Detect content JUMPER and eventually create HTML.
 * @param $attributes
 * @param $value
 * @param $string
 * @param $env
 * @return bool|mixed|string
 */
function wiki_PRODUCTS($attributes, $value, $string, $env) {

  $dirlist = new ProductList($env, $value, 'products', array('sortbytime' => 'asc') + $attributes);
  return $dirlist->render();
}

/**
 * Detect content JUMPER and eventually create HTML.
 * @param $attributes
 * @param $value
 * @param $string
 * @param $env
 * @return bool|mixed|string
 */
function wiki_PRODUCTSLIST($attributes, $value, $string, $env) {

  $dirlist = new ProductList($env, $value, 'products_list', array('sortbytime' => 'asc') + $attributes);
  return $dirlist->render();
}

function wiki_PRODUCTADD($attributes, $node, $string, $env) {
  $attributes['class'] = 'product-add-link';
  $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Add product...';
  return wiki_ADD($attributes, $node, $string, $env);
}

function wiki_PRODUCTEDIT($attributes, $node, $string, $env) {
  $attributes['class'] = 'product-edit-link';
  $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Edit product...';
  return wiki_EDIT($attributes, $node, $string, $env);
}