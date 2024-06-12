<?php
namespace Quanta\Qtags;

/**
 * Class FormItemText
 * This class represents a Form Item of type textarea
 */
class FormItemText extends FormItem {
  public $type = 'text';
  protected $html_tag = 'textarea';

  /**
   * Render the form item.
   */
  function render() {
    $this->setLabel($this->getTitle());

    if (!empty($this->getAttribute('wysiwyg'))) {
      $this->addClass('wysiwyg');
    }
    $cols = $this->getAttribute('cols');
    $rows = $this->getAttribute('rows');
    $this->html_params['cols'] = (($cols > 0) ? $cols : 50);
    $this->html_params['rows'] = (($rows > 0) ? $rows : 10);

    $this->html_body = $this->getDefaultValue();
    return parent::render();
  }
}
