<?php
namespace Quanta\Common;

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
   * @return FormState
   *   The created form.
   */
  public static function createFormState($env, $form_id, $type = NULL) {
    $form = new FormState($env, $form_id, $type);
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
   * @return FormState
   *   The retrieved form state.
   */
  public static function getFormState($env, $form_id = FORM_PAGE_FORM) {
    $form_state = $env->getData('form_' . $form_id);

    if (empty($form_state)) {
      $form_state = FormFactory::createFormState($env, $form_id);
      $env->setData('form_' . $form_id, $form_state);
    }
    $form_state->setType($form_state->getData('form_type'));
    return $form_state;
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
   * @param FormState $form
   *   The form.
   *
   * @return \Quanta\Qtags\FormItem
   *   The constructed form item.
   */
  public static function createInputItem($env, $input, &$form) {

    if (empty($input['type'])) {
      $input['type'] = 'string';
    }
    switch($input['type']) {

      case 'file':
        $formitem = new \Quanta\Qtags\FormItemFile($env, $input, $form);
        break;

      case 'text':
        $formitem = new \Quanta\Qtags\FormItemText($env, $input, $form);
        break;

      case 'hidden':
        $formitem = new \Quanta\Qtags\FormItemHidden($env, $input, $form);
        break;
      case 'select':
        $formitem = new \Quanta\Qtags\FormItemSelect($env, $input, $form);
        break;
      case 'checkboxes':
        $formitem = new \Quanta\Qtags\FormItemCheckboxes($env, $input, $form);
        break;
      case 'checkbox':
        $formitem = new \Quanta\Qtags\FormItemCheckbox($env, $input, $form);
        break;
      
      case 'radio':
        $formitem = new \Quanta\Qtags\FormItemRadio($env, $input, $form);
        break;

      case 'date':
        $formitem = new \Quanta\Qtags\FormItemDate($env, $input, $form);
        break;

      case 'time':
        $formitem = new \Quanta\Qtags\FormItemTime($env, $input, $form);
        break;

      case 'number':
        $formitem = new \Quanta\Qtags\FormItemNumber($env, $input, $form);
        break;

      case 'email':
        $formitem = new \Quanta\Qtags\FormItemEmail($env, $input, $form);
        break;

      case 'url':
        $formitem = new \Quanta\Qtags\FormItemUrl($env, $input, $form);
        break;

      case 'password':
        $formitem = new \Quanta\Qtags\FormItemPassword($env, $input, $form);
        break;

      case 'submit':
        $formitem = new \Quanta\Qtags\FormItemSubmit($env, $input, $form);
        break;

      case 'autocomplete':
        $formitem = new \Quanta\Qtags\FormItemAutocomplete($env, $input, $form);
        break;

      case 'tel':
        $formitem = new \Quanta\Qtags\FormItemTel($env, $input, $form);
        break;

      case 'string':
      default:
        // TODO: use a hook to eventually get custom formitem items from other modules.
        $formitem = new \Quanta\Qtags\FormItemString($env, $input, $form);
      break;
    }

    
    // Add the form item to the form state.
    $form->addItem($formitem->getName(), $formitem);

    return $formitem;

  }
}
