<?php
namespace Quanta\Qtags;


/**
 * Renders an internal or external Javascript script.
 */
class Js extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $page = $this->env->getData('page');

    $js = array();
    $js_inline = array();
    $js_code = '';
    $refresh =  isset($this->attributes['refresh']) ? ('?' . Doctor::timestamp($this->env)) : '';

    // If target is specified, include the css file directly.
    if (!empty($this->getTarget())) {
      if (isset($this->attributes['module'])) {
        $this->setTarget($this->env->getModule($this->attributes['module'])['path'] . '/' . $this->getTarget());
      }
      $js = array($this->getTarget());
    }
    else {
      // If no target specified, assume loading of all page includes.
      $js = $page->getData('js');
      $js_inline = $page->getData('js_inline');
    }
    // Process Js files.
    foreach ($js as $js_file) {
      // TODO: support per file async.
      if (isset($this->attributes['inline'])) {
        $js_code .= '<script>' . $js_file . '</script>';
      }
      elseif (isset($this->attributes['file_inline'])) {
        $js_code .= '<script>' . file_get_contents($js_file) . '</script>';
      }
      else {
        $js_code .= '<script src="' . $js_file . $refresh . '"></script>';
      }
    }

    if (!empty($js_inline)) {
      // Process Inline JS.
      foreach ($js_inline as $js_inline_code) {
          $js_code .= '<script>' . $js_inline_code . '</script>';
      }
    }
    return $js_code;
  }
}
