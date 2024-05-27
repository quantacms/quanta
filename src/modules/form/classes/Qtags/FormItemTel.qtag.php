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
    $tel = $this->getSubmittedValue();

    if (!empty($value) && !\Quanta\Common\Api::valid_phone($tel)) {
       $this->getFormState()->validationError($this->getName(), t('Insert a valid phone'));
    };
  }
}
