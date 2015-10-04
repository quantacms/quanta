<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 16/05/15
 * Time: 16:45
 */

class Image extends File {

  public $width = 'auto';
  public $height = 'auto';
  public $css = array();
  public $class = array();

  public function loadAttributes($attributes) {
    foreach($attributes as $attname => $attribute) {
      $attr = explode('=', $attribute);

      if (preg_match_all('/[0-9]x[0-9]/', $attname, $matches)) {
        $size = explode('x', $attname);
        $this->width = $size[0];
        $this->height = $size[1];
        $this->css[] = 'width:' . $this->width . ((strpos($this->width, '%') > 0) ? '' : 'px');
        $this->css[] = 'height:' . $this->height . ((strpos($this->height, '%') > 0) ? '' : 'px');
      }

      else switch(strtolower($attr[0])) {
        case 'class':
          $this->class = explode(',', $attr[1]);
          break;
        case 'float':
          $this->css[] = 'float:' . $attr[1];
          break;
        default:
          break;
      }
    }
  }

  public function render() {
    $style = (count($this->css) > 0) ? 'style="' . implode(';', $this->css) . '" ' : '';
    $class = (count($this->class) > 0) ?  implode(' ', $this->class) : '';
    return '<img class="innerimg ' . $class . '" src="' . $this->path . '" ' . $style . " />";
  }
} 