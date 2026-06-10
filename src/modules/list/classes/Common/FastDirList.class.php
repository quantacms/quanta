<?php
namespace Quanta\Common;

/**
 * Class FastDirList
 * This class extends DirList but uses fast node loading to skip heavy hooks and access checks.
 * Use it ONLY for backend performance-critical lists where full Node functionality isn't needed.
 */
class FastDirList extends DirList {

  /**
   * Override the load method to use FastNodeLoading.
   */
  public function load($lang = null) {
    // Get the exact path of the list father node
    $parent_path = $this->env->nodePath($this->node->getName());
    
    // Scan directory directly
    $scan = $this->env->scanDirectory($parent_path, array('type' => $this->scantype, 'exclude_dirs' => Environment::DIR_INACTIVE));
    
    foreach ($scan as $dir) {
      if ($this->node->getName() == $dir && !$this->getData('list_father')) {
        continue;
      }
      
      // Fast load bypassing hooks and environment path search
      $node_path = $parent_path . '/' . $dir;
      $node = NodeFactory::fastLoadFromRealPath($this->env, $node_path, $this->language);
      
      if ($node->exists) {
        $this->addItem($node);
      }
    }
  }
}
