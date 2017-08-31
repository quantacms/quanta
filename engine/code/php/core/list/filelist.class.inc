<?php

/**
 * Class FileList
 * Defines a list of all files in a folder / node.
 * Its behavior is similar of that of a DirList.
 * @see DirList
 *
 */
class FileList extends ListObject {
  // TODO: implement.
  private $allowed_types;
  protected $scantype = DIR_FILES;
  /**
   * Construct a FileList object.
   *
   * @param $dirname
   * @param $type
   * @param $page
   */
  public function theme() {
    $file_types = $this->getAttribute('file_types');
    $i = 0;

    foreach ($this->files as $file) {
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
				$tpl = file_get_contents($this->getModulePath() . '/tpl/' . $this->getTpl() . '.tpl.php');
        $tpl = preg_replace("/\{LISTITEM\}/is", string_normalize($file->getPath()), $tpl);
        $tpl = preg_replace("/\{LISTNODE\}/is", string_normalize($this->dir), $tpl);
        $tpl = transformCodeTags($this->env, $tpl);
        $classes = array('file-list-item', 'list-item-' . $this->getTpl(), 'list-item-' . $i);
        $tpl = '<li class="' . implode(' ', $classes) . '" index="' . $i . '">' . $tpl . '</li>';

        $tpl_item = $tpl;
        $this->list_items[] = $tpl_item;
      }
    }
  }
  public function getFiles() {
    return $this->files;
  }
}
