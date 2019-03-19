<?php
namespace Quanta\Qtags;

/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemString extends FormItem {
  public $type = 'string';
  protected $html_tag = 'input';

  /**
   * Render the Qtag.
   */
  public function render() {
    $this->html_params['type'] = $this->getType();
    $this->html_params['value'] = $this->getDefaultValue();

    if (!empty($this->getAttribute('size'))) {
      $this->html_params['size'] = $this->getAttribute('size');
    }
    return parent::render();
  }
}
