<?php
namespace Quanta\Qtags;

/**
 * Prepares an input item of a form for rendering.
 */
class Input extends HtmlTag {
  protected $form_state;
  protected $form_item;

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->form_state = \Quanta\Common\FormFactory::getFormState($this->env, $this->getTarget());
    $this->form_item = \Quanta\Common\FormFactory::createInputItem($this->env, $this->attributes, $this->form_state);

    if ($this->form_state->isSubmitted()) {
      $this->form_item->validate();

      $vars = array('form_item' => &$this->form_item);
      // TODO: add other useful smart hooks.
      $this->env->hook('form_item_validate', $vars);
      $this->env->hook($this->getFormState()->getId() . '_form_item_validate', $vars);
    }

    $form_item_values = $this->form_item->getValue();
    $values = is_array($form_item_values) ? $form_item_values : array($form_item_values);
    $i = 0;
    // Load and render all existing values...
    foreach ($values as $value) {
      // Set the current value of the form item.
      $this->form_item->setDefaultValue($value);
      if ($i > 0) {
        $this->form_item->setId($this->form_item->getName() . '-' . $i);
      }
      // Add a class to the form item directly.
      if (!empty($this->getAttribute('input_class'))) {
        $this->form_item->addClass($this->getAttribute('input_class'));
      }
      // Render the form item using its custom render function.
      $html = $this->form_item->render();
      // If item is password add div around input
      if ($this->form_item->getType() == 'password') {
        $html = '<div class="password-input-wrapper">' .$html . '</div>';
      }
      // If the item is multiple, add a wrapper for this instance.
      $this->html_body .= ($this->form_item->isMultiple()) ? ('<div class="form-item-multiple-wrapper">' . $html . '</div>') :  $html;
      $i++;
    }

    // Name attribute is mandatory to render an input.
    if (!(empty($this->getAttribute('name')))) {
      // Add a label to the form item.
      if (!empty($this->form_item->getLabel())) {
        $label_attributes = array(
          'label_position' => $this->form_item->getLabelPosition(),
          'label_text' => $this->form_item->getLabel(),
          'required' => $this->form_item->isRequired(),
          );
        $label = new Label($this->env, $label_attributes, $this->form_item->getId());
        if ($this->form_item->getLabelPosition() == Label::LABEL_ASIDE) {
          $this->html_body = $this->html_body . $label->render();
	}
        elseif ($this->form_item->getLabelPosition() == Label::LABEL_ON_TOP) {
          $this->html_body = $label->render() . $this->html_body;
        }
      }

      $this->addClass('form-item-wrapper');
      if ($this->hasValidationErrors()) {
        $error_attr = array();
        $error = new ValidationError($this->env, $error_attr, $this->getValidationErrors());
        $this->html_body = $error . $this->html_body;
        $this->addClass('has-validation-errors');
      }

      // Prevent the wrapper from having the same id as the input item.
      $this->setAttribute('id', 'wrapper-' . $this->form_item->getName());
      return parent::render();
    }
  }

  /**
   * Get all the validation errors of the submitted form item.
   *
   * @return mixed
   *   The validation errors of the submitted form item, if any.
   */
  public function getValidationErrors() {
    $errors = $this->getFormState()->getData('validation_errors');
    return !empty($errors[$this->form_item->getName()]) ? $errors[$this->form_item->getName()] : NULL;
  }

  /**
   * Check if this item is the last in the form.
   * (Normally used to create the closing </form> tag.
   *
   * @return boolean
   *   Returns true if the form item is the first one in the form.
   */
  public function isFirst() {
    $items = $this->getFormState()->getItems();
    $items = array_reverse($items);
    $first = array_pop($items);
    return ($this->form_item->getName() == $first->getName());
  }


  /**
   * Check if this item is the last in the form.
   * (Normally used to create the closing </form> tag.
   *
   * @return boolean
   *   Returns true if the form item is the last one in the form.
   */
  public function isLast() {
    // TODO: deprecated.
    // With the new approach we have no way to determine if an input item is "LAST".
  }

  /**
   * Setup the Form State containing this form item.
   *
   * @param \Quanta\Common\FormState
   */
  public function setFormState(&$form) {
    $this->form_state = $form;
  }

  /**
   * Returns the Form State containing this form item.
   *
   * @return \Quanta\Common\FormState
   */
  public function getFormState() {
    return $this->form_state;
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

}
