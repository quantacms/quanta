<?php
namespace Quanta\Qtags;

/**
 * Class FormItem
 * This class represents an abstract FormItem, to be extended by specific
 * form item types.
 */
abstract class FormItem extends HtmlTag {
  const INPUT_EMPTY_VALUE = '___INPUT_EMPTY_VALUE___';

  public $env;
  protected $name;
  protected $type;
  protected $title;
  protected $label;
  protected $label_position = Label::LABEL_ON_TOP;
  /** @var \Quanta\Common\FormState $form */
  protected $form_state;
  protected $html_tag;
  /** @var boolean $required */
  protected $required;
  /** @var boolean $disabled */
  protected $disabled;
  /** @var boolean $multiple */
  protected $multiple;
  /** @var int $limit */
  protected $limit;
  /** @var boolean $distinct */
  protected $distinct;
  /** @var array $allowable_values */
  protected $allowable_values = array();
  /** @var mixed $value */
  protected $value;
  /** @var mixed $current_value */
  protected $current_value;
  /** @var mixed $default_value */
  protected $default_value;
  /** @var mixed $input_arr */
  protected $input_arr;
  /** @var int $length */
  protected $length;


  /**
   * Constructs the form item with all its attributes.
   *
   * @param $env Environment
   * @param $input
   * @param null $form
   */
  public function __construct(&$env, $input, $form = NULL) {
    // Setup the form object for this Form Item.
    if (!empty($form)) {
      $this->setFormState($form);
    }

    $this->input_arr = $input;
    $this->env = $env;

    // Check all the form item's attributes.
    $this->setName($this->getAttribute('name'));
    $this->addClass('form-item form-item-' . $this->getType());

    $this->setTitle(!empty($this->getAttribute('title')) ? $this->getAttribute('title') : '');

    $this->setLabel(!empty($this->getAttribute('label')) ? $this->getAttribute('label') : $this->getTitle());

    $this->setId(!empty($this->getAttribute('id')) ? $this->getAttribute('id') : ('input-' . $this->getAttribute('name')));
    $this->checkRequired();
    $this->checkDisabled();
    $this->checkMultiple();
    $this->checkLimit();
    $this->checkLength();
    $this->checkDistinct();
    $this->loadValue();
    $this->loadDefault();
    $this->loadAllowableValues();
  }

  /**
   * The PlaceHolder text for this form item.
   *
   * @return string
   *   The placeholder text for this form item.
   */
  public function getPlaceHolder() {
    return $this->getAttribute('placeholder');
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
   * Sets the Form State for this form item.
   *
   * @param \Quanta\Common\FormState
   *   The Form State object.
   */
  public function setFormState(&$form_state) {
    $this->form_state = $form_state;
  }

  /**
   * Returns the Form State object containing this form item.
   *
   * @return \Quanta\Common\FormState
   */
  public function getFormState() {
    return $this->form_state;
  }

  /**
   * Checks if the form item is multiple. Add a custom class, to indicate
   * it's multiple or single.
   */
  public function checkMultiple() {
    $this->setMultiple($this->getAttribute('multiple'));
    $this->addClass($this->isMultiple() ? 'form-item-multiple' : 'form-item-single');
  }

  /**
   * Checks if the form item has a limit. Add a custom class, to indicate if
   * it's limited or unlimited.
   */
  public function checkLimit() {
    $this->setLimit($this->getAttribute('limit'));
    $this->addClass($this->limit ? 'form-item-limited' : 'form-item-unlimited');
  }

    /**
   * Checks if the form item has a length. Add a custom class, to indicate if
   * it's limited or unlimited.
   */
  public function checkLength() {
    $this->setLength($this->getAttribute('length'));
  }

  /**
   * Checks if the form item is distinct. Add a custom class, if it's required.
   */
  public function checkDistinct() {
    $this->setDistinct($this->getAttribute('distinct'));
    $this->addClass($this->isDistinct() ? 'form-item-distinct' : 'form-item-not-distinct');
  }

  /**
   * Checks if the form item is disabled. Add a custom class, if it's required.
   */
  public function checkDisabled() {
    $this->setDisabled($this->getAttribute('disabled'));
    $this->addClass($this->isDisabled() ? 'form-item-disabled' : 'form-item-enabled');
  }

  /**
   * Checks if the form item is required. Add a custom class, if it's required.
   */
  public function checkRequired() {
    $this->setRequired($this->getAttribute('required'));
    $this->addClass($this->isRequired() ? 'required' : 'not-required');
  }

  /**
   * Load the value for this form item.
   */
  public function loadValue() {
    $submitted = $this->getSubmittedValue();
    $value = (empty($submitted)) ? (($this->getAttribute('value') != self::INPUT_EMPTY_VALUE ? $this->getAttribute('value') : '')) : $submitted;
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
    $parsed_value = array($value[0] => (!empty($value[1]) ? trim($value[1]) : trim($value[0])));
    return $parsed_value;
  }

  /**
   * Load all the allowable value for a form item.
   * It will create <option> tags in <select>,
   * radio button options, etc.
   */
  public function loadAllowableValues() {
    if (!empty($this->getAttribute('empty'))) {
      $this->allowable_values = self::parseAllowableValue(self::INPUT_EMPTY_VALUE . '---' . $this->getAttribute('empty'));
    }

    $values = array();

    // If there is a range=x-y attribute, use it to calculate allowable values...
    if (!empty($this->getAttribute('range'))) {
      $split = explode('-', $this->getAttribute('range'));
      for ($i = $split[0]; $i <= $split[1]; $i++) {
        $values[$i] = $i;
      }
    } // ...otherwise look for the values attribute (that might be NULL, of course!)
    else {
      if ($this->getAttribute('values') != NULL) {
	$values = explode(',', $this->getAttribute('values'));
      }
    }

    // Check the allowable values for this form item.
    foreach ($values as $v) {
      $this->allowable_values[] = $this::parseAllowableValue($v);
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
    // TODO: PROBABLY WRONG. 
    if (is_array($value)) {
    	$single_value = array_pop($value);
    }
    // If there is already a value set for the input item, ignore the default.
    $this->default_value = $this->getAttribute('default');
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
   * Set the title of a form item.
   *
   * @param string $title
   */
  public function setTitle($title) {
    $this->title = $title;
  }

  /**
   * Get the title of a form item.
   *
   * @return string
   */
  public function getTitle() {
    return $this->title;
  }

  /**
   * Set the label of a form item.
   *
   * @param string $label
   */
  public function setLabel($label) {
    $this->label = $label;
  }

  /**
   * Get the label of a form item.
   *
   * @return string
   */
  public function getLabel() {
    return $this->label;
  }

  /**
   * Retrieve the value of an input attribute from the [INPUT] tag.
   * @param $attribute
   * @return bool|null
   */
  public function getAttribute($attribute, $empty_value = NULL) {
    return !isset($this->input_arr[$attribute]) ? NULL :
      (empty($this->input_arr[$attribute]) ? self::INPUT_EMPTY_VALUE : $this->input_arr[$attribute]);
  }

  /**
   * Set a form item as required.
   *
   * @param boolean $required
   */
  public function setRequired($required) {
    $this->required = !empty($required);
  }

  /**
   * Check if a form item is required.
   *
   * @return boolean
   *   The required status of the form item.
   */
  public function isRequired() {
    return $this->required;
  }

  /**
   * Set a form item as disabled.
   *
   * @param $disabled
   */
  public function setDisabled($disabled) {
    $this->disabled = !empty($disabled);
  }

  /**
   * Check if a form item is disabled.
   *
   * @return boolean
   *   The disabled status of the form item.
   */
  public function isDisabled() {
    return $this->disabled;
  }

  /**
   * Set the multiple attribute for the form item.
   *
   * @param $multiple
   */
  public function setMultiple($multiple) {
    $this->multiple = !empty($multiple);
  }

  /**
   * Check if the form item is multiple.
   *
   * @return mixed|null
   */
  public function isMultiple() {
    return $this->multiple;
  }

  /**
   * Set the limit of values for the form item.
   * @param $limit
   */
  public function setLimit($limit) {
    $this->limit = $limit;
  }

  /**
   * Get the limit of values for the form item.
   * @return mixed|null
   */
  public function getLimit() {
    return $this->limit;
  }

    /**
   * Set the length of values for the form item.
   * @param $length
   */
  public function setLength($length) {
    $this->length = $length;
  }

  /**
   * Get the length of values for the form item.
   * @return mixed|null
   */
  public function getLength() {
    return $this->length;
  }

  /**
   * Set the distinct attribute for the form item.
   *
   * @param boolean $distinct
   *
   */
  public function setDistinct($distinct) {
    $this->distinct = !empty($distinct);
  }

  /**
   * Check if the form item is distinct.
   *
   * @return boolean
   *   The distinct status of the form item.
   */
  public function isDistinct() {
    return $this->distinct;
  }

  /**
   * Get the allowed values for a form item.
   *
   * @return array
   *   The allowed values for the form item.
   */
  public function getAllowableValues() {
    return $this->allowable_values;
  }

  /**
   * Set the value for a form item.
   *
   * @param mixed $value
   *   The value of the form item.
   */
  public function setValue($value = NULL) {
    // In order to support multiple values, we use an array.
    if (($value != NULL) && !is_array($value)) {
      $value = explode(\Quanta\Common\Environment::GLOBAL_SEPARATOR, $value);
    }
    $this->value = $value;
  }

  /**
   * Set the current value for a form item.
   *
   * @param mixed $value
   *   The current value of a form item.
   */
  public function setCurrentValue($value = NULL) {
    // In order to support multiple values, we use an array.
    $this->current_value = $value;
  }

  /**
   * Return the value of a form item.
   *
   * @param bool $pop
   *   If true, return only the first element of the array, as a string.
   *
   * @return mixed
   *   The value of the form item.
   */
  public function getValue($pop = FALSE) {
    $return_value = $this->value == self::INPUT_EMPTY_VALUE ? '' : $this->value;
    if ($pop && is_array($return_value)) {
      $return_value = array_pop($return_value);
    }
    return $return_value;
  }

  /**
   * Return the string value of a form item.
   *
   * @return mixed
   *   The value of the form item.
   */
  public function getStringValue() {
    return $this->getValue(TRUE);
  }

  /**
   * Return the default value of a form item.
   *
   * @return mixed
   *   The default value of the form item.
   */
  public function getDefaultValue() {
    return $this->default_value == self::INPUT_EMPTY_VALUE ? '' : $this->default_value;
  }

  /**
   * Return the default value of a form item.
   *
   * @param mixed $default_value
   *   The default value of the form item.
   */
  public function setDefaultValue($default_value) {
    $this->default_value = $default_value;
  }

  /**
   * Return the current value of a form item.
   *
   * @return mixed
   *   The current value of the form item.
   */
  public function getCurrentValue() {
    return empty($this->current_value) ? $this->getDefaultValue() : $this->current_value;
  }

  /**
   * Renders a form item as HTML.
   *
   * @return string
   *   The rendered form item.
   */
  public function render() {
    if ($this->isDisabled()) {
      $this->html_params['disabled'] = 'disabled';
    }
    if ($this->isRequired()) {
      $this->html_params['required'] = 'required';
    }
    if (!empty($this->getName())) {
      $this->html_params['name'] = $this->getName();
    }
    if (!empty($this->getId())) {
      $this->setId($this->getId());
    }
    if ($this->isMultiple()) {
      $this->html_params['data-multiple'] = 'true';
    }
    if ($this->isDistinct()) {
      $this->html_params['data-distinct'] = 'true';
    }
    if ($this->getLimit()) {
      $this->html_params['data-limit'] = $this->getLimit();
    }
    if ($this->getLength()) {
      $this->html_params['data-length'] = $this->getLength();
    }
    if (!empty($this->getAttribute('node'))) {
      $this->html_params['data-node'] = $this->getAttribute('node');
    }
    if (!empty($this->getAttribute('size'))) {
      $this->html_params['size'] = $this->getAttribute('size');
    }
    if (!empty($this->getAttribute('placeholder'))) {
      $this->html_params['placeholder'] = $this->getAttribute('placeholder');
    }
    if (!empty($this->getAttribute('cols'))) {
      $this->html_params['cols'] = $this->getAttribute('cols');
    }
    if (!empty($this->getAttribute('rows'))) {
      $this->html_params['rows'] = $this->getAttribute('rows');
    }
    if (!empty($this->getAttribute('checked'))) {
      $this->html_params['checked'] = 'checked';
    }
    if($this->getType()== 'radio' && isset($_POST[$this->getName()]) && $this->getId() == $this->getName() .'_'.array_pop($this->getValue()) ){
      $this->html_params['checked'] = 'checked'; 
    }

    // Return the full rendered form item.
    return parent::render();
  }

  /**
   * Validate form item at a general level.
   * I.e. check if the item is required.
   */
  public function validate() {
    if ($this->isRequired() && (empty($this->getValue()))) {
      $this->getFormState()->validationError($this->getName(), \Quanta\Common\Localization::t('This item is required!'));
    }
  }

  /**
   * Returns the position of the label (aside, on top, etc.).
   *
   * @return string
   *   Where is the label positioned for this element.
   */
  public function getLabelPosition() {
    return $this->label_position;
  }
}
