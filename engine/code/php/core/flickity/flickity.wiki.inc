<?php
function wiki_CAROUSEL($attributes, $node, $string, $env) {
  $module = isset($attributes['module']) ? $attributes['module'] : 'flickity';
  $dirlist = new DirList($env, $node, 'flickity-carousel', array('sortby' => 'title', 'clean' => true, 'class' => 'flickity-carousel') + $attributes, $attributes['module']);
  return '<div class="flickity-carousel">' . $dirlist->render() . '</div>
  <script>$(".flickity-carousel").flickity({
    autoPlay: 5000,
    wrapAround: true,
  });
 </script>';
}

