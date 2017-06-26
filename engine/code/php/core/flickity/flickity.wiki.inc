<?php
define('CAROUSEL_FILES', 'carousel_files');
define('CAROUSEL_DIRS', 'carousel_dirs');

function wiki_CAROUSEL($attributes, $node, $string, $env) {
  $module = isset($attributes['module']) ? $attributes['module'] : 'flickity';
  if (empty($attributes['carousel-type'])) {
    $attributes['carousel-type'] = CAROUSEL_DIRS;
  }

  switch ($attributes['carousel-type']) {

    case CAROUSEL_DIRS:
      $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'flickity-carousel';
      $list = new DirList($env, $node, $tpl, array('sortby' => 'title', 'clean' => true, 'class' => 'flickity-carousel') + $attributes, $module);
      break;

    case CAROUSEL_FILES:
      $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'flickity-file-carousel';
      $list = new FileList($env, $node, $tpl, array('sortby' => 'title', 'clean' => true, 'class' => 'flickity-carousel') + $attributes, $module);
      break;

    default:
      break;
  }

  $carousel_attributes = array(
    'autoPlay' => 5000,
    'wrapAround' => 'true',
  );

  $carousel_attributes_arr = array();
  foreach ($carousel_attributes as $k => $attr) {
    $carousel_attributes_arr[] = $k . ':' . (isset($attributes[$k]) ? $attributes[$k] : $attr);

  }

  $rand_class = rand(0, 99999999);
  $html = '<div class="flickity-carousel flickity-' . $rand_class . '">' . $list->render() . '</div>
  <script>
	  $(".flickity-' . $rand_class . '").flickity({
  ' . implode(',
			', $carousel_attributes_arr) . '
	});
 </script>';
  return $html;

}

function wiki_FILECAROUSEL($attributes, $node, $string, $env) {
  $attributes['carousel-type'] = CAROUSEL_FILES;
  return wiki_CAROUSEL($attributes, $node, $string, $env);
}