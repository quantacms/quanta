<?php
namespace Quanta\Qtags;

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
class Qtag extends \Quanta\Common\DataContainer {
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
  protected $delimiters;

  /**
   * @var array $attributes
   *   The Qtag's attributes.
   */
  protected $attributes;

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
   * Constructs a Qtag object.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $action
   *   The action for which the access is being check.
   *
   * @param array $vars
   *   Mixed variables.
   */
  public function __construct(&$env, $tag, $attributes, $target) {
    // Sets basic Qtag's fields.
    $this->env = $env;
    $this->tag = $tag;
    $this->attributes = $attributes;
    $this->target = $target;

    // Highlight the Qtag - don't render it.
    if (!empty($this->attributes['highlight'])) {
      $this->html = $this->highlight();
    }
    // When the showtag attribute is used, don't render the tab but just display it.
    elseif (!empty($this->getData('showtag'))) {
      $this->html =  string_normalize(str_replace('|showtag', '', $this->getData('tag_full')));
    }
    // Start the rendering process of the Qtag.
    else {
      // Load attributes into the Qtag data.
      foreach ($this->attributes as $attribute_name => $attribute_value) {
        $this->setData($attribute_name, $attribute_value);
      }

      // A Qtag is accessible by default.
      $this->setAccess(TRUE);

      $vars = array(
        'qtag' => &$this,
      );

      // Other modules can hook into it in preload or load phases, to
      // change access rules or perform other interactions.
      $env->hook('qtag_preload', $vars);

      // Check that access is OK for the qtag.
      if (!$this->getAccess()) {
        $this->html = '';
      }
      else {
        $this->load();
        // Let other modules hook into the rendered qtag.
        $env->hook('qtag', $vars);
        // Add eventual suffix and prefix.
        if (!empty($this->getData('suffix')) && !empty($this->html)) {
          $this->html .= $this->getData('suffix');
        }
        if (!empty($this->getData('prefix')) && !empty($this->html)) {
          $this->html = $this->getData('prefix') . $this->html;
        }
        // Prevent replacement where no_qtags attribute present. Used for input forms etc.
        if (isset($this->attributes['no_qtags'])) {
          $this->html = string_normalize($this->html);
        }
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
    $highlighted .= '<span class="qtag-open-close">' . $this->getData('delimiters')[0] . '</span><span class="qtag-name">' . $this->tag . '</span>';
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
    $highlighted .= '<span class="qtag-open-close">' . $this->getData('delimiters')[1] . '</span>';

    $highlighted .= '</span>';
    return $highlighted;

  }

  public function setAccess($access) {
    $this->access = $access;
  }

  public function getAccess() {
    return $this->access;
  }

  public function setTarget($target) {
    $this->target = $target;
  }

  public function getTarget() {
    return $this->target;
  }

  /**
   * Using qtag_NAME functions is deprecated.
   * Will not work in next versions of Quanta.
   * Use classes in Qtag namespace instead...
   */
  public function load() {
    // The Qtag calling function.
    $func = '\\Quanta\\Common\\qtag_' . $this->tag;
    // Check if the requested qtag is defined anywhere.
    if ($this->tag == strtoupper($this->tag) &&  function_exists($func)) {
      // Run the qtag.
      $this->html = $func($this->env, $this->target, $this->attributes, $this);
    }
  }

  public function render() {
    return $this->html;
  }

  public function getAttributes() {
    return $this->attributes;
  }
}
