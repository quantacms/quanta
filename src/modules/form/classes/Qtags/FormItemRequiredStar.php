<?php
namespace Quanta\Qtags;

/**
 * Represents a Required Form Item "star" symbol or similar.
 */
class FormItemRequiredStar extends HtmlTag {
  public $html_tag = 'span';
  public $html_params = array('class' => 'form-item-required');
}