<?php
namespace Quanta\Qtags;
/**
 * Renders a Tab of Shadow, Quanta's overlay input form.
 */
class ShadowTab extends Link {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->destination = '#';
    $this->html_params['data-title'] = $this->getAttribute('title');
    $this->html_params['data-rel'] = $this->getTarget();
    $this->setId("shadow-title-" . $this->getTarget());
    $this->addClass('shadow-title');
    $this->addClass('p-1');
    $this->addClass($this->getAttribute('class'));
    return parent::render();
  }
}
