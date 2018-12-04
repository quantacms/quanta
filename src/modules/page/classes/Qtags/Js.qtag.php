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

    $js_code = '';
    $refresh =  isset($this->attributes['refresh']) ? ('?' . Doctor::timestamp($this->env)) : '';

    // If target is specified, include the css file directly.
    if (!empty($this->getTarget())) {
      if (isset($this->attributes['module'])) {
        $this->setTarget($this->env->getModule($this->attributes['module'])['path'] . '/' . $this->getTarget());
      }
      else {
        $this->setTarget($this->env->dir['docroot'] . '/' . $this->getTarget());
      }
      $js = array($this->getTarget());
    }
    else {
      // If no target specified, assume loading of all page includes.
      $js = $page->getData('js');
    }

    // TODO: converting all inclusions into inline stylesheets. Faster, but is it good?
    foreach ($js as $js_file) {
      // TODO: support per file async.
      if (isset($this->attributes['inline'])) {
        $js_code .= '<script>' . file_get_contents($js_file) . '</script>';
      }
      else {
        $js_code .= '<script src="' . $js_file . $refresh . '?[DOCTOR_TIMESTAMP]"></script>';
      }
    }

    return $js_code;
  }
}
