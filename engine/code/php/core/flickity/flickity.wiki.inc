<?php
function wiki_CAROUSEL($attributes, $node, $string, $env) {
  $module = isset($attributes['module']) ? $attributes['module'] : 'flickity';
  $dirlist = new DirList($env, $node, 'flickity-carousel', array('sortby' => 'title', 'clean' => true, 'class' => 'flickity-carousel') + $attributes, $module);
  
	$carousel_attributes = array(
			'autoPlay' => 5000,
			'wrapAround' => 'true',
			);

	$carousel_attributes_arr = array();
	foreach ($carousel_attributes as $k => $attr) {
	  $carousel_attributes_arr[] = $k . ':' . (isset($attributes[$k]) ? $attributes[$k] : $attr);
	
	}
	$html = '<div class="flickity-carousel">' . $dirlist->render() . '</div>
  <script>
	  $(".flickity-carousel").flickity({
  ' . implode(',
			', $carousel_attributes_arr) .	'
	});
 </script>';
 return $html;

}

