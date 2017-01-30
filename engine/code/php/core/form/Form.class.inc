<?php
define("FORM_METHOD_POST", 'post');
define("FORM_METHOD_GET", 'post');

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

  /**
   * @param $env Environment
   * @param $filename
   * @param null $name
   * @param null $content
   */
  public function __construct(&$env, $form_id, $attributes) {
    $this->env = $env;
    // Determine in which page the form loads.
    $this->setId($form_id);
    $this->loadAttributes($attributes);
  }

  public function checkValidate() {
// FORM submission handler.
    if (isset($_REQUEST['form_submit'])) {
      $this->setData('validation_errors', array());
      $this->env->hook('form_validate', array('form' => $this));
      if (empty($this->getData('validation_errors'))) {
        $this->env->hook('form_submit', array('form' => $this));
        $this->env->hook($this->getId() . '_form_submit');
        return (isset($attributes['ok_message']) ? $attributes['ok_message'] : 'Your form has been submitted.');
      } else {
        // TODO: do not use Message here.
        //foreach ($this->getData('validation_errors') as $error) {
          //new Message($this->env, $error, MESSAGE_ERROR, MESSAGE_TYPE_SCREEN);
        //}
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

  public function getAction() {
    return $this->action;
  }

  public function setAction($action) {
    $this->action = $action;
  }

  /**
   * Render the form and its HTML.
   * @return mixed
   */
  public function render() {
   $rendered = '<form action="/' . $this->getAction() .
     '" method="' . $this->getMethod() .
     '" id="' . $this->getId() . '">
     <input type="hidden" name="form_submit"/> ';

    foreach ($this->items as $form_item_name => $form_item) {
      if ($errors = $this->checkValidationErrors($form_item)) {
        $rendered .= '<div class="validation-error validation-error">' . $errors . '</div>';
        $form_item->addData('validation_errors', $errors);
      }
      $rendered .=  $form_item->renderFormItem();
    }

    $rendered .= '</form>';

    return $rendered;
  }

  /**
   * Load form attributes.
   */
  public function loadAttributes($attributes) {
    $this->setAction((isset($attributes['action'])) ? $attributes['action'] : '');
    $this->setMethod((isset($attributes['method'])) ? $attributes['method'] : FORM_METHOD_POST);
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
