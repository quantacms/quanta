<?php
namespace Quanta\Qtags;

/**
 * Class FormItemTel
 * This class represents a Form Item of type tel.
 */
class FormItemTel extends FormItemString {
  public $type = 'tel';


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
    $phone = $this->getSubmittedValue() ? $this->getSubmittedValue() : $this->getValue(true);
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