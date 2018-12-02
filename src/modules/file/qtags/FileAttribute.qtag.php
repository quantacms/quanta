<?php
namespace Quanta\Qtags;
use Quanta\Common\FileObject;
use Quanta\Common\NodeFactory;
/**
 * Renders an Attribuet of a File.
 */

class FileAttribute extends Qtag {
  /**
   * Renders a specific attribute of a file.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = NodeFactory::loadOrCurrent($this->env, $this->getAttribute('node'));
    $file = new FileObject($this->env, $this->getTarget(), $node);
    $string = NULL;

    // Check which file attribute is requested, and provide it.
    switch($this->getAttribute('name')) {

      case 'name':
        $string = $file->getName();
        break;

      case 'path':
        $string = $file->getFullPath();
        break;

      case 'type':
        $string = $file::getFileType($file->getExtension());
        break;

      case 'size':
        $string = $file->getFileSize();
        break;
    }

    return $string;
  }
}
