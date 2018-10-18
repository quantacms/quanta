<?php
/**
 * Implements AMP_LINK qtag.
 *
 * Render a link to the amp version of the node.
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
function qtag_AMP_LINK($env, $target, &$attributes) {
  $node = NodeFactory::current($env);
  return '<link rel="amphtml" href="' . ($env->getBaseUrl() . '/amp/' . $node->getName()) . '">';

}


/**
 * Implements CANONICAL_LINK qtag.
 *
 * Render a link to the amp version of the node.
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
function qtag_CANONICAL_LINK($env, $target, &$attributes) {
  $node = NodeFactory::current($env);
  return '<link rel="canonical" href="' . ($env->getBaseUrl() . '/' . $node->getName()) . '">';

}


/**
 * Implements AMP_CAROUSEL qtag.
 *
 * Render amp version of the carousel.
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
function qtag_AMP_CAROUSEL($env, $target, &$attributes) {

  $module = isset($attributes['module']) ? $attributes['module'] : 'amp';
  if (empty($attributes['carousel-type'])) {
    $attributes['carousel-type'] = CAROUSEL_DIRS;
  }

  /** @var ListObject $list */
  switch ($attributes['carousel-type']) {

    case CAROUSEL_DIRS:
      $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'amp-carousel';
      $attributes = array('clean' => true, 'class' => 'amp-carousel') + $attributes;
      $list = new DirList($env, $target, $tpl, $attributes, $module);
      break;

    case CAROUSEL_FILES:
      $tpl = isset($attributes['tpl']) ? $attributes['tpl'] : 'amp-file-carousel';
      $attributes = array('clean' => true, 'class' => 'amp-carousel') + $attributes;
      $list = new FileList($env, $target, $tpl, $attributes, $module);
      break;

    default:
      break;
  }

  $carousel_attributes_defaults = array(
    // TODO: Extend to all options.
    'carousel_width' => '400',
    'carousel_height' => '225', // 400:225 = 16:9
    'carousel_layout' => 'responsive', // responsive / fixed-height
    'carousel_type' => 'slides',
    'carousel_autoplay' => 'false',
    'carousel_delay' => '3000', // used when autoplay is active
  );

  $carousel_attributes = array();
  foreach ($carousel_attributes_defaults as $k => $attr) {
    $carousel_attributes[$k] = (isset($attributes[$k]) ? $attributes[$k] : $attr);
  }

  $rand_class = rand(0, 99999999);
  $html = '<amp-carousel class="amp-' . $rand_class . '"';
  $html .= ' width="' . $carousel_attributes['carousel_width'] . '"';
  $html .= ' height="' . $carousel_attributes['carousel_height'] . '"';
  $html .= ' layout="' . $carousel_attributes['carousel_layout'] . '"';
  $html .= ' type="' . $carousel_attributes['carousel_type'] . '"';
  $html .= ($carousel_attributes['carousel_autoplay'] == 'true' ? ' autoplay' : '');
  $html .= ' delay="' . $carousel_attributes['carousel_delay'] . '"';
  $html .= '>' . $list->render() . '</amp-carousel>';

  return $html;

}



/**
 * Implements AMP_SIDEBAR qtag.
 *
 * Render amp version of the overlay menu.
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
function qtag_AMP_SIDEBAR($env, $target, &$attributes) {

  $sidebar_attributes_defaults = array(
    'sidebar_side' => 'right',
  );

  $sidebar_attributes = array();
  foreach ($sidebar_attributes_defaults as $k => $attr) {
    $sidebar_attributes[$k] = (isset($attributes[$k]) ? $attributes[$k] : $attr);
  }
  
  $rand_class = rand(0, 99999999);
  $html = '<amp-sidebar id="' . $attributes['sidebar_id'] . '"';
  $html .= ' class="amp-' . $rand_class . '"';
  $html .= ' layout="nodisplay"';
  $html .= ' side="' . $sidebar_attributes['sidebar_side'] . '"';
  $html .= '>';
  $html .= '<button class="amp-close-sidebar" on="tap:' . $attributes['sidebar_id'] . '.close" role="button" tabindex="0"><i class="fa fa-times"></i></button>';
  $html .= $target;
  $html .= '</amp-sidebar>';

  return $html;
}



/**
 * Implements AMP_SIDEBAR_BUTTON qtag.
 *
 * Render a button to handle an AMP sidebar.
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
function qtag_AMP_SIDEBAR_BUTTON($env, $target, &$attributes) {

  $html = '';

  // TODO: find a better way to filter AMP
  if (Amp::isActive($env)) {
    $sidebar_button_attributes_defaults = array(
      'sidebar_button_action' => 'toggle',
    );

    $sidebar_button_attributes = array();
    foreach ($sidebar_button_attributes_defaults as $k => $attr) {
      $sidebar_button_attributes[$k] = (isset($attributes[$k]) ? $attributes[$k] : $attr);
    }
    
    $rand_class = rand(0, 99999999);
    $html = '<button' . (empty($attributes['sidebar_button_id']) ? '' : ' id="' . $attributes['sidebar_button_id'] . '"');
    $html .= ' class="amp-' . $rand_class . '"';
    $html .= ' on="tap:' . $target . '.' . $sidebar_button_attributes['sidebar_button_action'] . '"';
    $html .= '>';
    $html .= $attributes['sidebar_button_html'];
    $html .= '</button>';
  }

  return $html;
}