<?php
namespace Quanta\Qtags;
/**
 * Render a Simple HTML tag with content.
 */
class HtmlTag extends Qtag {
  protected $html_tag = 'div';
  protected $html_params = array();
  protected $html_body = FALSE;
  protected $html_self_close = FALSE;

  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    // Allow changing the HTML tag with an attribute.
    if (!empty($this->getAttribute('html_tag'))) {
      $this->html_tag = $this->getAttribute('html_tag');
    }
    // Load id.
    if (!empty($this->attributes['id'])) {
      $this->html_params['id'] = $this->attributes['id'];
    }
    // Load classes.
    if (!empty($this->attributes['class'])) {
      if (isset($this->html_params['class'])) {
        $this->html_params['class'] .= ' ' . $this->attributes['class'];
      }
      else {
        $this->html_params['class'] = $this->attributes['class'];
      }
    }
    // Set the body of the html tag.
    if ($this->html_body === FALSE) {
      $this->html_body = $this->getTarget();
    }
    // Open the HTML tag.
    $html = '<' . $this->html_tag . ' ';
    // Add the attributes.
    foreach ($this->html_params as $param_name => $param_value) {
      if (!empty($param_name)) {
        $html .= $param_name . '="' . $param_value . '"';
      }
      else {
        $html .= $param_value;
      }
      $html .= ' ';
    }
    // Self closing tag (i.e. <img />).
    if ($this->html_self_close) {
      $html .= " />";
    }
    else {
      $html .= '>';
      $html .= $this->html_body;
      $html .= '</' . $this->html_tag . '>';
    }
    return $html;
  }

  /**
   * Helper function to add a class, useful in many situations.
   *
   * @param string $classname
   *   The class to add.
   */
  public function addClass($classname) {
    if (!isset($this->html_params['class'])) {
      $this->html_params['class'] = $classname;
    }
    else {
      $this->html_params['class'] .= ' ' . $classname;
    }
  }

  /**
   * Helper function to set an id for the HTML tag.
   *
   * @param string $id
   *   The id of the tag.
   */
  public function setId($id) {
    $this->html_params['id'] = $id;
  }

  /**
   * Gets the id for the HTML tag.
   *
   * @param string $id
   *   The id of the tag.
   */
  public function getId() {
    return !empty($this->html_params['id']) ? $this->html_params['id'] : NULL;
  }

  /**
   * Set the body of the HTML tag.
   *
   * @param string $body
   */
  public function setHtmlBody($body) {
    $this->html_body = $body;
  }

  /**
   * Get the body of the HTML tag.
   *
   * @return string
   *   The HTML body of the tag.
   */
  public function getHtmlBody() {
    return $this->html_body;
  }
}
