<?php
define("FORM_METHOD_POST", 'post');
define("FORM_METHOD_GET", 'get');
define("FORM_PAGE_FORM", '___page_form___');
/**
 * Class Form
 * This class represents a Page (corrisponding to a rendered html page).
 */
class Form extends DataContainer {
  public $html;
  public $includes;
  public $file;
  public $items = array();
  public $id;
  public $validated = FALSE;
  public $ok_message;
  public $type;

  /**
   * @param $env Environment
   * @param $form_id
   * @param $attributes
   * @internal param $filename
   * @internal param null $name
   * @internal param null $content
   */
  public function __construct(&$env, $form_id, $attributes) {
    $this->env = $env;
    // Determine in which page the form loads.
    $this->setId($form_id);
    $this->loadAttributes($attributes);
  }

  public function checkValidate() {
    // FORM submission handler.
    if ($this->isSubmitted()) {
      $this->setData('validation_errors', array());

      $this->env->hook('form_validate', array('form' => $this));
      $this->env->hook('form_type_' . $this->getType() . '_validate', array('form' => $this));
      $this->env->hook($this->getId() . '_form_validate', array('form' => $this));

      if (empty($this->getData('validation_errors'))) {
        $this->validated = TRUE;
        $this->env->hook('form_submit', array('form' => $this));
        $this->env->hook('form_type_' . $this->getType() . '_submit', array('form' => $this));
        $this->env->hook($this->getId() . '_form_submit', array('form' => $this));
        return $this->getOkMessage();
      }
    } else {
      return FALSE;
    }
  }

  public function addItem($form_item_name, $form_item) {
    $form_item->setForm($this);
    $this->items[$form_item_name] = $form_item;
  }

  public function getItems() {
    return $this->items;
  }

  public function getItem($name) {
    return (isset($this->items[$name]) ? $this->items[$name] : NULL);
  }

  public function getMethod() {
    return $this->method;
  }

  public function setMethod($method) {
    $this->method = $method;
  }

  public function getId() {
    return $this->id;
  }

  public function setId($id) {
    $this->id = $id;
  }

  public function getAnchor() {
    return $this->getData('anchor');
  }

  public function setAnchor($anchor) {
    $this->setData('anchor', $anchor);
  }

  public function getRedirect() {
    return $this->getData('redirect');
  }

  public function setRedirect($redirect) {
    $this->setData('redirect', $redirect);
  }

  public function getAction() {
    return $this->action;
  }

  public function setAction($action) {
    $this->action = $action;
  }

  public function getType() {
    return $this->type;
  }

  public function setType($type) {
    $this->type = $type;
  }

  public function getOkMessage() {
    return $this->ok_message;
  }

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
    $rendered .= '<form action="' . $this->getAction() .
     '#' . $this->getAnchor() .'" method="' . $this->getMethod() .
     '" id="' . $this->getId() . '">';
    // TODO: why two fields? check shadow...
     $rendered .= '<input type="hidden" name="form_submit" value=""/> ';
    $rendered .= '<input type="hidden" name="form" value="' . $this->getId() . '"/> ';
    $rendered .= '<input type="hidden" name="form_type" value="' . $this->getType() . '"/> ';

    return $rendered;
  }


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
    $this->setAction((isset($attributes['action'])) ? '/' . $attributes['action'] : '?');
    $this->setMethod((isset($attributes['method'])) ? $attributes['method'] : FORM_METHOD_POST);
    $this->setOkMessage((isset($attributes['ok_message'])) ? $attributes['ok_message'] : 'Your form has been submitted.');
    $this->setAnchor((isset($attributes['anchor'])) ? $attributes['anchor'] : $this->getId());
    //$this->setRedirect((isset($attributes['redirect'])) ? $attributes['redirect'] : NULL);

  }



  /**
   * Check the form for validation errors.
   * @param $form_item
   * @return bool
   */
  public function checkValidationErrors($form_item) {
    $validation_errors = $this->getData('validation_errors');
    return !empty($validation_errors[$form_item->getName()]) ? $validation_errors[$form_item->getName()] : FALSE;
  }

  /**
   * Throw a validation error in the form.
   * @param $input
   * @param $error
   */
  public function validationError($input, $error) {
    $this->addData('validation_errors', array($input->getName() => $error));
  }

}
