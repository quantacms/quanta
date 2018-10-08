<?php
/**
 *
 */
define("FORM_METHOD_POST", 'post');
/**
 *
 */
define("FORM_METHOD_GET", 'get');
/**
 *
 */
define("FORM_PAGE_FORM", '___page_form___');
/**
 * Class Form
 * This class represents a HTML Form, that's a container of form items.
 * @see FormItem
 */
class Form extends DataContainer {
  /**
   * @var string $method
   */
  public $method;
  /**
   * @var string $target
   */
  public $target;
  /**
   * @var string $action
   */
  public $action;
  /**
   * @var array $items
   */
  public $items = array();
  /**
   * @var string $id
   */
  public $id;
  /**
   * @var bool $validated
   */
  public $validated = FALSE;
  /**
   * @var string $ok_message
   */
  public $ok_message;
  /**
   * @var string $type
   */
  public $type;

  /**
   *
   * Constructs a form.
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
  public function __construct(&$env, $form_id, $attributes) {
    $this->env = $env;
    // Determine in which page the form loads.
    $this->setId($form_id);
    $this->loadAttributes($attributes);
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
      $this->setData('validation_errors', array());
      // Run validation hooks for the form.
      $vars = array('form' => &$this);
      $this->env->hook('form_validate', $vars);
      $this->env->hook('form_type_' . $this->getType() . '_validate', $vars);
      $this->env->hook($this->getId() . '_form_validate', $vars);

      // Check if any form item has thrown validation errors...
      if (empty($this->getData('validation_errors'))) {
        $this->validated = TRUE;
        $this->env->hook('form_submit', $vars);
        $this->env->hook('form_type_' . $this->getType() . '_submit', $vars);
        $this->env->hook($this->getId() . '_form_submit', $vars);
        if (empty($this->getRedirect())) {
          return $this->getOkMessage();
        }
        else {
          redirect($this->getRedirect());
        }
      }
    }
    // If the form has not been submitted, it can not been validated.
    return FALSE;

  }

  /**
   * Add a Form Item to the form.
   *
   * @param string $form_item_name
   *   The name of the form item.
   *
   * @param FormItem $form_item
   *   The form item.
   */
  public function addItem($form_item_name, $form_item) {
    $form_item->setForm($this);
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
   * Gets the values of the items in the form.
   *
   * @return mixed
   *   The values of the items in the form.
   */
  public function getFormValues() {

    $items = $this->getItems();
    $values = array();
    foreach ($items as $key => $item) {
      $values[$key] = $item->getValue();
    }
    return $values;
  }

  /**
   * Gets the method of the form.
   *
   * @return mixed
   *   The method of the form.
   */
  public function getMethod() {
    return $this->method;
  }

  /**
   * Sets the method of the form.
   *
   * @param $method
   *   The method of the form.
   */
  public function setMethod($method) {
    $this->method = $method;
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
   * @return mixed|null
   */
  public function getAnchor() {
    return $this->getData('anchor');
  }

  /**
   * @param $anchor
   */
  public function setAnchor($anchor) {
    $this->setData('anchor', $anchor);
  }

  /**
   * @return mixed|null
   */
  public function getRedirect() {
    return $this->getData('redirect');
  }

  /**
   * @param $redirect
   */
  public function setRedirect($redirect) {
    $this->setData('redirect', $redirect);
  }
  
	/**
   * Gets the form target.
   *
   * @return mixed
   */
  public function getTarget() {
    return $this->target;
  }

  /**
   * Sets the form target.
   *
   * @param string $target
	 *   The form target.
   */
  public function setTarget($target) {
    $this->target = $target;
  }

  /**
   * Gets the form action (page).
   *
   * @return mixed
   */
  public function getAction() {
    return $this->action;
  }

  /**
   * Sets the form action (page).
   *
   * @param $action
   */
  public function setAction($action) {
    $this->action = $action;
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
   * @return mixed
   */
  public function getOkMessage() {
    return $this->ok_message;
  }

  /**
   * @param $ok_message
   */
  public function setOkMessage($ok_message) {
    $this->ok_message = $ok_message;
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
   * Render the form and its HTML.
   * @return mixed
   */
  public function renderFormOpen() {
   $rendered = '<a name="' . $this->getId() . '"></a>';
    $rendered .= '<form target="' . $this->getTarget() . '" action="' . $this->getAction() .
     '#' . $this->getAnchor() .'" method="' . $this->getMethod() .
     '" id="' . $this->getId() . '">';
    // TODO: why two fields? check shadow...
     $rendered .= '<input type="hidden" name="form_submit" value=""/> ';
    $rendered .= '<input type="hidden" name="form" value="' . $this->getId() . '"/> ';
    $rendered .= '<input type="hidden" name="form_type" value="' . $this->getType() . '"/> ';

    return $rendered;
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
   * Load form attributes.
   * @param $attributes
   */
  public function loadAttributes($attributes) {
    $this->setType((isset($attributes['type'])) ? $attributes['type'] : 'form');
    $this->setAction((isset($attributes['action'])) ? '/' . ($attributes['action']) : '?');
    $this->setTarget((isset($attributes['target'])) ? '/' . ($attributes['target']) : '_top');
    $this->setMethod((isset($attributes['method'])) ? $attributes['method'] : FORM_METHOD_POST);
    $this->setOkMessage((isset($attributes['ok_message'])) ? $attributes['ok_message'] : 'Your form has been submitted.');
    $this->setAnchor((isset($attributes['anchor'])) ? $attributes['anchor'] : $this->getId());

  }



  /**
   * Check the form for validation errors.
   *
   * @param FormItem $form_item
   *   The Form item for which to check validation errors.
   * @return bool
   *   Returns true if the value of the form item is valid.
   */
  public function checkValidationErrors($form_item) {
    $validation_errors = $this->getData('validation_errors');
    return !empty($validation_errors[$form_item->getName()]) ? $validation_errors[$form_item->getName()] : FALSE;
  }

  /**
   * Throw a validation error in the form.
   *
   * @param FormItem $form_item
   *   The form item.
   *
   * @param string $error
   *   The error to throw.
   */
  public function validationError($form_item, $error) {
    $this->addData('validation_errors', array($form_item->getName() => $error));
  }

}
