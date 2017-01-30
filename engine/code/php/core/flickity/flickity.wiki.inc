<?php
function wiki_CAROUSEL($attributes, $node, $string, $env) {
  $module = isset($attributes['module']) ? $attributes['module'] : 'flickity';
  $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'flickity-carousel';
  $dirlist = new DirList($env, $node, $tpl, array('sortby' => 'title', 'clean' => true, 'class' => 'flickity-carousel') + $attributes, $module);
  
	$carousel_attributes = array(
			'autoPlay' => 5000,
			'wrapAround' => 'true',
			);

	$carousel_attributes_arr = array();
	foreach ($carousel_attributes as $k => $attr) {
	  $carousel_attributes_arr[] = $k . ':' . (isset($attributes[$k]) ? $attributes[$k] : $attr);
	
	}
	
	$rand_class = rand(0, 99999999);
	$html = '<div class="flickity-carousel flickity-' . $rand_class . '">' . $dirlist->render() . '</div>
  <script>
	  $(".flickity-' . $rand_class . '").flickity({
  ' . implode(',
			', $carousel_attributes_arr) .	'
	});
 </script>';
 return $html;

}

