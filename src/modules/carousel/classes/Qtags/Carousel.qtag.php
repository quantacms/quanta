<?php
namespace Quanta\Qtags;


/**
 * Create a visual carousel based on a node list.
 * We are using the flickity plugin for rendering the carousel.
 */
class Carousel extends HtmlTag {
  const CAROUSEL_FILES = 'carousel_files';
  const CAROUSEL_DIRS = 'carousel_dirs';
  public $carousel_type = self::CAROUSEL_DIRS;
  public $carousel_plugin = 'flickity';

  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $flickity_counter = $this->env->getData('flickity_counter');
    if (empty($flickity_counter)) {
      $flickity_counter = 0;
    }
    $flickity_counter++;
		$this->env->setData('flickity_counter', $flickity_counter);

		$node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    /** @var \Quanta\Common\Page $page */
    $page = $this->env->getData('page');

    // TODO: create a class for the carousel.
    $module = isset($this->attributes['module']) ? $this->attributes['module'] : 'carousel';
    if (isset($this->attributes['carousel-type'])) {
      $this->carousel_type = $this->attributes['carousel-type'];
    }

    /** @var ListObject $list */
    switch ($this->carousel_type) {

      case self::CAROUSEL_DIRS:
        $tpl = isset($this->attributes['tpl']) ? $this->attributes['tpl'] : 'flickity-carousel';
        $list = new \Quanta\Common\DirList($this->env, $this->getTarget(), $tpl, array('clean' => true, 'class' => 'flickity-carousel') + $this->attributes, $module);
        break;

      case self::CAROUSEL_FILES:
        $tpl = isset($this->attributes['tpl']) ? $this->attributes['tpl'] : 'flickity-file-carousel';
        $list = new \Quanta\Common\FileList($this->env, $this->getTarget(), $tpl, array('clean' => true, 'class' => 'flickity-carousel') + $this->attributes, $module);
        break;

      default:
        break;
    }

    // Create an array with all carousel attributes.
    $carousel_attributes = array(
      'prevNextButtons' => 'true',
      'pageDots' => 'true',
      'autoPlay' => 5000, // Slide duration in milliseconds.
      'wrapAround' => 'true', // Never ending slides.
      'contain' => 'false', // Fills start and end of carousel with cells. Has no effect if wrapAround: true.
      'freeScroll' => 'false', // Free slides scroll without aligning them to an end position.
      'pauseAutoPlayOnHover' => 'true',
      'adaptiveHeight' => 'false', // Dinamically adapt to current slide's height.
      'imagesLoaded' => 'false', // If true, re-positions cells once their images have loaded.
      'initialIndex' => 0, // First slide.
      'accessibility' => 'true', // Enable keyboard navigation.
      'setGallerySize' => 'true', // Carousel's height by the tallest cell.
      'resize' => 'true', // Resize carousel when windows is resized
      'cellAlign' => '"center"', // Cell horizontal alignment within the carousel.
      'draggable' => 'true', // Draggable carousel.
    );

    // Cycle the attributes.
    $carousel_attributes_arr = array();

    foreach ($carousel_attributes as $k => $attr) {
      $carousel_attributes_arr[] = $k . ':' . (isset($this->attributes[$k]) ? $this->attributes[$k] : $attr);
    }

    // We can skip including css and js when in an AMP context.
    if (!\Quanta\Common\Amp::isActive($this->env)) {
      $module_path = $this->env->getModulePath('carousel');

      // Setup Flickity look & feel by using predefined theme (whitestripe).
      $flickity_theme = isset($this->attributes['flickity_theme']) ? $this->attributes['flickity_theme'] : 'whitestripe';

      if (is_file($module_path . '/assets/css/themes/' . $flickity_theme . '.css')) {
        $page->addCSS($module_path . '/assets/css/themes/' . $flickity_theme . '.css');
      }
      // TODO: support relative path to JS.


      switch ($this->carousel_plugin) {

        case 'zoom':
          $img_attr = array('class' => "xzoom");
          $main_img = new Img($this->env, $img_attr, $node->getThumbnail());
          $main_img->setAttribute('node', $node->getName());

          $this->setAttribute('prefix', '<img id="main_image" class="xzoom" src="' . $node->getThumbnail() . '" xoriginal="' . $node->getThumbnail() . '" />');
          $page->addCSS($module_path . '/assets/css/xzoom.css');
          $page->addJS('/src/modules/carousel/assets/js/xzoom.js?test=' . rand(1, 100000), 'file');
          $page->addJS('$(".xzoom, .xzoom-gallery").xzoom({tint: "#333", Xoffset: 15});', 'inline');
          break;

        case 'flickity':

        default:
        $carousel_id = 'flickity-' . $flickity_counter;
        $this->html_params['id'] = $carousel_id;

        $this->html_params['class'] = 'flickity-carousel ' . $flickity_theme;

          $page->addCSS($module_path . '/assets/css/flickity.min.css');
          $page->addCSS($module_path . '/assets/css/flickity-quanta.css');
          $page->addJS('/src/modules/carousel/assets/js/flickity.pkgd.min.js', 'file');
          $page->addJS('window.addEventListener("DOMContentLoaded", function() {
          $("#' . $carousel_id . '").flickity({' . implode(',', $carousel_attributes_arr) . '});
          });', 'inline');
        break;
      }
      $this->html_body = $list->render();

    }
    return parent::render();
  }
}
