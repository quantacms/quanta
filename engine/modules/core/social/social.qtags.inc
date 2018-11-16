<?php
/**
 *
 * @category Qtags
 * @file jumper.qtags.inc
 * @package Quanta
 * @author Aldo Tripiciano <aldo.tripiciano@gmail.com>
 * @license http://www.quantacms.com Open
 * @link http://www.quantacms.com
 *
 * All qtags implemented by the jumper module.
 */

/**
 * Implements qtag SHARE_BUTTONS.
 *
 * Create share buttons for a certain node.
 *
 * @param Environment $env
 *   The Environment.
 * @param string $target
 *   The qtag's target.
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_SHARE_BUTTONS($env, $target, &$attributes) {
  // Which folder to use.
  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $share_buttons = '';
  $link_attr = array('protocol' => 'https');

  // Pick a set of icons.
  $set = isset($attributes['set']) ? $attributes['set'] : 'default';
  $url = qtag_URL($env, $target, $attributes);
  // Facebook.

  $socials = array(
    'facebook' => array(
      'url' => 'www.facebook.com/sharer.php?u=' . $url,
      'title' => t('Share on Facebook'),
    ),
    'google' => array(
      'url' => 'plus.google.com/share?url=' . $url,
      'title' => t('Share on Google+'),
    ),
    'linkedin' => array(
      'url' => 'www.linkedin.com/shareArticle?mini=true&amp;url=' . $url,
      'title' => t('Share on LinkedIn'),
    ),
    'twitter' => array(
      'url' => 'twitter.com/share?url=' . $url . '&amp;text=' . $node->getTitle() . '%20-%20' . $url,
      'title' => t('Share on Twitter'),
    ),
    'pinterest' => array(
      'url' => 'pinterest.com/pin/create/button/?url=' . $url,
      'title' => t('Pin it!'),
    ),
  );

  // Render the social share buttons.
  foreach ($socials as $social_item => $social_item_data) {
    $img =  '<img src="engine/modules/core/social/set/' . $set . '/' . $social_item . '.png" />';
    $link_attr['title'] = $img;
    $link_attr['tooltip'] = $social_item_data['title'];
    $share_buttons .= qtag_LINK($env, $social_item_data['url'],$link_attr);
  }

  return $share_buttons;
}
