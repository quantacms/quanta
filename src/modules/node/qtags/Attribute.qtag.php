<?php
namespace Quanta\Qtags;
use Quanta\Common\NodeFactory;
use Quanta\Common\Api;
use Quanta\Common\Message;
/**
 * Allows retrieving and rendering standard attributes of a node.
 */
class Attribute extends Qtag {
  /**
   * Renders the author of a node as an user link.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    switch ($this->attributes['name']) {
      // Node's name (aka name of the folder).
      case 'name':
        $string = $node->getName();
        if ($string == NODE_NEW) {
          $string = '';
        }
        break;

      // Author's name (aka name of the author's node)
      case 'author':
        $string = $node->getAuthor();
        // In case node has no author, return NULL string.
        if ($string == USER_ANONYMOUS) {
          $string = '';
        }
        break;

      // Node's title.
      case 'title':
        $string = Api::filter_xss($node->getTitle());
        break;

      // Node's full rendered content.
      case 'content':
        $string = NodeFactory::render($this->env, $node);
        break;

      // Node's body.
      case 'body':
        $string = $node->getBody();
        break;

      // Node's teaser.
      case 'teaser':
        $string = Api::filter_xss($node->getTeaser());
        break;

      // Node's father node.
      case 'father':
        $string = $node->getFather()->getName();
        break;

      // Node's creation time.
      case 'time':
        $string = $node->getTime();
        break;

      // Node's creation date.
      case 'date':
        $string = $node->getDate();
        break;

      // Node edit screen's temporary file upload directory.
      case 'tmp_files_dir':
        $string = $node->getData('tmp_files_dir');
        break;

      // Node's thumbnail file.
      case 'thumbnail':
        $string = $node->getThumbnail();
        break;

      // Node's status.
      case 'status':
        $string = $node->getStatus();
        break;

      default:
        $string = '';
        new Message($this->env, t('Error: trying to fetch the invalid attribute %attribute', array('%attribute' => $this->attributes['name']), MESSAGE_WARNING));
        break;
    }

    return $string;
  }
}
