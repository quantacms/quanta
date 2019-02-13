<?php
namespace Quanta\Qtags;
use Quanta\Common\FileObject;
use Quanta\Common\NodeFactory;

/**
 * Provides a "suggested qtag" for rendering a file.
 */
class FileQtagSuggestion extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = empty($this->attributes['node']) ? NodeFactory::current($this->env) : NodeFactory::load($this->env, $this->attributes['node']);
    if (isset($this->attributes['tmp_path'])) {
      $file = new FileObject($this->env, $this->env->dir['tmp'] . '/files/' . $this->attributes['tmp_path'] . '/' . $this->getTarget(), \Quanta\Common\Node::NODE_NEW);
    }
    else {
      $file = new FileObject($this->env, $this->getTarget(), $node);
    }

    switch ($file->getType()) {
      case 'image':
        $suggestion = '[IMG|showtag:' . $this->getTarget() . ']';
        break;

      default:
        $suggestion = '[FILE|showtag:' . $this->getTarget() . ']';
        break;
    }

    return $suggestion;
  }
}
