<?php

namespace Quanta\Qtags;

use Quanta\Common\NodeFactory;
use Quanta\Common\Api;

/**
 * Creates a link to another node in the system.
 */
class Link extends HtmlTag {
  /**
   * @var string $link_title
   *   The Link Title (what appears in the <a title=... attribute).
   */
  public $link_title;
  /**
   * @var string $link_class
   *   The Link Classes (what appears in the <a class=... attribute).
   */
  public $link_class = array();
  /**
   * @var string $link_id
   *   The Link Id (what appears in the <a id=... attribute).
   */
  public $link_id = '';
  /**
   * @var string $link_target
   *   The Link Target (what appears in the <a target=... attribute).
   */
  public $link_target = '_self';
  /**
   * @var array $link_data
   *   A key-value array of data attributes (es. <a data-rel="myblock"...).
   */
  public $link_data = array();
  /**
   * @var string $destination
   *   The link's destination.
   */
  public $destination = NULL;
  /**
   * @var string $external
   *   If true, will create a simple link to the target/destination.
   */
  public $external = FALSE;
  /**
   * @var array $querystring
   *   A key-value array of querystring parameters.
   */
  public $querystring = array();
  /**
   * @var array $protocol
   *   For external links, the protocol to use (to not include http://... in the qtag's target).
   */
  public $protocol = NULL;

  /**
   * The HTML tag.
   * @var string
   */
  protected $html_tag = 'a';

  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $querystring = array();
    $this->link_id = !empty($this->attributes['link_id']) ? $this->attributes['link_id'] : '';

    $this->link_class[] = 'link';
    if (empty($this->destination)) {
      $this->destination = '#';
      // Check if the target is a node or an external link.
      // External link with a protocol (ex. http://).
      if (!empty($this->attributes['protocol'])) {
        $this->protocol = $this->attributes['protocol'];
        $this->link_class[] = 'link-ext';
        $this->attributes['rel'] = NULL;
        // TODO: Find a better solution for &colon; instead of : to avoid conflicts in multiple qtags.
        $this->destination = $this->protocol . '&colon;//' . $this->getTarget();
      }
      // Link to a specific resource (i.e. to an image).
      elseif (!empty($this->attributes['external'])) {
        $this->external = TRUE;
        $this->destination = $this->getTarget();
      }
      // Link to a node.
      elseif (!empty($this->getTarget())) {
        // Load the node.
        $node = NodeFactory::load($this->env, $this->getTarget());
        $current = NodeFactory::current($this->env);
        if ($current->hasParent($node->getName())) {
          $this->link_class[] = 'link-lineage-active';
        }
        // Allow other modules to change the URL.
        $this->destination = '/' . $node->getName();

        // Add classes...
        $this->link_class[] = 'link-' . Api::string_normalize($node->getName());
        // Check if this node's link is identical to the current node.
        if (($this->destination . '/') == $this->env->request_uri) {
          $this->link_class[] = 'link-active';
        }
        $this->attributes['rel'] = $node->getName();
        if (empty($this->html_body)) {
          $this->html_body = $node->getTitle();
        }
      }
    }

    // Sets the link title (<a title=...).
    $this->link_title = empty($this->attributes['link_title']) ? strip_tags($this->html_body) : $this->attributes['link_title'];

    // Prepare variables for Link hooks.
    $vars = array(
      'qtag' => &$this,
      );
    $this->env->hook('link_alter', $vars);

    if (isset($this->attributes['title'])) {
      $this->html_body = $this->attributes['title'];
    }

    // Add custom classes to the link.
    if (isset($this->attributes['link_class'])) {
      // TODO: add single classes instead of one string
      $this->link_class[] = $this->attributes['link_class'];
    }

    // Check if there is a target language.
    if (!empty($this->attributes['language'])) {
      $this->querystring['lang'] = $this->attributes['language'];
    }
    // Sets a query string.
    $query = (!empty($querystring)) ? ('?' . implode('&', $this->querystring)) : '';

    // TODO: make just a big variable "data".
    // Check Quanta data types.
    $data_types = array('rel', 'language', 'type', 'widget', 'components', 'tooltip', 'redirect');
    foreach ($data_types as $data_type) {
      if (!empty($this->attributes[$data_type])) {
        if ($data_type == 'tooltip') {
          $this->attributes[$data_type] = htmlspecialchars($this->attributes[$data_type]);
        }
        $this->html_params['data-' . $data_type] =  $this->attributes[$data_type];
      }
    }

    $this->html_params['class'] = implode(' ', $this->link_class);
    $this->html_params['title'] = $this->link_title;
    $this->html_params['href'] = $this->destination . $query;
    $this->html_params['target'] = $this->link_target;
    if (!empty($this->link_id)) {
      $this->html_params['id'] = $this->link_id;
    }

    return parent::render();

  }
}
