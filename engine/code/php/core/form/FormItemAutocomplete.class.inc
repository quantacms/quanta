<?php
/**
 * Class FormItemAutocomplete
 * This class represents a Form Item of type dropdown Select
 */
class FormItemAutocomplete extends FormItemString {
  public $type = 'autocomplete';

  function loadAttributes() {
    $this->addClass('autocomplete');
    parent::loadAttributes();
  }
}
