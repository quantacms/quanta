<?php
namespace Quanta\Common;

/**
 * Class FileList
 * Defines a list of all files in a folder / node.
 * Its behavior is similar of that of a DirList.
 * @see DirList
 *
 */
class FileList extends ListObject {
  protected $scantype = DIR_FILES;
  /** @var string $filename */
  public $filefield = 'files';

  public function start() {
    if (!empty($this->getAttribute('file_field'))) {
      $this->filefield = $this->getAttribute('file_field');
    }

  }

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

      /** @var FileObject $file */
      $i++;

      // Check that this file is not excluded by parameters.
      if (!empty($this->exclude) && !isset($this->exclude[$i])) {
        continue;
      }
      // Check that this file is included, if specified.
      if (!empty($this->include) && isset($this->include[$i])) {
        continue;
      }
      // If there is a limit set, break when passing it.
      if (!empty($this->limit) && $i > $this->limit) {
        break;
      }
      if ((($file_types == FALSE) || $file_types == $file->getType()) && $file->isPublic()) {

        // TODO: not a beautiful approach. Invent something better.
        $list_item = preg_replace("/\{LISTITEM\}/is", Api::string_normalize($file->getPath()), $tpl);
        $list_item = preg_replace("/\{LISTNODE\}/is", Api::string_normalize($this->getNode()->getName()), $list_item);
        $list_item = QtagFactory::transformCodeTags($this->env, $list_item);

        // If "clean" mode is set don't add wrapping li tags.
        if (empty($this->getAttribute('clean'))) {
          $classes = array('file-list-item', 'list-item-' . $this->getTpl(), 'list-item-' . $i);
          $list_item = '<' . $this->getData('list_item_html_tag') . ' class="' . implode(' ', $classes) . '" data-index="' . $i . '">' . $list_item . '</' . $this->getData('list_item_html_tag') . '>';
        }

        $this->rendered_items[$file->getName()] = $list_item;
      }
    }
  }

  /**
   * Sort the file list.
   *
   * @param FileObject $x
   * @param FileObject $y
   * @return int
   */
  public function sortBy($x, $y) {

    // Which field to use for sorting.
    switch ($this->sort) {


      case 'type':
        $check = strcasecmp($x->getType(), $y->getType()) > 0;
        break;

      case 'size':
        $check = ($x->getFileSize() < $y->getFileSize());
        break;

      case 'weight':
        if (!empty($this->getNode()->json->{$this->filefield})) {
          // Rearrange Files according with what was set in the node json.
          $files_json = array_flip($this->getNode()->json->{$this->filefield});

          if (isset($files_json[$x->getName()]) && isset($files_json[$y->getName()])) {
            $check = ($files_json[$x->getName()] > $files_json[$y->getName()]);
          }
          else {
            $check = TRUE;
          }
          // Compare files found in the directory with files saved in the json
          // and reorder them accordingly.
          // This allows the user to keep the file order he has set via drag&drop.
        }
        else {
          $check = FALSE;
        }
        break;
        
      case 'name':
      default:
      $check = strcasecmp($x->getName(), $y->getName()) > 0;
        break;
    }

    return ($check) ? 1 : -1;
  }

  /**
   * Adds a file to the list queue.
   *
   * @param FileObject $file
   *   The file to be added.
   */
  public function addItem($file) {
    // Check that this file belongs to the file field as in json.
    // TODO: why this was done?.
    //      $node_files = array_flip($this->getNode()->getAttributeJSON($this->filefield));.
    // if (!isset($node_files[$file->getPath()])) {
    //  new Message($this->env, 'File absent from JSON file: ' . $file->getName() . '. Please re-save this node to fix the problem.');
    // }
    $this->items[] = $file;

  }
}
