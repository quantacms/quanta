<?php
namespace Quanta\Qtags;
Use Quanta\Common\FormFactory;

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
    $form = FormFactory::getForm($this->env, $this->getTarget());
    FormFactory::createInputItem($this->env, $this->attributes, $form);
    $rendered = '';

    if (!(empty($this->attributes['name'])) && !(empty($form->getItem($this->attributes['name'])))) {

      $input = $form->getItem($this->attributes['name']);
      if ($input->isFirst()) {
        $this->attributes['prefix'] = $form->renderFormOpen();
      }
      $rendered .= ($form->isValidated()) ? '' : $input->renderFormItem();
    }

    return $rendered;
  }
}
