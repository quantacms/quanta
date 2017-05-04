<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 16/05/15
 * Time: 16:45
 */
define('IMAGE_RENDER_FULL', 'image_full');
class Image extends File {

  public $width = 'auto';
  public $height = 'auto';
  public $css = array();
  public $class = array();
  public $linkto = NULL;
  public $title = NULL;

  public function loadAttributes($attributes) {
    foreach($attributes as $attname => $attribute) {

      // Check forced size.
      if (preg_match_all('/[0-9]x[0-9]/', $attname, $matches)) {
        $size = explode('x', $attname);
        $this->width = $size[0];
        $this->height = $size[1];
        $this->css[] = 'width:' . $this->width . ((strpos($this->width, '%') > 0) ? '' : 'px');
        $this->css[] = 'height:' . $this->height . ((strpos($this->height, '%') > 0) ? '' : 'px');
      }

      else switch(strtolower($attname)) {
        case 'class':
          $this->class = explode(',', $attribute);
          break;
        case 'float':
          $this->css[] = 'float:' . $attribute;
          break;
				case 'link':
          $this->linkto = $attribute;
					break;
        case 'title':
					$this->setTitle($attribute);
					break;
				default:
          break;
      }
    }
    if (empty($this->getTitle())) {
      $this->setTitle($this->getFileName());
    }
  }

  public function setTitle($title) {
    $this->title = $title;
  }

  public function getTitle() {
    return $this->title;
  }
  public function render($mode = IMAGE_RENDER_FULL) {
    $style = (count($this->css) > 0) ? 'style="' . implode(';', $this->css) . '" ' : '';
    $class = (count($this->class) > 0) ?  implode(' ', $this->class) : '';
    
		$img = '<img alt="' . $this->getTitle() . '" class="innerimg ' . $class . '" src="' . $this->path . '" ' . $style . " />";
    if (!empty($this->link)) {
		  $img = '<a href="#">' . $img . '</a>';
		} 
		return $img;

	}
} 
