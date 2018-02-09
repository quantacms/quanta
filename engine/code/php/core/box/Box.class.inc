<?php
  require_once('Box.class.inc');
define('BOX_NON_EDITABLE', 'box_non_editable');
define('BOX_FLAT', 'box_flat');

date_default_timezone_set('UTC');
/**
 * Class Box
 * This class creates a wrapper div for an inserted tag.
 * It accepts width, height, margins and borders for automatic placing.
 * It is triggered when using the [TAG|box... attribute.
 */
class Box extends DataContainer {
  private $html;
  private $w;
  private $h;
  private $margin;
  private $padding;
  private $border;
  private $name;

  /**
   * Box constructor.
   * @param $env
   * @param null $w
   * @param null $h
   * @param null $attributes
   */
  public function __construct(&$env, $w = NULL, $h = NULL, $attributes = NULL) {
    // Class for the div
    $this->setData('class', array('box'));
    // Style for the div
    $this->setData('style', array());
    // Style for the .inner div
    $this->setData('inner_style', array());

    $this->setWidth($w);
    $this->setHeight($h);

    // TODO: make sure name is unique.
    $name = isset($attributes['name']) ? $attributes['name'] : 'box-noname-' . rand(10000000, 99999999);

    $this->setName($name);
    $this->addData('class', array('box-' . $name));


    $id = isset($attributes['id']) ? $attributes['id'] : $this->getName();
    // Set ID.
    $this->setData('id', $id);

    // Set custom classes.
    if (isset($attributes['class'])) {
      $this->addData('class', array($attributes['class']));
    }

    // Set custom classes.
    if (isset($attributes['title'])) {
      $this->addData('title', array($attributes['title']));
    }

    // Clear non-floating box.
    if (!empty($attributes['clear'])) {
      $this->addData('class', array('clear-box'));
    }

    // Set floating direction. Defaults to left.
    $this->setData('float', empty($attributes['float']) ? 'left' : $attributes['float']);

    // Centered box.
    if (!empty($attributes['centered'])) {
      $this->addData('class', array('centered-box'));
    }

    // Centered box.
    if (!empty($attributes['target'])) {
      $this->addData('class', array($attributes['target']));
    }

    // TODO: move out of constructor ?

    if (isset($attributes['margin'])) {
      $this->setMargin($attributes['margin']);
    }

    if (isset($attributes['padding'])) {
      $this->setPadding($attributes['padding']);
    }

    if (isset($attributes['border'])) {
      $this->setBorder($attributes['border']);
    }

    // Centered box.
    if (!($this->isFlat())) {
      $this->addData('class', array('autoresize', 'float-' . $this->getFloatDirection()));
    }
  }

  /**
   * @param $html
   */
  public function setHtml($html) {
    $this->html = $html;
  }

  /**
   * @return mixed
   */
  public function getHtml() {
    return $this->html;
  }

  /**
   * @return mixed
   */
  public function getFloatDirection() {
    return $this->getData('float');
  }

  /**
   * @return mixed|null
   */
  public function getTitle() {
    return $this->getData('title');
  }

  /**
   * @param $margin
   */
  public function setMargin($margin) {
    $this->margin = $margin;
    $this->addData('inner_style', array('margin:' . $margin . 'px'));

  }

  /**
   * @return mixed
   */
  public function getMargin() {
    return $this->margin;
  }

  /**
   * @param $padding
   */
  public function setPadding($padding) {
    $this->padding = $padding;
    $this->addData('inner_style', array('padding:' . $padding . 'px'));
  }

  /**
   * @return mixed
   */
  public function getPadding() {
    return $this->padding;
  }

  /**
   * @param $border
   */
  public function setBorder($border) {
    $this->border = $border;
    $this->addData('inner_style', array('border:' . $border . 'px solid'));

  }

  /**
   * @return mixed
   */
  public function getBorder() {
    return $this->border;
  }

  /**
   * @param $h
   */
  public function setHeight($h) {
    $this->h = $h;
    if ($h != NULL) {
    $this->addData('class', array('h-' . $h));
    }
  }

  /**
   * @return mixed
   */
  public function getHeight() {
    return $this->h;
  }

  /**
   * @param $w
   */
  public function setWidth($w) {
    $this->w = $w;
    if ($w != NULL) {
    $this->addData('class', array('w-' . $w));
    if (is_numeric($w) && $w > 0) {
      $this->addData('style', array('width:' . $w . '%'));
    }
    }
  }

  /**
   * @return mixed
   */
  public function getWidth() {
    return $this->w;
  }

  /**
   * @param $name
   */
  public function setName($name) {
    $this->name = $name;
  }

  /**
   * @return mixed
   */
  public function getName() {
    return $this->name;
  }


  /**
   * @return bool
   */
  public function isFlat() {
    return (($this->getWidth() == NULL) && ($this->getHeight() == NULL));
  }

  /**
   * @return string
   */
  public function render() {

    // Don't wrap empty content.
    if (empty($this->getHtml())) {
      return '';
    }
    else {
      $box_content = $this->getHtml();
      if (!empty($this->getData('title'))) {
        $box_content = '<h3>' . @array_pop($this->getData('title')) . '</h3>' . $box_content;
      }

      if (!$this->isFlat()) {
        $box_content = '<div class="inner" style="' . implode(';', $this->getData('inner_style')) . '" >' . $box_content . '</div>';
      }
      $box_content = '<div id="' . $this->getData('id') . '" class="' . implode(' ', $this->getData('class')) . '" style="' . implode(';', $this->getData('style')) . '">' . $box_content . '</div>';
      return $box_content;
    }

}
}