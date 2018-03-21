<?php
/**
 * Implements MENU qtag.
 *  TODO: whole module to be implemented.
 *
 * Renders a tree (a list, that goes deep into folders).
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
function qtag_MENU($env, $target, $attributes) {

 foreach($attributes as $name => $value) {
   $exp = explode('menu_link_', $name);
   if (count($exp) > 0) {
     print_r($value);
   }

 }

}
