<?php
namespace Quanta\Qtags;


/**
 * Helper tag to render all the classes created for the <body> tag.
 */
class BodyClasses extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $page = $this->env->getData('page');
    $body_classes = $page->getData('body_classes');
    return implode(' ', $body_classes);
  }
}
