<?php
/**
 * Set credits to zero if points variable has not been registered.
 * @param $vars
 */

/**
 * @param $vars
 */
function namearcher_user_save($vars) {
  $points = $vars['user']->getData('points');
  if (!$points) {
    $vars['user']->setData('points', array(
      'energy' => array(
        'current' => 1000,
        'max' => 1000,
      ),
        'experience' => array(
          'current' => 0,
          'max' => 1000000000,
        ),
      )
    );
  }
}

/**
 * @param $vars
 */
function namearcher_shadow_namearcher_message($vars) {
  $text = file_get_contents('contrib/namearcher/tpl/' . $vars['shadow']->getData('message') . '.inc');
  $vars['shadow']->addTab('Info', $text, 1);
}

/**
 * @param $vars
 */
function namearcher_shadow_domain_collections($vars) {
  $collections = DomainSearch::getCollections($vars['shadow']->env);
  $output = '<div id="domain-collections">';
  foreach ($collections as $collection) {
    $output .= '<div class="domain-collection"><a href="#" rel="' . $collection['name'] . '">' . $collection['name'] . ' (' . $collection['number'] . ')</a></div>';
  }
  $output .= '</div>';
  $text = file_get_contents('contrib/namearcher/tpl/collections.inc');
  $vars['shadow']->addTab('Collections', $output . $text, 1);
}

function namearcher_init($vars) {
  $vars['env']->addInclude('engine/code/php/contrib/namearcher/js/namearcher.js');
}