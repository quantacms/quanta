<?php

namespace Quanta\Qtags;

/**
 * Class FormItemRadios
 * 
 */
class FormItemRadios extends FormItem
{
  public $type = 'radio';
  protected $html_tag = 'div';

  /**
   * Renders the input item.
   * @return mixed
   */
  function render()
  {
    $this->loadOptions();
    return parent::render();
  }

  /**
   * Load Options for select inputs.
   */
  public function loadOptions()
  {

    $id = $this->getId();
    $this->setId($id . '-container');
    $i = 0;
    foreach ($this->getAllowableValues() as $k => $option) {
      // TODO: when it's single, it becomes a simple string...
      if (is_array($option)) {
        $option_key = array_keys($option)[0];
        $option_value = array_values($option)[0];
      } else {
        $option_key = $k;
        $option_value = $option;
      }
      $option_attributes = array();
      $option = new FormItemRadio($this->env, array('name' => $this->name, 'value' => $option_key));

      $option->setDefaultValue($option_key);

      if (!empty($this->getAttribute('selected-value'))) {
        $selected_value = $this->getAttribute('selected-value');
      } else {
        $selected_value = null;
      }

      if (!empty($selected_value) && $option_key == $selected_value) {
        $option->html_params['checked'] = 'checked';
      } elseif ($this->getAttribute('default_value') == $option_key) {
        $option->html_params['checked'] = 'checked';
      }
      //make sure each radio has diffrent id
      $option->setId($id . '-' . $i);

      $option_input_classes = $this->getAttribute('option-input-class');

      if ($option_input_classes) {
        $option->addClass($option_input_classes);
      }
      //Add label
      $label_attributes = array(
        'label_position' => $option->getLabelPosition(),
        'label_text' => $option_value,
        'required' => $option->isRequired(),
      );
      $label = new Label($this->env, $label_attributes, $id . '-' . $i);

      $option->setHtmlBody($option->html_body . $label);

      $option_classes = $this->getAttribute('option-class');
      // Add the ability to apply specific CSS classes to individual radio buttons.
      if ($this->getAttribute('option-class-' . $i)) {
        $option_classes .= ' ' . $this->getAttribute('option-class-' . $i);
      }
      $html = $option->render();

      // Allow adding custom HTML content to individual radio buttons.
      if ($this->getAttribute('option-html-' . $i)) {
        $html .= $this->getAttribute('option-html-' . $i);
      }
      $this->html_body .= "<div class=\"" . $option_classes . "\">{$html}</div>";
      $i++;
    }
  }

  // TODO. Check that the value is in the list.
  public function validate()
  {
    return TRUE;
  }
}
