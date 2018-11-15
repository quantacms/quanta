<?php
/**
 * Implements hook_page_complete()
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_page_complete($env, $vars) {
  $html = transformCodeTags($env, $vars['page']->html);
  $html = transformCodeTags($env, $html, array('runlast' => TRUE));
  $vars['page']->html = $html;
}

/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function qtags_init($env, $vars) {
  if ($env->request[1] == 'qtag') {
    $env->setContext('qtag');
		print transformCodeTags($env, urldecode($env->request[2]));
    exit;
  }
}

/**
 * Highlights a qtag.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function _qtag_highlight($qtag, $attributes, $target, $delimiters) {
  print_r($delimiters);
  $highlighted = '<span class="qtag">';
  $highlighted .= '<span class="qtag-open-close">' . $delimiters[0] . '</span><span class="qtag-name">' . $qtag . '</span>';
  foreach ($attributes as $attribute_name => $attribute_value) {
    if (($attribute_value != "showtag") && ($attribute_value != "highlight")) {
      $highlighted .= '<span class="qtag-attribute-separator">|</span>';
      $highlighted .= '<span class="qtag-attribute">' . $attribute_value . '</span>';
    }
  }
  if (!empty($target)) {
    $highlighted .= '<span class="qtag-target-seprator">:</span>';
    $highlighted .= '<span class="qtag-target">' . $target . '</span>';
  }
  $highlighted .= '<span class="qtag-open-close">' . $delimiters[1] . '</span>';

  $highlighted .= '</span>';
  return $highlighted;

}

/**
 * Implements hook_load_includes().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function qtags_load_includes($env, $vars) {
  $module_path = $env->getModulePath('qtags');
  $env->addInclude($module_path . '/css/qtags.css');
}
