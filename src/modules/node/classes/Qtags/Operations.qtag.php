<?php
namespace Quanta\Qtags;

/**
 * Renders ADD - EDIT - DELETE node links altogether.
 */
class Operations extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $add = new Add($this->env, array(), $this->getTarget());
    $edit = new Edit($this->env, array(), $this->getTarget());
    $delete = new Delete($this->env, array(), $this->getTarget());
    $operations = '';

    $operations .= $add->getHtml();
    $operations .= $edit->getHtml();
    $operations .= $delete->getHtml();

    return $operations;
  }
}
