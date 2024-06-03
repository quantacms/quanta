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
  const DEFAULT_FILE_FIELD = 'files';

  protected $scantype = \Quanta\Common\Environment::DIR_FILES;
  /** @var string $filename */
  public $filefield = self::DEFAULT_FILE_FIELD;

  public function start() {
    if (!empty($this->getData('file_field'))) {
      $this->filefield = $this->getData('file_field');
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

    $file_types = $this->getData('file_types');

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
      $classes = array('file-list-item', 'list-item-' . $this->getTpl(), 'list-item-' . $i, (($i % 2) == 0) ? 'list-item-even' : 'list-item-odd');
     
      if ((($file_types == FALSE) || (is_array($file_types) && in_array($file->getType(),$file_types)) || (!is_array($file_types) && $file_types == $file->getType())) && $file->isPublic()) {

        // TODO: not a beautiful approach. Invent something better.
        $list_item = preg_replace("/\{LISTITEM\}/is", Api::string_normalize($file->getPath()), $tpl);
        $list_item = preg_replace("/\{LISTNODE\}/is", Api::string_normalize($this->getNode()->getName()), $list_item);
        $list_item = QtagFactory::transformCodeTags($this->env, $list_item);
        $vars = array(
          'list' => &$this,
          'list_item' => &$list_item,
          'list_item_counter' => $i,
          'list_item_classes' => &$classes,
        );
        $this->env->hook('list_item', $vars);

        // If "clean" mode is set don't add wrapping li tags.
        if (empty($this->getData('clean'))) {
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

    // Switch field to use for sorting.
    switch ($this->sort) {

      // Sort files by file type.
      case 'type':
        $check = strcasecmp($x->getType(), $y->getType()) > 0;
        break;

      // Sort files by file size.
      case 'size':
        $check = ($x->getFileSize() < $y->getFileSize());
        break;

      // Order files by weight (as they are sorted using the UI).
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

      // Sort files alphabetically by Name (default).
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
   if (($this->getNode()->getAttributeJSON($this->filefield)==NULL) || empty($this->getNode()->getAttributeJSON($this->filefield))) {
	   $node_files = array();
   }	  
   elseif (!is_array($this->getNode()->getAttributeJSON($this->filefield))) {
   	$node_files = array($this->getNode()->getAttributeJSON($this->filefield));
   }

   if ($node_files == NULL) {
   	$node_files = array();
   }
   $node_files = array_flip($node_files);

    // The "files" filefield is used in the standard Quanta files, containing all uploaded files in the folder.
    // For other file inputs, filter files by those that have been uploaded through that specific input.
    if (($this->filefield == self::DEFAULT_FILE_FIELD) || isset($node_files[$file->getPath()])) {
      $this->items[] = $file;
    }

  }
}
