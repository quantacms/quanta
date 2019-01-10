<?php
namespace Quanta\Qtags;
Use Quanta\Common\FormFactory;

/**
 * Renders a form with all its inputs, buttons, etc.
 */
class Form extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $form = FormFactory::getForm($this->env, $this->getTarget());
    $form->loadAttributes($this->attributes);
    $form_html = '';
    // If the form has been submitted, validate it.
    if ($form->isSubmitted() && ($validate_ok = $form->checkValidate())) {
      $form_html = $validate_ok;
    }
    $form_html .= $form->renderFormClose();
    return $form_html;
  }
}
