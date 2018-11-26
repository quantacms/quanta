<?php
namespace Quanta\Common;

/**
 *
 * @category Qtags
 * @file jumper.qtags.inc
 * @package Quanta
 * @author Aldo Tripiciano <aldo.tripiciano@gmail.com>
 * @license http://www.quantacms.com Open
 * @link http://www.quantacms.com
 *
 * All qtags implemented by the jumper module.
 */
define("JUMPER_EMPTY", "_empty");

/**
 * Implements qtag JUMPER.
 *
 * Create a jumper to quick access nodes.
 *
 * @param Environment $env
 *   The Environment.
 * @param string $target
 *   The qtag's target.
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_JUMPER($env, $target, &$attributes) {
  // Which folder to use.
  $dirlist = new DirList($env, $target, 'jumper', array('sortbytime' => 'asc') + $attributes, 'jumper');

  $ajax = (isset($attributes['ajax'])) ? $attributes['ajax'] : '_self';
  $empty = (isset($attributes['empty'])) ? $attributes['empty'] : '----------';
  $tpl = (isset($attributes['tpl'])) ? ('data-tpl="' . $attributes['tpl'] . '"') : '';

  // Render the jumper.
  // TODO: maybe use FORM tags?
  $jumper = '<select class="jumper" rel="' . $ajax . '" ' . $tpl . '><option value="' . JUMPER_EMPTY . '">' . $empty . '</option>' . $dirlist->render() . '</select>';

  return $jumper;
}
