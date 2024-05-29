<?php
namespace Quanta\Qtags;

/**
 * Class FormItemString
 * This class represents a Form Item of type dropdown Select
 */
class FormItemCheckboxes extends FormItem {
  public $type = 'checkbox';
  protected $html_tag = 'div';

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
      $option = new FormItemCheckbox($this->env, array('name' => $this->name));

      if ($this->getCurrentValue() == $option_key) {
        $option->html_params['selected'] = 'selected';
      }
      elseif ($this->getDefaultValue() == $option_key) {
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
