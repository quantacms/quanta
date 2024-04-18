<?php
namespace Quanta\Qtags;
/**
 * Renders a Content Tab of a Shadow, Quanta's overlay input form.
 */
class ShadowContent extends HtmlTag {

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $this->setId("shadow-content-" . $this->getTarget());
    $this->addClass('shadow-content');
    $this->addClass('shadow-content-' . $this->env->getContext());
    $this->addClass($this->getAttribute('class'));
    return parent::render();
  }
}
