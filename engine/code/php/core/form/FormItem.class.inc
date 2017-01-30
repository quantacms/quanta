<?php
/**
 * Class FormItem
 * This class represents a Form Item - it's to be extended by specific form item types.
 */
abstract class FormItem extends DataContainer {
  public $env;
  private $name;
  private $type;
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

    // TODO: make this better and move elsewhere in validation function.
    if (isset($_SESSION['validation_errors'][$_REQUEST['form']][$this->getName()])) {
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
    $value = (empty($submitted)) ? $this->getInputAttr('value') : $submitted;
    $this->setData('value', $value);
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
    }
    // ...otherwise look for the values attribute (that might be NULL, of course!)
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
    // If there is already a value set for the input item, ignore the default.
    $this->setData('default_value', !empty($this->getData('value')) ? $this->getData('value') : $this->getInputAttr('default'));

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
      (empty($this->input_arr[$attribute]) ? TRUE : $this->input_arr[$attribute]);
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
    $this->setData('value', $value);
  }

  /**
   * Return the value for a form item.
   * @return mixed
   */
  public function getValue() {
    return $this->getData('value');
  }

  /**
   * Return the value for a form item.
   * @return mixed
   */
  public function getDefaultValue() {
    return $this->getData('default_value');
  }

  public function getClass() {
    return implode(' ', $this->getData('class'));
  }

  public function getId() {
    return $this->getData('id');
  }

  public function getLabel() {
    return !empty($this->getData('label')) ? $this->getData('label') : $this->getData('title');
  }

  public function hasValidationErrors() {
    return (!empty($this->getData('validation_errors')));
  }
  public function renderFormItem() {

    $rendered = '<div class="form-item-wrapper ' . ($this->hasValidationErrors() ? 'has-validation-errors' : '') . '">'
    . (!empty($this->getLabel()) ? ('<div class="form-item-label"><label for="' . $this->getName() . '">' . $this->getLabel() . '</label>' . ($this->isRequired() ? '<span class="form-item-required">*</span>' : '') . '</div>') : '')

    . ($this->render())
    . '</div>';
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

}
