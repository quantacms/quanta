<?php
namespace Quanta\Qtags;

/**
 * Class FormItemAddress
 * This class represents a Form Item of type address.
 */
class FormItemAddress extends FormItemString {

  public function render(){
    $this->addClass('address-input');
    return parent::render();
  }

}