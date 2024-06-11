<?php
namespace Quanta\Qtags;

/**
 * Class FormItemEmail
 * This class represents a Form Item of type Email.
 */
class FormItemEmail extends FormItemString {
  public $type = 'email';


  /**
   * @return string
   */
  public function getHTMLType() {
    return 'email';
  }

  /**
   *
   */
  public function validate() {
    $email = $this->getSubmittedValue() ? $this->getSubmittedValue() : $this->getValue(true);
    if (!empty($email) && !\Quanta\Common\Api::valid_email($email)) {
      $this->setValidationStatus(false);
      $translated_text = \Quanta\Common\Localization::translatableText($this->env,'Inserisci una email valida!','enter-valid-email-message');
      $this->setValidationMessage($translated_text);
      if($this->getFormState()){ 
          $this->getFormState()->validationError($this->getName(), $translated_text);
      }
    }
    parent::validate();
  }
}
