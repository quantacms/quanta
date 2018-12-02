<?php
namespace Quanta\Qtags;
Use Quanta\Common\FormFactory;

/**
 * Helper qtag, rendering INPUT after all the input items have been loaded.
 */
class InputRender extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $rendered = '';
    $form = FormFactory::getForm($this->env, $this->getTarget());
    if (!(empty($this->attributes['name'])) && !(empty($form->getItem($this->attributes['name'])))) {

      $input = $form->getItem($this->attributes['name']);
      if ($input->isFirst()) {
        $this->attributes['prefix'] = $form->renderFormOpen();
      }

      $rendered .= ($form->isValidated()) ? '' : $input->renderFormItem();

      if ($input->isLast()) {
        $this->attributes['suffix'] = $form->renderFormClose();
      }
    }

    return $rendered;
  }
}
