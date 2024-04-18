<?php
namespace Quanta\Qtags;

/**
 * Creates a GRID that can contain other content.
 */
class Grid extends HtmlTag {
  protected $html_tag = 'div';
  protected $html_params = array('class' => 'grid');
}
