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
abstract class Qtag {
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
   * @param string $attributes
   *   The Qtag's attributes
   *
   * @param array $target
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

    // Check that current user has access to the qtag. Empty the qtag if it's not.
    if (!$this->getAccess()) {
      $this->html = '';
    }
    elseif (!$this->rendered) {

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
    $highlighted = '<span class="qtag">';
    $highlighted .= '<span class="qtag-open-close">' . $this->delimiters[0] . '</span><span class="qtag-name">' . $this->tag . '</span>';
    foreach ($this->attributes as $attribute_name => $attribute_value) {
      if (($attribute_value != "showtag") && ($attribute_value != "highlight")) {
        $highlighted .= '<span class="qtag-attribute-separator">|</span>';
        $highlighted .= '<span class="qtag-attribute">' . $attribute_value . '</span>';
      }
    }
    if (!empty($target)) {
      $highlighted .= '<span class="qtag-target-seprator">:</span>';
      $highlighted .= '<span class="qtag-target">' . $target . '</span>';
    }
    $highlighted .= '<span class="qtag-open-close">' . $this->delimiters[1] . '</span>';

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
  abstract public function render();

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
  public function getAttribute($attr_name) {
    return !empty($this->attributes[$attr_name]) ? $this->attributes[$attr_name] : NULL;
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
}
