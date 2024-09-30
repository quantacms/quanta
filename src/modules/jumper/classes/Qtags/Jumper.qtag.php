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
    $default_path = null;

    if (!empty($this->attributes['default'])) {
      $default_path = $this->attributes['default'];
      $default_title = !empty($this->getAttribute('default-title')) ? $this->getAttribute('default-title') :  '[TITLE:' . $this->attributes['default'] . ']';

    } else {
      $default_path = (isset($this->attributes['empty_path'])) ? $this->attributes['empty_path'] : '';
      $default_title = (isset($this->attributes['empty'])) ? $this->attributes['empty'] : '----------';
    }

    if (!empty($this->attributes['empty_show']) && ($this->attributes['empty_show'] == 'always')) {
      $empty_path = (isset($this->attributes['empty_path'])) ? $this->attributes['empty_path'] : '';
      $empty_title = (isset($this->attributes['empty'])) ? $this->attributes['empty'] : '----------';
    } else {
      $empty_title = '';
      $empty_path = '';
    }

    $list_filters = (isset($this->attributes['list_filter'])) ? $this->attributes['list_filter'] : '';
    if($default_path){
      $filter_prefix = !empty($list_filters) ? ',' : '';
      $list_filters .= $filter_prefix . 'path@!'. $default_path;
    }
    
    $tpl = !empty($this->getAttribute('tpl')) ? $this->getAttribute('tpl') : 'jumper';
    $module = !empty($this->getAttribute('module')) ? $this->getAttribute('module') : 'jumper';

    $options = !empty($this->getAttribute('values')) ? $this->getAttribute('values') : null;
    if(!$options){
      $dirlist = new DirList($this->env, $this->getTarget(), $tpl, array('sort' => 'title','list_filter' => $list_filters) + $this->attributes, $module);
      $options =  $dirlist->render();
    }
    $tpl = 'jumper';
    // Render the jumper.
    // TODO: use FORM Qtags.
    $jumper = '<select class="jumper" data-field="' . $field . '" data-jumper-method="' . $method. '" rel="' . $ajax . '" ' . $tpl . '>'.
      (($empty_path != $default_path) ? ('<option value="' . $default_path . '">' . $default_title . '</option>') : '') .
      ($this->attributes['empty_show'] !='false' ?'<option value="' . $empty_path . '">' . $empty_title . '</option>':'') .
      $options . '</select>';

    return $jumper;
 
  }
}
