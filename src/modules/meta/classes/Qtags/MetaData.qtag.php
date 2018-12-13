<?php
namespace Quanta\Qtags;

/**
 * Renders all the page's meta data.
 */
class MetaData extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $meta = $this->env->getData('metadata');
    $metatags = array();

    foreach ($meta as $meta_name => $meta_content) {
      $properties = array();
      foreach ($meta_content as $type => $value) {
        $properties[] = $type . '="' . $value . '"';
      }
      $metatags[] = '<meta name="' . $meta_name . '" ' . str_replace("\n"," ",implode(' ', $properties)) . ' />';
    }

    return implode("\n", $metatags);  }
}
