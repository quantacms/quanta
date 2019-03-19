<?php
namespace Quanta\Qtags;

/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemSelect extends FormItem {
  public $type = 'select';
  protected $html_tag = 'select';

  /**
   * Renders the input item.
   * @return mixed
   */
  function render() {
    $this->loadOptions();
    return parent::render();
  }

  /**
   * Load Options for select inputs.
   */
  public function loadOptions() {
    foreach ($this->getAllowableValues() as $k => $option) {
      // TODO: when it's single, it becomes a simple string...
      if (is_array($option)) {
        $option_key = array_keys($option)[0];
        $option_value = array_values($option)[0];
      }
      else {
        $option_key = $k;
        $option_value = $option;
      }
      $option_attributes = array();
      $option = new Option($this->env, $option_attributes, NULL);
      $option->html_params['value'] = $option_key;
      if ($this->getCurrentValue() == $option_key) {
        $option->html_params['selected'] = 'selected';
      }
      $option->setHtmlBody($option_value);
      $this->html_body .= $option->render();
    }
  }

  // TODO. Check that the value is in the list.
  public function validate() {
    return TRUE;
  }
}
