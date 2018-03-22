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
  public function generateList() {

    $file_types = $this->getAttribute('file_types');
    $i = 0;
    $tpl = file_get_contents($this->getModulePath() . '/tpl/' . $this->getTpl() . '.tpl.php');

    foreach ($this->items as $file) {
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
          $list_item = '<li class="' . implode(' ', $classes) . '" data-index="' . $i . '">' . $list_item . '</li>';
        }

        $this->rendered_items[$file->getName()] = $list_item;
      }
    }
  }

  /**
   * Sort the file list.
   *
   * @param File $x
   * @param File $y
   * @return int
   */
  public function sortBy($x, $y) {
    {
      // Which field to use for sorting.
      switch ($this->sort) {
        case 'name':
          $check = strcasecmp($x->getName(), $y->getName()) > 0;
          break;
        case 'type':
          $check = strcasecmp($x->getType(), $y->getType()) > 0;
          break;
        case 'size':
          $check = ($x->getFileSize() < $y->getFileSize());
          break;

        case 'time':
          $check = ($x->getTimestamp() < $y->getTimestamp());
          break;

        case 'weight':
          if (!empty($this->getNode()->json->files)) {
            // Rearrange Files according with what was set in the node json.
            $files_json = array_flip($this->getNode()->json->files);
            $check = ($files_json[$x->getName()] > $files_json[$y->getName()]);
            // Compare files found in the directory with files saved in the json
            // and reorder them accordingly.
            // This allows the user to keep the file order he has set via drag&drop.
          }
          break;

        default:
          //Sort by Number DESC
          $check = ($x->getAttributeJSON($this->sort) < $y->getAttributeJSON($this->sort));
          break;
      }

      return ($check) ? 1 : -1;
    }
  }

}
