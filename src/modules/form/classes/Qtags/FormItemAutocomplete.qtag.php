<?php
namespace Quanta\Qtags;

/**
 * Class FormItemAutocomplete
 * This class represents a Form Item of type dropdown Select
 */
class FormItemAutocomplete extends FormItemString {

  function render() {
    $this->addClass('autocomplete');
    return parent::render();
  }
}
