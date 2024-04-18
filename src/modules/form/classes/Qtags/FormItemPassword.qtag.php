<?php
namespace Quanta\Qtags;

/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemPassword extends FormItemString {
  public $type = 'password';
  protected $html_tag = 'input';

  /**
   * @return string
   */
  public function getHTMLType() {
    return 'password';
  }


  function loadAttributes() {
    // TODO: Implement loadAttributes() method.
  }
}
