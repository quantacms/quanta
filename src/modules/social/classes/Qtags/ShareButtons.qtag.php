<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Create social sharing buttons for a node.
 */
class ShareButtons extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
// Which folder to use.
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    
    $share_buttons = '';
    $link_attr = array('protocol' => 'https');

    // Pick a set of icons.
    $set = isset($this->attributes['set']) ? $this->attributes['set'] : 'default';
    $urlobj = new Url($this->env, $this->getTarget(), $this->attributes);
    $url = $urlobj->render();
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
      $img =  '<img src="src/modules/core/social/assets/set/' . $set . '/' . $social_item . '.png" />';
      $link_attr['title'] = $img;
      $link_attr['tooltip'] = $social_item_data['title'];
      $link = new Link($this->env, $social_item_data['url'], $link_attr);
      $share_buttons .= $link->render();
    }

    return $share_buttons;
  }
}
