<?php
namespace Quanta\Qtags;

/**
 * Prepares an input item of a form for rendering.
 */
class Input extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $form = \Quanta\Common\FormFactory::getForm($this->env, $this->getTarget());
    \Quanta\Common\FormFactory::createInputItem($this->env, $this->attributes, $form);
    $rendered = '';

    if (!(empty($this->attributes['name'])) && !(empty($form->getItem($this->attributes['name'])))) {
      $input = $form->getItem($this->attributes['name']);
      if ($input->isFirst()) {
        $rendered = $form->renderFormOpen() . $rendered;
      }
      $rendered .= ($form->isValidated()) ? '' : $input->renderFormItem();
    }

    return $rendered;
  }
}
