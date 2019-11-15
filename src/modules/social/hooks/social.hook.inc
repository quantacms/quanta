<?php
namespace Quanta\Common;

/**
 * Implements hook_metadata().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function social_metadata(Environment $env, array $vars){
  $node = NodeFactory::current($env);

  // Data for Twitter card.
  if (!empty($node->getTeaser())) {
    // Twitter description.
    $vars['metadata']['twitter:description'] = array(
      'content' => Api::filter_xss($node->getTeaser()),
    );
    // Twitter card.
    $vars['metadata']['twitter:card'] = array(
      'content' => Api::filter_xss($node->getTeaser()),
    );
  }
  // Twitter thumbnail.
  if (!empty($node->getThumbnail())) {
    $vars['metadata']['twitter:image'] = array(
      'content' => $env->getData('request_url') . $node->getThumbnail(),
    );
  }
  // Twitter title.
  $vars['metadata']['twitter:title'] = array(
    'content' => Api::filter_xss($node->getTitle()),
  );
}
