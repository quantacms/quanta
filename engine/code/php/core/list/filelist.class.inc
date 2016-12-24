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
  protected $module_path = 'core/list';

  protected $scantype = DIR_FILES;
  /**
   * Construct a FileList object.
   *
   * @param $dirname
   * @param $type
   * @param $page
   */
  public function theme() {
    $user = User::current($this->env);
    $file_types = $this->getAttribute('file_types');
    $i = 0;

    foreach ($this->files as $file) {
      $i++;
      // If there is a limit set, break when passing it.
      if (!empty($this->limit) && $i > $this->limit) {
        break;
      }
      if ((($file_types == FALSE) || $file_types == $file->getType()) && $file->isPublic()) {
        $tpl = file_get_contents($this->module_path . '/tpl/' . $this->getTheme() . '.tpl.php');

        $tpl = preg_replace("/\{LISTITEM\}/is", string_normalize($file->getPath()), $tpl);
        $tpl = preg_replace("/\{LISTNODE\}/is", string_normalize($this->dir), $tpl);
        $tpl = transformCodeTags($this->env, $tpl);
        $classes = array('file-list-item', 'list-' . $this->getTheme() . '-item', 'list-item-' . $i);
        $tpl = '<li class="' . implode(' ', $classes) . '" index="' . $i . '">' . $tpl . '</li>';

        $tpl_item = $tpl;
        $this->tpl_items[] = $tpl_item;
      }
    }
  }
}
