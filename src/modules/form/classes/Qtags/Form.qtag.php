<?php
namespace Quanta\Qtags;

/**
 * Renders a form with all its inputs, buttons, etc.
 */
class Form extends HtmlTag {
  const FORM_METHOD_POST = 'post';
  const FORM_METHOD_GET = 'get';

  /** @var string $action */
  public $action;
  /** @var string $method */
  public $method;
  /** @var string $target */
  public $target;
  /** @var string $target */
  public $anchor;
  /** @var string $ok_message */
  public $ok_message;
  /** @var FormState $form */
  protected $form_state;
  /** @var string $html_tag */
  protected $html_tag = 'form';

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $this->form_state = \Quanta\Common\FormFactory::getFormState($this->env, $this->getAttribute('name'));

    if (!empty($this->getAttribute('name'))) {
      $this->setId($this->getAttribute('name'));
    }
    $this->form_state->setType($this->getAttribute('type'));
    $this->html_params['action'] = !empty($this->getAttribute('action')) ? $this->getAttribute('action') : '?';
    $this->html_params['target'] = !empty($this->getAttribute('target')) ? $this->getAttribute('target') : '_top';
    $this->html_params['method'] = !empty($this->getAttribute('method')) ? $this->getAttribute('method') : self::FORM_METHOD_POST;
    $this->setOkMessage(!empty($this->getAttribute('ok_message')) ? $this->getAttribute('ok_message') : \Quanta\Common\Localization::t('Your form has been submitted.'));

    // Check if the form has been submitted.
    // If the form has been submitted, validate it.
    if ($this->form_state->isSubmitted() && $this->form_state->isValidated()) {
      // If there are no errors, redirect OR show the OK message of the form.
      if (!empty($this->getAttribute('redirect'))) {
        \Quanta\Common\Api::redirect($this->getAttribute('redirect'));
      }
      else {
        $this->html_body = $this->getOkMessage();
      }
    }
    else {
      $inner_attr = array('name' => 'form_submit');
      $this->html_body = (new \Quanta\Qtags\FormItemHidden($this->env, $inner_attr))->render();
      $inner_attr = array('name' => 'form', 'value' => $this->getAttribute('name'));
      $this->html_body .= (new \Quanta\Qtags\FormItemHidden($this->env, $inner_attr))->render();
      $inner_attr = array('name' => 'form_type', 'value' => $this->getAttribute('type'));
      $this->html_body .= (new \Quanta\Qtags\FormItemHidden($this->env, $inner_attr))->render();
      $this->html_body .= $this->getTarget();
    }

    // Add attached HTML.
    $this->html_body .= $this->form_state->attach;
    return parent::render();
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
   * @return mixed|null
   */
  public function getAnchor() {
    return $this->anchor;
  }

  /**
   * @param $anchor
   */
  public function setAnchor($anchor) {
    $this->anchor = $anchor;
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
  public function getOkMessage() {
    return $this->ok_message;
  }

  /**
   * @param $ok_message
   */
  public function setOkMessage($ok_message) {
    $this->ok_message = $ok_message;
  }
}
