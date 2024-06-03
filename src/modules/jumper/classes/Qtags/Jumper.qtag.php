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

    $ajax = (isset($this->attributes['ajax'])) ? $this->attributes['ajax'] : '_self';
    $default = (isset($this->attributes['empty'])) ? $this->attributes['empty'] : '----------';
    $field = isset($this->attributes['field']) ? $this->attributes['field'] : NULL;
    $method = isset($this->attributes['method']) ? $this->attributes['method'] : 'redirect';

    if (!empty($this->attributes['default'])) {
      $default_path = $this->attributes['default'];
      $default_title = '[TITLE:' . $this->attributes['default'] . ']';
    } else {
      $default_path = JUMPER_EMPTY;
      $default_title = (isset($this->attributes['empty'])) ? $this->attributes['empty'] : '----------';
    }

    $dirlist = new DirList($this->env, $this->getTarget(), 'jumper', array('sort' => 'title','list_filter' => 'path@!' . $default_path) + $this->attributes, 'jumper');
    $tpl = 'jumper';
    // Render the jumper.
    // TODO: use FORM Qtags.
    $jumper = '<select class="jumper" data-field="' . $field . '" data-jumper-method="' . $method. '" rel="' . $ajax . '" ' . $tpl . '><option value="' . $default_path . '">' . $default_title . '</option>' . $dirlist->render() . '</select>';

    return $jumper;
  }
}
