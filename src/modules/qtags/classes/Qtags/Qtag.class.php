<?php

namespace Quanta\Qtags;

use Quanta\Common\Api;
use Quanta\Common\Environment;

/**
 * Class Qtag
 *
 * This class represents a Qtag, aka a markup taking input from
 * templates and metadata, in the format:
 *
 * [TYPE|attribute1=x|attribute2=y:target]
 *
 * QtagFactory will parse this data from text, and construct an instance of this class
 * passing the related variables.
 *
 */
class Qtag {
  /**
   * @var Environment $env
   *   The Environment.
   */
  protected $env;

  /**
   * @var array $tag
   *   The Qtag's Tag name.
   */
  protected $tag;

  /**
   * @var string $delimiters
   *   The Qtag's delimiters.
   */
  public $delimiters;

  /**
   * @var array $attributes
   *   The Qtag's attributes.
   */
  public $attributes;

  /**
   * @var mixed $target
   *   The Qtag's Target.
   */
  protected $target;

  /**
   * @var string $html
   *   The Rendered HTML of the Qtag.
   */
  public $html;

  /**
   * @var bool $access
   *   Accessibility to the qtag for current user.
   */
  private $access;

  /**
   * True when the Qtag has already rendered to HTML.
   * @var bool
   */
  protected $rendered = FALSE;

  /**
   * Constructs a Qtag object.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param array $attributes
   *   The Qtag's attributes
   *
   * @param string $target
   *   The Qtag's target.
   */
  public function __construct(&$env, $attributes, $target, $tag = NULL) {
    // Sets basic Qtag's fields.
    $this->env = $env;
    $this->attributes = $attributes;
    $this->target = $target;
    $this->tag = $tag;

    // A Qtag is accessible by default.
    $this->setAccess(TRUE);

    $vars = array(
      'qtag' => &$this,
    );

    // Other modules can hook into it in preload or load phases, to
    // change access rules or perform other interactions.
    $this->env->hook('qtag_preload', $vars);

    // Default empty string value for the Qtag.
    $this->html = '';

    // Check that current user has access to the qtag. Empty the qtag if it's not.
    if ($this->getAccess() && !$this->rendered) {
      $this->html = $this->render();
      $this->rendered = TRUE;
      // Let other modules hook into the rendered qtag.
      $this->env->hook('qtag', $vars);
      // Add eventual suffix and prefix.
      if (!empty($this->attributes['suffix']) && !empty($this->html)) {
        $this->html .= $this->attributes['suffix'];
      }
      if (!empty($this->attributes['prefix']) && !empty($this->html)) {
        $this->html = $this->attributes['prefix'] . $this->html;
      }
      if (!empty($this->attributes['trim']) && is_numeric($this->attributes['trim']) && !empty($this->html)) {
        $this->html = substr($this->html, 0, $this->attributes['trim']);
        if (!empty($this->attributes['trim_text'])) {
          $this->html .= $this->attributes['trim_text'];
        }
      }
      // Prevent replacement where no_qtags attribute present. Used for input forms etc.
      if (isset($this->attributes['no_qtags'])) {
        $this->html = Api::string_normalize($this->html);
      }
    }
  }


  /**
   * Convert the Qtag in an highlighted version of the Qtag string.
   *
   * @return string
   *   The highlighted Qtag string.
   */
  public function highlight() {

    // TODO: those characters seem ignored by htmlentities(). Converting manually for now, check out why.
    $open_tag = \Quanta\Common\Api::string_normalize($this->delimiters[0]);
    $close_tag = \Quanta\Common\Api::string_normalize($this->delimiters[1]);
    $highlighted = '<span class="qtag">';
    $highlighted .= '<span class="qtag-open-close">' . $open_tag . '</span><span class="qtag-name">' . $this->tag . '</span>';
    foreach ($this->attributes as $attribute_name => $attribute_value) {
      if (($attribute_value != "showtag") && ($attribute_value != "highlight")) {
        $attribute_full = $attribute_name . (empty($attribute_value) ? '' : ('=' . $attribute_value));
        $highlighted .= '<span class="qtag-attribute-separator">&#124;</span>';
        $highlighted .= '<span class="qtag-attribute">' . $attribute_full . '</span>';
      }
    }
    if (!empty($this->getTarget())) {
      $highlighted .= '<span class="qtag-target-separator">&colon;</span>';
      $highlighted .= '<span class="qtag-target">' . $this->getTarget() . '</span>';
    }

    $highlighted .= '<span class="qtag-open-close">' . $close_tag . '</span>';

    $highlighted .= '</span>';
    return $highlighted;

  }

  /**
   * Sets accessibility to the Qtag for the current user.
   *
   * @param bool $access
   *   If true, current user can see the Qtag.
   */
  public function setAccess($access) {
    $this->access = $access;
  }

  /**
   * Returns accessibility to the Qtag for the current user.
   *
   * @return bool
   *   If true, current user can see the Qtag.
   */
  public function getAccess() {
    return $this->access;
  }

  /**
   * Sets the target of the Qtag.
   *
   * @param string $target
   *   The target of the Qtag.
   */
  public function setTarget($target) {
    $this->target = $target;
  }

  /**
   * Returns the target of the Qtag.
   *
   * @return string
   *   The target of the Qtag.
   */
  public function getTarget() {
    return $this->target;
  }

  /**
   * Renders the Qtag.
   */
  public function render() {
    return NULL;
  }

  /**
   * Returns a Qtag's attributes.
   *
   * @return array
   *   All the Qtag's attributes.
   */
  public function getAttributes() {
    return $this->attributes;
  }

  /**
   * Returns a Qtag's specific attribute.
   *
   * @return mixed
   *   The Qtag's attribute value.
   */
  public function getAttribute($attr_name, $empty_value = NULL) {

    return !empty($this->attributes[$attr_name]) ? $this->attributes[$attr_name] : $empty_value;
  }

  /**
   * Checks if a Qtag has a specific attribute.
   *
   * @return bool
   *   True if the attribute is set on the Qtag.
   */
  public function hasAttribute($attr_name) {
    return isset($this->attributes[$attr_name]);
  }

  /**
   * Sets a Qtag's specific attribute.
   *
   * @param $attr_name
   *   The Attribute name.
   *
   * @param $value
   *   The attribute's value.
   */
  public function setAttribute($attr_name, $value) {
    $this->attributes[$attr_name] = $value;
  }

  /**
   * Returns a rendered Qtag's HTML code.
   *
   * @return string
   *   The rendered Qtag's HTML.
   */
  public function getHtml() {
    return $this->html;
  }

  /**
   * Make a Qtag printable (print its rendered html).
   */
  public function __toString() {
    return $this->html;
  }
}
