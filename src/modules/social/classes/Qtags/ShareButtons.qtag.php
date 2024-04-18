<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;

/**
 * Create social sharing buttons for a node.
 */
class ShareButtons extends HtmlTag {
  protected $share_text = NULL;
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
// Which folder to use.
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());

    if (!empty($this->getAttribute('share_text'))) {
      $this->share_text = $this->getAttribute('share_text');
    }

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
      // Create the link to the image of the social network
      $social_img = $this->env->getModulePath('social') . '/assets/set/' . $set . '/' . $social_item . '.png';
      $img_attributes = array();
      $img = new \Quanta\Qtags\Img($this->env, $img_attributes, $social_img);
      $img->addClass('share-img');
      $img->setAttribute('alt', $social_item_data['title']);
      $link_attr['title'] = $img->render();
      $link_attr['tooltip'] = $social_item_data['title'];
      $link = new Link($this->env, $link_attr, $social_item_data['url']);
      $this->html_body .= $link->render();
    }
    // Optional "share" text.
    if (!empty($this->share_text)) {
      $this->html_body = '<h3>' . t($this->share_text) . '</h3>' . $this->html_body;
    }

    return parent::render();
  }
}
