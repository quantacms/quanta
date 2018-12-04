<?php
namespace Quanta\Qtags;
use Quanta\Common\DirList;
use Quanta\Common\Page;

define('CAROUSEL_FILES', 'carousel_files');
define('CAROUSEL_DIRS', 'carousel_dirs');
/**
 * Create a visual carousel based on a node list.
 * We are using the flickity plugin for rendering the carousel.
 */
class Carousel extends Qtag {
  public $carousel_type = CAROUSEL_DIRS;
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    /** @var Page $page */
    $page = $this->env->getData('page');
    $module_path = $this->env->getModulePath('flickity');
    // TODO: support relative path to JS.
    $page->addJS('/src/modules/flickity/js/flickity.pkgd.min.js');
    $page->addCSS($module_path . '/assets/css/flickity.min.css');
    $page->addCSS($module_path . '/assets/css/flickity-quanta.css');

    // Setup Flickity look & feel by using predefined theme (whitestripe).
    $flickity_theme = isset($this->attributes['flickity_theme']) ? $this->attributes['flickity_theme'] : 'whitestripe';

    if (is_file($module_path . '/assets/css/themes/' . $flickity_theme . '.css')) {
      $page->addCSS($module_path . '/assets/css/themes/' . $flickity_theme . '.css');
    }

    // TODO: create a class for the carousel.
    $module = isset($this->attributes['module']) ? $this->attributes['module'] : 'flickity';
    if (isset($this->attributes['carousel-type'])) {
      $this->carousel_type = $this->attributes['carousel-type'];
    }

    /** @var ListObject $list */
    switch ($this->carousel_type) {

      case CAROUSEL_DIRS:
        $tpl = isset($this->attributes['tpl']) ? $this->attributes['tpl'] : 'flickity-carousel';
        $list = new DirList($this->env, $this->getTarget(), $tpl, array('clean' => true, 'class' => 'flickity-carousel') + $this->attributes, $module);
        break;

      case CAROUSEL_FILES:
        $tpl = isset($this->attributes['tpl']) ? $this->attributes['tpl'] : 'flickity-file-carousel';
        $list = new FileList($this->env, $this->getTarget(), $tpl, array('clean' => true, 'class' => 'flickity-carousel') + $this->attributes, $module);
        break;

      default:
        break;
    }

    $carousel_attributes = array(
      // TODO: Define all Flickity attributes. See https://flickity.metafizzy.co/options.html
      'prevNextButtons' => 'true',
      'pageDots' => 'true',
      'autoPlay' => 5000, //Slide duration in milliseconds
      'wrapAround' => 'true', //Never ending slides
      'contain' => 'false', //Fills start and end of carousel with cells (no extra-spaces). Has no effect if wrapAround: true.
      'freeScroll' => 'false', //Free slides scroll without aligning them to an end position
      'pauseAutoPlayOnHover' => 'true',
      'adaptiveHeight' => 'false', //Dinamically adapt to current slide's height
      'imagesLoaded' => 'false', //if true, re-positions cells once their images have loaded
      'initialIndex' => 0, //First slide
      'accessibility' => 'true', //Enable keyboard navigation
      'setGallerySize' => 'true', //Carousel's height by the tallest cell
      'resize' => 'true', //Resize carousel when windows is resized
      'cellAlign' => '"center"', //Cell horizontal alignment within the carousel
      'draggable' => 'true', //Draggable carousel
    );

    $carousel_attributes_arr = array();
    foreach ($carousel_attributes as $k => $attr) {
      $carousel_attributes_arr[] = $k . ':' . (isset($this->attributes[$k]) ? $this->attributes[$k] : $attr);
    }

    $rand_class = rand(0, 99999999);
    $html = '<div class="flickity-carousel ' . $flickity_theme . ' flickity-' . $rand_class . '">' . $list->render() . '</div>';
    $html .=
      '<script>
    window.addEventListener("DOMContentLoaded", function(){
      $(".flickity-' . $rand_class . '").flickity({' . implode(',', $carousel_attributes_arr) . '});
    });
  </script>';
    return $html;
  }
}
