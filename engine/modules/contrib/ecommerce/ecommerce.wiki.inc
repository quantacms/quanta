<?php

/**
 * Detect content JUMPER and eventually create HTML.
 * @param $attributes
 * @param $value
 * @param $string
 * @param $env
 * @return bool|mixed|string
 */
function qtag_PRODUCTS($attributes, $value, $string, $env) {

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
function qtag_PRODUCTSLIST($attributes, $value, $string, $env) {

  $dirlist = new ProductList($env, $value, 'products_list', array('sortbytime' => 'asc') + $attributes);
  return $dirlist->render();
}

/**
 * @param $env
 * @param $target
 * @param $attributes
 * @return string
 */
function qtag_PRODUCTADD($env, $target, $attributes) {
  $attributes['class'] = 'product-add-link';
  $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Add product...';
  return qtag_ADD($env, $target, $attributes);
}

/**
 * @param $env
 * @param $target
 * @param $attributes
 * @return string
 */
function qtag_PRODUCTEDIT($env, $target, $attributes) {
  $attributes['class'] = 'product-edit-link';
  $attributes['tooltip'] = isset($attributes['tooltip']) ? $attributes['tooltip'] : 'Edit product...';
  return qtag_EDIT($env, $target, $attributes);
}