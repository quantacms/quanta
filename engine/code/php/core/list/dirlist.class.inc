<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 08/05/15
 * Time: 12:18
 */
/**
 * Class DirList
 * This class is providing "listing" features useful to scan a folder and
 * render the files and folders contained into it.
 */
class DirList extends ListObject {
  /**
   * Generate the html of the list.
   */
  public function theme() {
    // TODO change way to include tpl.
    $tpl = file_get_contents('core/list/tpl/' . $this->getTheme() . '.tpl.php');
    $i = 0;
    $user_actions = array();
      $user_actions[] = '[[URL]:Read all&rarr;]';
      foreach($this->dirs as $dir_url => $dir) {
        $tpl_item = $tpl;
        $tpl_item = preg_replace ("/\[USER_ACTIONS\]/is", implode('', $user_actions), $tpl_item);
        $tpl_item = preg_replace ("/\[TITLE\]/is", $dir['title'], $tpl_item);
        $tpl_item = preg_replace ("/\[COUNTER\]/is", $i, $tpl_item);
        $tpl_item = preg_replace ("/\[URL\]/is", $dir_url, $tpl_item);
        $tpl_item = preg_replace ("/\[DATE\]/is", date('d-M-Y H:i:s', $dir['timestamp']), $tpl_item);
        $tpl_item = preg_replace ("/\[PREVIEW\]/is", strip_tags(substr($dir['content'], 0, 300), 'img'), $tpl_item);
        $tpl_item = preg_replace ("/\[IMG\:/is", '[IMG:/' . $dir_url . '/', $tpl_item);
        $this->tpl_items[] = $tpl_item;
      }
  }



}
