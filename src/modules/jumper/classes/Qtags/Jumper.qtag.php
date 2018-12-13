<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;

define("JUMPER_EMPTY", "_empty");

/**
 * Create a "jumper" dropdown to quick access nodes.
 */
class Jumper extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // Which folder to use.
    $dirlist = new DirList($this->env, $this->getTarget(), 'jumper', array('sortbytime' => 'asc') + $this->attributes, 'jumper');

    $ajax = (isset($this->attributes['ajax'])) ? $this->attributes['ajax'] : '_self';
    $empty = (isset($this->attributes['empty'])) ? $this->attributes['empty'] : '----------';
    $tpl = (isset($this->attributes['tpl'])) ? ('data-tpl="' . $this->attributes['tpl'] . '"') : '';

    // Render the jumper.
    // TODO: use FORM Qtags.
    $jumper = '<select class="jumper" rel="' . $ajax . '" ' . $tpl . '><option value="' . JUMPER_EMPTY . '">' . $empty . '</option>' . $dirlist->render() . '</select>';

    return $jumper;
  }
}
