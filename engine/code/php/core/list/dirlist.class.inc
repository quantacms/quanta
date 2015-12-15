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
    $user_actions[] = '[[NODE:name]:Read all&rarr;]';
    foreach ($this->dirs as $dir_url => $node) {
      $i++;
      $classes = array('dir-list-item', 'list-' . $this->getTheme() . '-item', 'list-item-' . $i);
      if ($node->isCurrent()) {
        $classes[] = 'list-selected';
      }

      if (Node::current($this->env)->getFather()->name == $node->name) {
        $classes[] = 'list-selected-father';
      }


      $tpl_item = $tpl;
      $thumbfile = new Image($this->env, $node->getThumbnail(), $node);
      $thumbnail = ($thumbfile->exists) ? $thumbfile->render() : '';
      $thumbclass = ($thumbfile->exists) ? 'thumb-img' : 'thumb-void';

      $tpl_item = preg_replace("/\[NODE:user_actions\]/is", implode('', $user_actions), $tpl_item);
      $tpl_item = preg_replace("/\[NODE:title\]/is", $node->getTitle(), $tpl_item);
      $tpl_item = preg_replace("/\[NODE:thumbnail\]/is", '<div class="thumb ' . $thumbclass . '">' . $thumbnail . '</div>', $tpl_item);

      $tpl_item = preg_replace("/\[COUNTER\]/is", $i, $tpl_item);
      $tpl_item = preg_replace("/\[NODE:name\]/is", $dir_url, $tpl_item);
      $tpl_item = preg_replace("/\[NODE:date\]/is", $node->getDateTime(), $tpl_item);
      $tpl_item = preg_replace("/\[NODE:teaser\]/is", strip_tags(substr(preg_replace('/\[(.*)\]/', '', $node->getBody()), 0, 300)), $tpl_item);
      $tpl_item = preg_replace("/\[IMG\:/is", '[IMG:/' . $dir_url . '/', $tpl_item);
      $this->tpl_items[] = '<li class="' . implode(' ', $classes) . '">' . $tpl_item . '</li>';
    }
  }

  public function getDirs() {
    return $this->dirs;
  }

}
