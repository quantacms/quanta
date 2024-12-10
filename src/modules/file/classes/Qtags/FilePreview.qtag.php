<?php
namespace Quanta\Qtags;
use Quanta\Common\FileObject;
use Quanta\Common\NodeFactory;

/**
 * Renders a Preview of a file.
 */

class FilePreview extends Qtag {
  /**
   * Renders a specific attribute of a file.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getAttribute('node'));
    if (!empty($this->getAttribute('tmp_path'))) {
      $this->setTarget($this->env->dir['tmp'] . '/files/' . $this->getAttribute('tmp_path') . '/' . $this->getTarget());
      $node->setName(\Quanta\Common\Node::NODE_NEW);
    }

    $file = new FileObject($this->env, $this->getTarget(), $node);
    $preview = '';
    switch($file->getType()) {
      case 'image':
        $attributes['node'] = $node->getName();
        $preview_img = new ImgThumb($this->env, $this->getAttributes(), $this->getTarget());
        $preview = $preview_img;
        break;

      default:
        break;
    }

    return '<div class="file-preview-item file-' . $file->getType() . '">' . $preview . '</div>';
  }
}
