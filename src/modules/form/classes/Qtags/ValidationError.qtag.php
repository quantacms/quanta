<?php
namespace Quanta\Qtags;

/**
 * Represents an HTML Option item.
 */
class ValidationError extends HtmlTag {
  public $html_tag = 'div';
  public $html_params = array('class' => 'validation-error');
}