<?php
namespace Quanta\Common;

define("FORM_PAGE_FORM", '___page_form___');
/**
 * Class Form
 * This class represents a HTML Form, that's a container of form items.
 * @see FormItem
 */
class FormState extends DataContainer {
  /**
   * @var array $items
   */
  public $items = array();
  /**
   * @var bool $validated
   */
  public $validated = FALSE;
  /**
   * @var string $type
   */
  public $type;
  /**
   * @var string $attach
   */
  public $attach;

  /**
   *
   * Constructs a form state.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $form_id
   *   The form ID.
   *
   * @param array $attributes
   *   The form attributes.
   */
  public function __construct(&$env, $form_id, $form_type = NULL) {
    $this->env = $env;
    // Determine in which page the form loads.
    $this->setId($form_id);
    $this->setType($form_type);

    // Add Request Data to the form state.
    foreach ($_REQUEST as $key => $value) {
      $this->addData($key, $value);
    }
  }

  /**
   * Check if the form is valid.
   *
   * @return bool
   *   True if the form items in the form contain valid values.
   */
  public function checkValidate() {
    // FORM submission handler.
    if ($this->isSubmitted()) {

      //$this->setData('validation_errors', array());
      // Run validation hooks for the form.
      $vars = array('form_state' => &$this);

      // Form pre-validation (Generic).
      $this->env->hook('form_pre_validate', $vars);

      // Form pre-validation by ID hook.
      $this->env->hook($this->getId() . '_form_pre_validate', $vars);

      // Form validation (Generic)
      $this->env->hook('form_validate', $vars);

      // Form validation by type hook.
      if (!empty($this->getType())) {
        $this->env->hook('form_type_' . $this->getType() . '_validate', $vars);
      }
      // Form validation by ID hook.
      $this->env->hook($this->getId() . '_form_validate', $vars);

      // Check if any form item has thrown validation errors...
      if (empty($this->getData('validation_errors'))) {
        $this->validated = TRUE;
        $this->env->hook('form_submit', $vars);
        $this->env->hook('form_type_' . $this->getType() . '_submit', $vars);
        $this->env->hook($this->getId() . '_form_submit', $vars);
        $this->env->hook('form_type_' . $this->getType() . '_after_submit', $vars);
        $this->env->hook($this->getId() . '_form_after_submit', $vars);
      }
      else {
        // Form has validation errors.
        $this->env->hook('form_validation_errors', $vars);
      }
    }
    // If the form has not been submitted, it can not been validated.
    return $this->isValidated();

  }

  /**
   * Add a Form Item to the form.
   *
   * @param string $form_item_name
   *   The name of the submitted form item.
   *
   * @param \Quanta\Qtags\FormItem $form_item
   *   The submitted form item value.
   */
  public function addItem($form_item_name, $form_item) {
    $this->items[$form_item_name] = $form_item;
  }

  /**
   * Gets all the form items of the form.
   *
   * @return array
   *   All the form items that have been added to the Form.
   */
  public function getItems() {
    return $this->items;
  }

  /**
   * Get a specific form item of the form.
   *
   * @param string $name
   *   The name of the form item to retrieve.
   *
   * @return FormItem|null
   *   The form item, if it's contained in the form, null otherwise.
   */
  public function getItem($name) {
    return (isset($this->items[$name]) ? $this->items[$name] : NULL);
  }

  /**
   * Gets the id of the form.
   *
   * @return string
   *   The id of the form.
   */
  public function getId() {
    return $this->id;
  }

  /**
   * @param $id
   */
  public function setId($id) {
    $this->id = $id;
  }

  /**
   * @return mixed
   */
  public function getType() {
    return $this->type;
  }

  /**
   * @param $type
   */
  public function setType($type) {
    $this->type = $type;
  }

  /**
   * Check if the form contains any item (yet).
   * @return bool
   */
  public function isEmpty() {
    return (count($this->items) == 0);
  }

  /**
   * Check if the form has been submitted.
   * @return bool
   */
  public function isSubmitted() {
    return (isset($_REQUEST['form_submit']) && ($_REQUEST['form'] == $this->getId()));
  }

  /**
   * Check if the form has been submitted.
   * @return bool
   */
  public function isValidated() {
    return $this->validated;
  }

  /**
	 * Close the form.
   * @return string
   */
  public function renderFormClose() {
    $rendered = '</form>';
    return $rendered;
  }

  /**
   * Check the form for validation errors.
   *
   * @param string $form_item
   *   The Form item for which to check validation errors.
   * @return bool
   *   Returns true if the value of the form item is valid.
   */
  public function checkValidationErrors($form_item) {
    $validation_errors = $this->getData('validation_errors');
    return !empty($validation_errors[$form_item]) ? $validation_errors[$form_item] : FALSE;
  }

  /**
   * Throw a validation error in the form.
   *
   * @param string $form_item
   *   The name of the form item.
   *
   * @param string $error
   *   The error to throw.
   */
  public function validationError($form_item, $error) {
    $this->addData('validation_errors', array($form_item => $error));
  }

  /**
   * Attaches some HTML to the form.
   *
   * @param $label
   *   Label of the attached HTML (to avoid double inclusions).
   * @param $html
   *   HTML to attach.
   */
  public function attach($label, $html) {
    static $attaches;
    if (empty($attaches)) {
      $attaches = array();
    }
    if (empty($attaches[$label])) {
      $this->attach .= $html;
      $attaches[$label] = TRUE;
    }

  }

}
