<?php
namespace Quanta\Qtags;

/**
 * Renders an internal or external Cascading Style Sheet.
 */
class Css extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $page = $this->env->getData('page');
    
    // If target is specified, include the css file directly.
    if (!empty($this->getTarget())) {
      if (isset($this->attributes['module'])) {
        $this->setTarget($this->env->getModule($this->attributes['module'])['path'] . '/' . $this->getTarget());
      }
      $css = array($this->getTarget());
    }

    else {
      // If no target specified, assume loading of all page includes.
      $css = $page->getData('css');
      $inline_css = $page->getData('css_inline');
    }

    // Including an internal CSS.
    if (empty($this->attributes['external'])) {
      $css_code = '<style>';
      // TODO: converting all inclusions into inline stylesheets. Faster, but to be reviewed.

      foreach ($css as $css_file) {
        $css_code .= file_get_contents($css_file);
      }
      if (!empty($inline_css)) {
        foreach ($inline_css as $inline_css_code) {
          $css_code .= $inline_css_code . "\n";
        }
      }
      $css_code .= '</style>';
    }
    // Including an external CSS.
    else {
      $css_code = '<link rel="stylesheet" href="' . (isset($this->attributes['protocol']) ? ($this->attributes['protocol'] . '://') : '') . $this->getTarget() . '" type="text/css" />';
    }
    return $css_code;
  }
}
