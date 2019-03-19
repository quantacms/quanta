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
    $email = $this->getSubmittedValue();

    if (!empty($value) && !\Quanta\Common\Api::valid_email($email)) {
       $this->getFormState()->validationError($this->getName(), t('Insert a valid email'));
    };
  }
}
