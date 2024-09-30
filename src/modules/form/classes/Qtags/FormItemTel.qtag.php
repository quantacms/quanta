<?php
namespace Quanta\Qtags;

/**
 * Class FormItemTel
 * This class represents a Form Item of type tel.
 */
class FormItemTel extends FormItemString {
  public $type = 'tel';

  public function render(){
    $page = $this->env->getData('page');
    $page->addJS('https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/intlTelInput.min.js', 'file');
    $page->addJS('https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/utils.js', 'file');
    $page->addCSS('https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/css/intlTelInput.css');

    return parent::render();
  }

  /**
   * @return string
   */
  public function getHTMLType() {
    return 'tel';
  }

  /**
   *
   */
  public function validate() {
    $full_phone = !empty($this->form_state) ? $this->form_state->getData('fullphone') : null;
    if(!empty($full_phone)){
      $phone = $full_phone;
    }else{
      $phone = $this->getSubmittedValue() ? $this->getSubmittedValue() : $this->getValue(true);
    }
    if (!empty($phone) && !\Quanta\Common\Api::valid_phone($phone)) {
      $this->setValidationStatus(false);
      $translated_text = \Quanta\Common\Localization::translatableText($this->env,'Si prega di inserire un numero di telefono valido!','enter-valid-phone-message');
      $this->setValidationMessage($translated_text);
      if($this->getFormState()){ 
          $this->getFormState()->validationError($this->getName(), $translated_text);
      }
    }
    parent::validate();
  }
}