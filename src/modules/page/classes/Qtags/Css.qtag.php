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
      $inline_code = '';
      $css_links = '';
      $allowed_roots = array(
        $this->env->dir['docroot'],
        $this->env->dir['modules_core'],
        $this->env->dir['modules_custom'],
        $this->env->dir['profiles'],
        $this->env->dir['static'],
      );

      foreach ($css as $css_file) {
        // Remote stylesheets must stay links. Never fetch arbitrary URLs server-side.
        if (preg_match('#^https?://#i', $css_file)) {
          $css_links .= '<link rel="stylesheet" href="' . htmlspecialchars($css_file, ENT_QUOTES, 'UTF-8') . '" type="text/css" />';
          continue;
        }
        if (strtolower(pathinfo($css_file, PATHINFO_EXTENSION)) !== 'css') {
          continue;
        }
        $local_file = \Quanta\Common\Api::resolveAllowedLocalFile($css_file, $allowed_roots);
        if ($local_file !== FALSE) {
          $inline_code .= file_get_contents($local_file);
        }
      }
      if (!empty($inline_css)) {
        foreach ($inline_css as $inline_css_code) {
          $inline_code .= $inline_css_code . "\n";
        }
      }
      $css_code = ($inline_code !== '') ? ('<style>' . $inline_code . '</style>') : '';
      $css_code .= $css_links;
    }
    // Including an external CSS.
    else {
      $css_code = '<link rel="stylesheet" href="' . (isset($this->attributes['protocol']) ? ($this->attributes['protocol'] . '://') : '') . $this->getTarget() . '" type="text/css" />';
    }
    return $css_code;
  }
}
