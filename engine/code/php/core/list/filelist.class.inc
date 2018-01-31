<?php

/**
 * Class FileList
 * Defines a list of all files in a folder / node.
 * Its behavior is similar of that of a DirList.
 * @see DirList
 *
 */
class FileList extends ListObject {
  protected $scantype = DIR_FILES;

  /**
   * Construct a FileList object.
   *
   * @internal param $dirname
   * @internal param $type
   * @internal param $page
   */
  public function theme() {
    $file_types = $this->getAttribute('file_types');
    $i = 0;
    $tpl = file_get_contents($this->getModulePath() . '/tpl/' . $this->getTpl() . '.tpl.php');
    foreach ($this->files as $file) {
      /** @var File $file */
      $i++;
      if (!empty($this->exclude) && !isset($this->exclude[$i])) {
        continue;
      }

      if (!empty($this->include) && isset($this->include[$i])) {
        continue;
      }
      // If there is a limit set, break when passing it.
      if (!empty($this->limit) && $i > $this->limit) {
        break;
      }
      if ((($file_types == FALSE) || $file_types == $file->getType()) && $file->isPublic()) {

        $list_item = preg_replace("/\{LISTITEM\}/is", string_normalize($file->getPath()), $tpl);
        $list_item = preg_replace("/\{LISTNODE\}/is", string_normalize($this->node->getName()), $list_item);
        $list_item = transformCodeTags($this->env, $list_item);

        // If "clean" mode is set don't add wrapping li tags.
        if (empty($this->getAttribute('clean'))) {
          $classes = array('file-list-item', 'list-item-' . $this->getTpl(), 'list-item-' . $i);
          $list_item = '<li class="' . implode(' ', $classes) . '" index="' . $i . '">' . $list_item . '</li>';
        }

        $this->list_items[] = $list_item;
      }
    }
  }
  public function getFiles() {
    return $this->files;
  }
}
