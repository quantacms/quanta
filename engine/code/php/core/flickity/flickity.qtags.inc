<?php
define('CAROUSEL_FILES', 'carousel_files');
define('CAROUSEL_DIRS', 'carousel_dirs');

/**
 * Implements CAROUSEL qtag.
 *
 * Create a visual carousel based on a node list.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_CAROUSEL($env, $target, $attributes) {
  /** @var Page $page */
  $page = $env->getData('page');
  $module_path = $env->getModule('flickity')['path'];
  $page->addCSS($module_path . '/js/flickity.pkgd.min.js');
  $page->addCSS($module_path . '/css/flickity.min.css');
  $page->addCSS($module_path . '/css/flickity-quanta.css');

  // TODO: create a class for the carousel.
  $module = isset($attributes['module']) ? $attributes['module'] : 'flickity';
  if (empty($attributes['carousel-type'])) {
    $attributes['carousel-type'] = CAROUSEL_DIRS;
  }

  /** @var ListObject $list */
  switch ($attributes['carousel-type']) {

    case CAROUSEL_DIRS:
      $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'flickity-carousel';
      $list = new DirList($env, $target, $tpl, array('clean' => true, 'class' => 'flickity-carousel') + $attributes, $module);
      break;

    case CAROUSEL_FILES:
      $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'flickity-file-carousel';
      $list = new FileList($env, $target, $tpl, array('clean' => true, 'class' => 'flickity-carousel') + $attributes, $module);
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
    $carousel_attributes_arr[] = $k . ':' . (isset($attributes[$k]) ? $attributes[$k] : $attr);
  }

  $rand_class = rand(0, 99999999);
  $html = '<div class="flickity-carousel flickity-' . $rand_class . '">' . $list->render() . '</div>';
  $html .=
  '<script>
    window.addEventListener("DOMContentLoaded", function(){
      $(".flickity-' . $rand_class . '").flickity({' . implode(',', $carousel_attributes_arr) . '});
    });
  </script>';
  return $html;
}

/**
 * Implements FILECAROUSEL qtag.
 *
 * Create a visual carousel based on a file list.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_FILECAROUSEL($env, $target, $attributes) {
  $attributes['carousel-type'] = CAROUSEL_FILES;
  return qtag_CAROUSEL($env, $target, $attributes);
}
