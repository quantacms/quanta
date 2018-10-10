<?php
/**
 * Class Page
 * This class represents a Page (corrisponding to a rendered html page).
 */
class FormFactory {

  /**
   * Create an empty form.
   *
   * @param Environment $env
   *   The Environment.
   * @param string $form_id
   *   The form ID.
   *
   * @return Form
   *   The created form.
   */
  public static function createForm($env, $form_id) {
    $form = new Form($env, $form_id, array());
    return $form;
  }

  /**
   * Retrieves a form.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $form_id
   *   The id of the form.
   *
   * @return Form
   *   The retrieved form.
   */
  public static function getForm($env, $form_id = FORM_PAGE_FORM) {
    $form = $env->getData('form_' . $form_id);
    if (empty($form)) {
      $form = FormFactory::createForm($env, $form_id);
      $env->setData('form_' . $form_id, $form);
    }

    return $form;
  }

  /**
   * Create an input item and add it into a form.
   *
   * @param Environment $env
   *   The environment.
   *
   * @param array $input
   *   The input item as it comes from the qtag.
   *
   * @param Form $form
   *   The form.
   *
   * @return FormItem
   *   The constructed form item.
   */
  public static function createInputItem($env, $input, $form) {
    if (empty($input['type'])) {
      $input['type'] = 'string';
    }
    switch($input['type']) {

      case 'FileObject':
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

      case 'password':
        $formitem = new FormItemPassword($env, $input);
        break;

      case 'submit':
        $formitem = new FormItemSubmit($env, $input);
        break;

      case 'autocomplete':
        $formitem = new FormItemAutocomplete($env, $input);
        break;

      case 'string':
      default:
      // TODO: use a hook to eventually get custom formitem items from other modules.
      $formitem = new FormItemString($env, $input);
        break;

    }

    $form->addItem($formitem->getName(), $formitem);
    return $formitem;

  }
}
