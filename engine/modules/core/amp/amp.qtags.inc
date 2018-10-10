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
function qtag_AMP_LINK($env, $target, $attributes) {
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
function qtag_CANONICAL_LINK($env, $target, $attributes) {
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
function qtag_AMP_CAROUSEL($env, $target, $attributes) {

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
    'width' => '400',
    'height' => '225', // 400:225 = 16:9
    'layout' => 'responsive', // responsive / fixed-height
    'type' => 'slides',
    'autoplay' => 'false',
    'delay' => '3000', // used when autoplay is active
  );

  $carousel_attributes = array();
  foreach ($carousel_attributes_defaults as $k => $attr) {
    $carousel_attributes[$k] = (isset($attributes[$k]) ? $attributes[$k] : $attr);
  }

  $rand_class = rand(0, 99999999);
  $html = '<amp-carousel class="amp-' . $rand_class . '"';
  $html .= ' width="' . $carousel_attributes['width'] . '"';
  $html .= ' height="' . $carousel_attributes['height'] . '"';
  $html .= ' layout="' . $carousel_attributes['layout'] . '"';
  $html .= ' type="' . $carousel_attributes['type'] . '"';
  $html .= ($carousel_attributes['autoplay'] == 'true' ? ' autoplay' : '');
  $html .= ' delay="' . $carousel_attributes['delay'] . '"';
  $html .= '>' . $list->render() . '</amp-carousel>';

  return $html;

}