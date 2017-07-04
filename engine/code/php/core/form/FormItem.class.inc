<?php
define('INPUT_EMPTY_VALUE', '___INPUT_EMPTY_VALUE___');

/**
 * Class FormItem
 * This class represents a Form Item - it's to be extended by specific form item types.
 */
abstract class FormItem extends DataContainer {
  public $env;
  private $name;
  protected $type;
  public $allowable_values = array();
  private $input_arr = array();
  private $form;

  /**
   * @param $env Environment
   * @param $filename
   * @param null $name
   * @param null $content
   */
  public function __construct(&$env, $input, $form = NULL) {
    $this->input_arr = $input;
    $this->env = $env;
    $this->setName($this->getInputAttr('name'));
    $this->setData('class', array('form-item'));
    $this->setData('title', !empty($this->getInputAttr('title')) ? $this->getInputAttr('title') : $this->getInputAttr('name'));
    $this->setData('label', !empty($this->getInputAttr('label')) ? $this->getInputAttr('label') : NULL);
    $this->setData('id', !empty($this->getInputAttr('id')) ? $this->getInputAttr('id') : ('input-' . $this->getInputAttr('name')));
    $this->checkRequired();
    $this->checkDisabled();
    $this->checkMultiple();

    // TODO: make this better and move elsewhere in validation function.
    if (!empty($_REQUEST['form']) && !empty($_SESSION['validation_errors'][$_REQUEST['form']][$this->getName()])) {
      $this->addData('class', array('validation-error'));
    }

    $this->loadValue();
    $this->loadDefault();
    $this->loadAllowableValues();
    $this->loadAttributes();

    if (!empty($form)) {
      $this->setForm($form);
    }

  }

  abstract function loadAttributes();

  public function getPlaceHolder() {
    return $this->getInputAttr('placeholder');
  }

  public function setName($name) {
    $this->name = $name;
  }

  public function getName() {
    return $this->name;
  }

  public function setForm(&$form) {
    $this->form = $form;
  }

  public function getForm() {
    return $this->form;
  }

  public function checkMultiple() {
    $this->setMultiple($this->getInputAttr('multiple'));
    $this->addData('class', array($this->isMultiple() ? 'form-item-multiple' : 'form-item-single'));
  }

  public function checkDisabled() {
    $this->setDisabled($this->getInputAttr('disabled'));
    $this->addData('class', array($this->isDisabled() ? 'form-item-disabled' : 'form-item-enabled'));
  }

  public function checkRequired() {
    $this->setRequired($this->getInputAttr('required'));
    $this->addData('class', array($this->isRequired() ? 'required' : 'not-required'));

  }

  public function loadValue() {
    $submitted = $this->getSubmittedValue();
    $value = (empty($submitted)) ? (($this->getInputAttr('value') != INPUT_EMPTY_VALUE ? $this->getInputAttr('value') : '')) : $submitted;
    $this->setValue($value);
  }

  public static function parseAllowableValue($v) {
    $value = explode('---', $v);
    return array($value[0] => (!empty($value[1]) ? trim($value[1]) : trim($value[0])));
  }

  /**
   * Load all the allowable value for a form item.
   * It will create <option> tags in <select>,
   * radio button options, etc.
   */
  public function loadAllowableValues() {
    if (!empty($this->getInputAttr('empty'))) {
      $this->allowable_values = self::parseAllowableValue($this->getInputAttr('empty'));
    }

    // If there is a range=x-y attribute, use it to calculate allowable values...
    if (!empty($this->getInputAttr('range'))) {
      $split = explode('-', $this->getInputAttr('range'));
      for ($i = $split[0]; $i <= $split[1]; $i++) {
        $values[$i] = $i;
      }
    } // ...otherwise look for the values attribute (that might be NULL, of course!)
    else {
      $values = explode(',', $this->getInputAttr('values'));
    }

    foreach ($values as $v) {
      $this->allowable_values = $this->allowable_values + $this::parseAllowableValue($v);
    }
  }

  public function getSubmittedValue() {
    return isset($_REQUEST[$this->getName()]) ? $_REQUEST[$this->getName()] : NULL;
  }

  /**
   * Load the default value for the input item.
   */
  public function loadDefault() {
    $value = $this->getValue();
    // If there is already a value set for the input item, ignore the default.
    $this->setData('default_value', !empty($value) ? array_pop($value) : $this->getInputAttr('default'));
  }

  public function setType($type) {
    $this->type = $type;
  }

  public function getType() {
    return $this->type;
  }

  /**
   * Retrieve the value of an input attribute from the [INPUT] tag.
   * @param $attribute
   * @return bool|null
   */
  public function getInputAttr($attribute) {
    return !isset($this->input_arr[$attribute]) ? NULL :
      (empty($this->input_arr[$attribute]) ? INPUT_EMPTY_VALUE : $this->input_arr[$attribute]);
  }

  public function setRequired($required) {
    $this->setData('required', (!empty($required)));
  }

  public function isRequired() {
    return $this->getData('required');
  }

  public function setDisabled($disabled) {
    $this->setData('disabled', (!empty($disabled)));
  }

  public function isDisabled() {
    return $this->getData('disabled');
  }

  public function setMultiple($multiple) {
    $this->setData('multiple', (!empty($multiple)));
  }

  public function isMultiple() {
    return $this->getData('multiple');
  }

  /**
   * Get the allowed values for a form item.
   * @return array
   */
  public function getAllowedValues() {
    return $this->getData('allowed_values');
  }

  /**
   * Set the value for a form item.
   * @param mixed $value
   */
  public function setValue($value = NULL) {
    // In order to support multiple values, we use an array.
    if (!is_array($value)) {
      $value = explode(GLOBAL_SEPARATOR, $value);
    }
    $this->setData('value', $value);
  }

  /**
   * Set the value for a form item.
   * @param mixed $value
   */
  public function setCurrentValue($value = NULL) {
    // In order to support multiple values, we use an array.
    $this->setData('current_value', $value);
  }

  /**
   * Return the value for a form item.
   * @return mixed
   */
  public function getValue() {
    return $this->getData('value') == INPUT_EMPTY_VALUE ? '' : $this->getData('value');
  }

  /**
   * Return the value for a form item.
   * @return mixed
   */
  public function getDefaultValue() {
    return $this->getData('default_value') == INPUT_EMPTY_VALUE ? '' : $this->getData('default_value');
  }

  public function getCurrentValue() {
    return empty($this->getData('current_value')) ? $this->getData('default_value') : $this->getData('current_value');
  }

  public function getClass() {
    return implode(' ', $this->getData('class'));
  }

  public function setId($id) {
    $this->setData('id', $id);
  }

  public function getId() {
    return $this->getData('id');
  }

  public function getLabel() {
    return !empty($this->getData('label')) ? $this->getData('label') : $this->getData('title');
  }

  public function hasValidationErrors() {
    return !empty($this->getValidationErrors());
  }

  /**
   * Renders this form item.
   * @return string
   */
  public function renderFormItem() {
    $rendered = '';
    $values = $this->getValue();
    $i = 0;
    // Load and render all existing values...
    foreach ($values as $value) {

      $this->setCurrentValue($value);
      if ($i > 0) {
        $this->setId($this->getId() . '_' . $i);
      }

      $rend = $this->render();

      $rendered .= ($this->isMultiple()) ? '<div class="form-item-multiple-wrapper">' . $rend . '</div>' : $rend;
      $i++;
    }

    // Add wrapping & label...
    $rendered = '<div class="form-item-wrapper ' .
      ($this->hasValidationErrors() ? 'has-validation-errors' : '') .
      '">' .
      (!empty($this->getLabel()) ? ('<div class="form-item-label"><label for="' . $this->getName() . '">' . $this->getLabel() . '' . ($this->isRequired() ? '<span class="form-item-required">*</span>' : '') . '</label></div>') : '') .
      $rendered .
      '</div>';

    if ($this->hasValidationErrors()) {
      $rendered = '<div class="validation-error validation-error">' . $this->getValidationErrors() . '</div>' . $rendered;
    }

    return $rendered;
  }

  /**
   * Renders the input item.
   * @return mixed
   */
  abstract function render();

  /**
   * Validates standards of the input item.
   * @return mixed
   */
  public function validate() {
    return TRUE;
  }

  /**
   * Get all the validation errors of the submitted form item.
   * @return null
   */
  public function getValidationErrors() {
    $errors = $this->getForm()->getData('validation_errors');
    return !empty($errors[$this->getName()]) ? $errors[$this->getName()] : NULL;
  }

  /**
   * Check if this item is the last in the form.
   * (Normally used to create the closing </form> tag.
   */
  public function isFirst() {
    $items = $this->getForm()->getItems();
    $items = array_reverse($items);
    $first = array_pop($items);
    return ($this->getName() == $first->getName());
  }


  /**
   * Check if this item is the last in the form.
   * (Normally used to create the closing </form> tag.
   */
  public function isLast() {
    $items = $this->getForm()->getItems();
    $last = array_pop($items);
    return ($this->getName() == $last->getName());
  }
}
