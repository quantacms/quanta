<?php
namespace Quanta\Common;

define('INPUT_EMPTY_VALUE', '___INPUT_EMPTY_VALUE___');

/**
 * Class FormItem
 * This class represents an abstract FormItem, to be extended by specific
 * form item types.
 */
abstract class FormItem extends DataContainer {
  public $env;
  private $name;
  protected $type;
  private $input_arr = array();
  private $form;

  /**
   * Constructs the form item with all its attributes.
   *
   * @param $env Environment
   * @param $input
   * @param null $form
   */
  public function __construct(&$env, $input, $form = NULL) {
    $this->input_arr = $input;
    $this->env = $env;

    // Check all the form item's attributes.
    $this->setName($this->getInputAttr('name'));
    $this->setData('class', array('form-item form-item-' . $this->getType()));
    $this->setData('title', !empty($this->getInputAttr('title')) ? $this->getInputAttr('title') : $this->getInputAttr('name'));
    $this->setData('label', !empty($this->getInputAttr('label')) ? $this->getInputAttr('label') : NULL);
    $this->setData('id', !empty($this->getInputAttr('id')) ? $this->getInputAttr('id') : ('input-' . $this->getInputAttr('name')));

    $this->checkRequired();
    $this->checkDisabled();
    $this->checkMultiple();
    $this->checkLimit();
    $this->checkDistinct();

    // TODO: make this better and move elsewhere in validation function.
    if (!empty($_REQUEST['form']) && !empty($_SESSION['validation_errors'][$_REQUEST['form']][$this->getName()])) {
      $this->addClass('validation-error');
    }
    $this->loadValue();
    $this->loadDefault();
    $this->loadAllowableValues();
    $this->loadAttributes();

    // Setup the form object for this Form Item.
    if (!empty($form)) {
      $this->setForm($form);
    }

  }

  /**
   * Load all the attributes of the form item.
   */
  abstract function loadAttributes();

  /**
   * The PlaceHolder text for this form item.
   *
   * @return string
   *   The placeholder text for this form item.
   */
  public function getPlaceHolder() {
    return $this->getInputAttr('placeholder');
  }

  /**
   * @param string $name
   *   The name of this form item.
   */
  public function setName($name) {
    $this->name = $name;
  }

  /**
   * @return string
   *   The name of this form item.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Setup the Form object containing this form item.
   *
   * @param Form mixed
   */
  public function setForm(&$form) {
    $this->form = $form;
  }

  /**
   * Returns the Form object containing this form item.
   *
   * @return Form mixed
   */
  public function getForm() {
    return $this->form;
  }

  /**
   * Checks if the form item is multiple. Add a custom class, to indicate
   * it's multiple or single.
   */
  public function checkMultiple() {
    $this->setMultiple($this->getInputAttr('multiple'));
    $this->addData('class', array($this->isMultiple() ? 'form-item-multiple' : 'form-item-single'));
  }

  /**
   * Checks if the form item has a limit. Add a custom class, to indicate if
   * it's limited or unlimited.
   */
  public function checkLimit() {
    $this->setLimit($this->getInputAttr('limit'));
    $this->addData('class', array($this->getData('limit') ? 'form-item-limited' : 'form-item-unlimited'));
  }

  /**
   * Checks if the form item is distinct. Add a custom class, if it's required.
   */
  public function checkDistinct() {
    $this->setDistinct($this->getInputAttr('distinct'));
    $this->addData('class', array($this->isDistinct() ? 'form-item-distinct' : 'form-item-not-distinct'));
  }

  /**
   * Checks if the form item is disabled. Add a custom class, if it's required.
   */
  public function checkDisabled() {
    $this->setDisabled($this->getInputAttr('disabled'));
    $this->addData('class', array($this->isDisabled() ? 'form-item-disabled' : 'form-item-enabled'));
  }

  /**
   * Checks if the form item is required. Add a custom class, if it's required.
   */
  public function checkRequired() {
    $this->setRequired($this->getInputAttr('required'));
    $this->addData('class', array($this->isRequired() ? 'required' : 'not-required'));
  }

  /**
   * Load the value for this form item.
   */
  public function loadValue() {
    $submitted = $this->getSubmittedValue();
    $value = (empty($submitted)) ? (($this->getInputAttr('value') != INPUT_EMPTY_VALUE ? $this->getInputAttr('value') : '')) : $submitted;
    $this->setValue($value);
  }

  /**
   * Parse an allowable value (normally delimited via --- string).
   * TODO: maybe use a better criteria?
   *
   * @param $v
   *   The value to be parsed
   *
   * @return array
   *   The parsed value.
   */
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
      $this->setData('allowable_values', self::parseAllowableValue($this->getInputAttr('empty')));
    }

    $values = array();

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

    // Check the allowable values for this form item.
    foreach ($values as $v) {
      $this->addData('allowable_values', $this::parseAllowableValue($v));
    }
  }

  /**
   * If the form has been submitted, this function returns a submitted item's value.
   *
   * @return mixed
   *  The submitted value for the form item.
   */
  public function getSubmittedValue() {
    return isset($_REQUEST[$this->getName()]) ? $_REQUEST[$this->getName()] : NULL;
  }

  /**
   * Load the default value for the input item.
   */
  public function loadDefault() {
    $value = $this->getValue();
    $single_value = array_pop($value);

    // If there is already a value set for the input item, ignore the default.
    $this->setData('default_value', !empty($single_value) ? $single_value : $this->getInputAttr('default'));
  }

  /**
   * Set the type of a form item.
   *
   * @param string $type
   */
  public function setType($type) {
    $this->type = $type;
  }

  /**
   * Get the type of a form item.
   *
   * @return string mixed
   */
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

  /**
   * Set a form item as required.
   *
   * @param boolean $required
   */
  public function setRequired($required) {
    $this->setData('required', (!empty($required)));
  }

  /**
   * Check if a form item is required.
   *
   * @return boolean
   *   The required status of the form item.
   */
  public function isRequired() {
    return $this->getData('required');
  }

  /**
   * Set a form item as disabled.
   *
   * @param $disabled
   */
  public function setDisabled($disabled) {
    $this->setData('disabled', (!empty($disabled)));
  }

  /**
   * Check if a form item is disabled.
   *
   * @return boolean
   *   The disabled status of the form item.
   */
  public function isDisabled() {
    return $this->getData('disabled');
  }

  /**
   * Set the multiple attribute for the form item.
   *
   * @param $multiple
   */
  public function setMultiple($multiple) {
    $this->setData('multiple', (!empty($multiple)));
  }

  /**
   * Check if the form item is multiple.
   *
   * @return mixed|null
   */
  public function isMultiple() {
    return $this->getData('multiple');
  }

  /**
   * Set the limit of values for the form item.
   * @param $limit
   */
  public function setLimit($limit) {
    $this->setData('limit', $limit);
  }

  /**
   * Get the limit of values for the form item.
   * @return mixed|null
   */
  public function getLimit() {
    return $this->getData('limit');
  }

  /**
   * Set the distinct attribute for the form item.
   *
   * @param boolean $distinct
   *
   */
  public function setDistinct($distinct) {
    $this->setData('distinct', (!empty($distinct)));
  }

  /**
   * Check if the form item is distinct.
   *
   * @return boolean
   *   The distinct status of the form item.
   */
  public function isDistinct() {
    return $this->getData('distinct');
  }

  /**
   * Get the allowed values for a form item.
   *
   * @return array
   *   The allowed values for the form item.
   */
  public function getAllowableValues() {
    return $this->getData('allowable_values');
  }

  /**
   * Set the value for a form item.
   *
   * @param mixed $value
   *   The value of the form item.
   */
  public function setValue($value = NULL) {
    // In order to support multiple values, we use an array.
    if (!is_array($value)) {
      $value = explode(\Quanta\Common\Environment::GLOBAL_SEPARATOR, $value);
    }
    $this->setData('value', $value);
  }

  /**
   * Set the current value for a form item.
   *
   * @param mixed $value
   *   The current value of a form item.
   */
  public function setCurrentValue($value = NULL) {
    // In order to support multiple values, we use an array.
    $this->setData('current_value', $value);
  }

  /**
   * Return the value of a form item.
   *
   * @return mixed
   *   The value of the form item.
   */
  public function getValue() {
    return $this->getData('value') == INPUT_EMPTY_VALUE ? '' : $this->getData('value');
  }

  /**
   * Return the default value of a form item.
   *
   * @return mixed
   *   The default value of the form item.
   */
  public function getDefaultValue() {
    return $this->getData('default_value') == INPUT_EMPTY_VALUE ? '' : $this->getData('default_value');
  }

  /**
   * Return the current value of a form item.
   *
   * @return mixed
   *   The current value of the form item.
   */
  public function getCurrentValue() {
    return empty($this->getData('current_value')) ? $this->getData('default_value') : $this->getData('current_value');
  }

  /**
   * Return the custom class attribute of a form item.
   *
   * @return string
   *   The class attribute of the form item.
   */
  public function getClass() {
    return implode(' ', $this->getData('class'));
  }


  /**
   * Add a custom class attribute to a form item.
   *
   * @param $class
   *   The class attribute to be added to the form item.
   */
  public function addClass($class) {
    $this->addData('class', array($class));
  }

  /**
   * Sets a custom id attribute to a form item.
   *
   * @param string $id
   *   The custom id of the form item.
   */
  public function setId($id) {
    $this->setData('id', $id);
  }

  /**
   * Return the custom id attribute of a form item.
   *
   * @return string
   *   The id attribute of the form item.
   */
  public function getId() {
    return $this->getData('id');
  }

  /**
   * Return the custom label attribute of a form item.
   *
   * @return string
   *   The class attribute of the form item.
   */
  public function getLabel() {
    return !empty($this->getData('label')) ? $this->getData('label') : $this->getData('title');
  }

  /**
   * Check if a submitted form has validation errors.
   *
   * @return boolean
   *   Returns true if the submitted form has validation errors.
   */
  public function hasValidationErrors() {
    $has_validation_errors = !empty($this->getValidationErrors());
    return $has_validation_errors;
  }

  /**
   * Renders a form item as HTML.
   *
   * @return string
   *   The rendered form item.
   */
  public function renderFormItem() {
    $rendered = '';
    $values = $this->getValue();

    $i = 0;
    // Load and render all existing values...
    foreach ($values as $value) {
      // Set the current value of the form item.
      $this->setCurrentValue($value);
      if ($i > 0) {
        $this->setId($this->getId() . '_' . $i);
      }

      // Render the form item using its custom render function.
      $rend = $this->render();

      // If the item is multiple, add a wrapper for this instance.
      $rendered .= ($this->isMultiple()) ? '<div class="form-item-multiple-wrapper">' . $rend . '</div>' : $rend;
      $i++;
    }

    // Add wrapping, classes, id, labels, including those for validations errors...
    $rendered = '<div class="form-item-wrapper ' .
      ($this->hasValidationErrors() ? 'has-validation-errors' : '') .
      '">' .
      ($this->hasValidationErrors() ? ('<div class="validation-error validation-error">' . $this->getValidationErrors() . '</div>') : '') .
      (!empty($this->getLabel()) ? ('<div class="form-item-label"><label for="' . $this->getId() . '">' . $this->getLabel() . '' . ($this->isRequired() ? '<span class="form-item-required">*</span>' : '') . '</label></div>') : '') .
      $rendered .
      '</div>';

    // Return the full rendered form item.
    return $rendered;
  }

  /**
   * Renders the input item.
   * This function must be implemented by classes extending FormItem.
   *
   * @return mixed
   */
  abstract function render();

  /**
   * Validates correctness of the submitted input item.
   * By default it's always validated, but specific classes extending FormItem
   * can implement specific validation criteria.
   *
   * @return boolean
   *   Return true if the submitted form item is validated.
   */
  public function validate() {
    return TRUE;
  }

  /**
   * Get all the validation errors of the submitted form item.
   *
   * @return mixed
   *   The validation errors of the submitted form item, if any.
   */
  public function getValidationErrors() {
    $errors = $this->getForm()->getData('validation_errors');
    return !empty($errors[$this->getName()]) ? $errors[$this->getName()] : NULL;
  }

  /**
   * Check if this item is the last in the form.
   * (Normally used to create the closing </form> tag.
   *
   * @return boolean
   *   Returns true if the form item is the first one in the form.
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
   *
   * @return boolean
   *   Returns true if the form item is the last one in the form.
   */
  public function isLast() {
    $items = $this->getForm()->getItems();
    print_r(array_keys($items));
    $last = array_pop($items);
    return ($this->getName() == $last->getName());
  }
}
