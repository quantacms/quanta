<?php
/**
 * Implementas hook_page_complete()
 *
 * @param $vars
 */
function qtags_page_complete($vars) {
  $vars['page']->html = transformCodeTags($vars['page']->env, $vars['page']->html);
}

/**
 * A generic qtag function to be used for PHPDoc Cloning.
 *
 * @param Environment $env
 *   The current environment.
 *
 * @param string $target
 *   A target attribute.
 *
 * @param array $attributes
 *   An array of attributes.
 *
 * @return mixed
 *   Could return about anything.
 */
function qtag($env, $target, $attributes) {

  return NULL;
}