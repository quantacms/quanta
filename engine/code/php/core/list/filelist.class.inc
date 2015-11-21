<?php
  /**
  * Class FileList
  * Defines a list of all files in a folder / node.
  * Its behavior is similar of that of a DirList.
  * @see DirList
  *
  */
  class FileList extends ListObject {
    private $allowed_types;
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
    foreach ($this->files as $file) {
      if ((($file_types == FALSE) || $file_types == $file->getType()) && $file->isPublic()) {
        $tpl = file_get_contents('core/list/tpl/' . $this->getTheme() . '.tpl.php');
        $tpl_item = $tpl;
        $user_actions = array();
        // TODO: get useractions from file.
        if ($user->checkAccess($this->node, NODE_ACTION_EDIT)) {
          $user_actions[] = '<a title="Set as thumbnail" rel="' . $file->path . '" href="#" style="text-decoration: none" class="set-thumbnail ' . (($this->node->getThumbnail() == $file->path) ? 'selected-thumbnail' : '') . '">&#9786;</a>';
        }
        if ($user->checkAccess($this->node, NODE_ACTION_DELETE)) {
          $user_actions[] = '<a title="Delete" href="#" class="delete-file">☒</a>';
        }
        $wikitag = $file->type == 'image' ? '[IMG:' . $file->path . ']' : ' [FILE:' . $file->path . ']';
        $tpl_item = preg_replace ("/\[USER_ACTIONS\]/is", implode('', $user_actions), $tpl_item);
        $tpl_item = preg_replace ("/\[URL\]/is", $file->path, $tpl_item);
        $tpl_item = preg_replace ("/\[EXT\]/is", $file->extension, $tpl_item);
        $tpl_item = preg_replace ("/\[PAGE_URL\]/is", $file->node->getName(), $tpl_item);
        $tpl_item = preg_replace ("/\[TITLE\]/is", $file->name, $tpl_item);
        $tpl_item = preg_replace ("/\[WIKI_TAG\]/is", $wikitag, $tpl_item);

        $this->tpl_items[] = $tpl_item;
      }
    }
  }

    public function clearFiles() {
      $this->files = array();
      $this->tpl_items = array();

    }


}