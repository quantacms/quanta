<?php
/**
 * Class Page
 * This class represents a Page (corrisponding to a rendered html page).
 */
class FormFactory {

  public static function createForm($env, $form_id) {
    $form = new Form($env, $form_id, array());
    return $form;
  }

  public static function getForm($env, $form_id) {
    $form = $env->getData('form_' . $form_id);
    return (empty($form)) ? FormFactory::createForm($env, $form_id) : $form;
  }

  public static function createInputItem($env, $input) {
    switch($input['type']) {

      case 'file':
        $formitem = new FormItemFile($env, $input);
        break;

      case 'text':
        $formitem = new FormItemText($env, $input);
        break;

      case 'hidden':
        $formitem = new FormItemHidden($env, $input);
        break;

      case 'select':
        $formitem = new FormItemSelect($env, $input);
        break;

      case 'checkbox':
        $formitem = new FormItemCheckbox($env, $input);
        break;

      case 'date':
        $formitem = new FormItemDate($env, $input);
        break;

      case 'time':
        $formitem = new FormItemTime($env, $input);
        break;

      case 'number':
        $formitem = new FormItemNumber($env, $input);
        break;

      case 'email':
        $formitem = new FormItemEmail($env, $input);
        break;

      case 'submit':
        $formitem = new FormItemSubmit($env, $input);
        break;

      case 'string':
      default:
      // TODO: use a hook to eventually get custom formitem items from other modules.
      $formitem = new FormItemString($env, $input);
        break;

    }
    return $formitem;

  }
}
