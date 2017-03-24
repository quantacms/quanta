<?php
/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemPassword extends FormItemString {
  public $type = 'password';

  public function getHTMLType() {
    return 'password';
  }


  function loadAttributes() {
    // TODO: Implement loadAttributes() method.
  }
}
