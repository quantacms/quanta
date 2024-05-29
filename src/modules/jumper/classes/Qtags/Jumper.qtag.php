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
    $dirlist = new DirList($this->env, $this->getTarget(), 'jumper', array('sort' => 'title') + $this->attributes, 'jumper');

    $ajax = (isset($this->attributes['ajax'])) ? $this->attributes['ajax'] : '_self';
    $empty = (isset($this->attributes['empty'])) ? $this->attributes['empty'] : '----------';
    $field = isset($this->attributes['field']) ? $this->attributes['field'] : NULL;
    $method = isset($this->attributes['method']) ? $this->attributes['method'] : 'redirect';

    $tpl = 'jumper';
    // Render the jumper.
    // TODO: use FORM Qtags.
    $jumper = '<select class="jumper" data-field="' . $field . '" data-jumper-method="' . $method. '" rel="' . $ajax . '" ' . $tpl . '><option value="' . JUMPER_EMPTY . '">' . $empty . '</option>' . $dirlist->render() . '</select>';

    return $jumper;
  }
}
