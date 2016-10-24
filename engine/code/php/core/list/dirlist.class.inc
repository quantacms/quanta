<?php

/**
 * Class DirList
 * This class is providing "listing" features useful to scan a folder and
 * render the files and folders contained into it.
 */
class DirList extends ListObject {
  protected $module_path = 'core/list';
  protected $scantype = DIR_DIRS;

	/**
   * Generate the html of the list.
   */
  public function theme() {
    $i = 0;
    $user_actions = array();
    $user_actions[] = '[[NODE:name]:Read all&rarr;]';

    foreach ($this->dirs as $dir_url => $node) {
			$i++;
      // If there is a limit set, break when passing it.
      if (!empty($this->limit) && $i > $this->limit) {
        break;
      }
      $classes = array('dir-list-item', 'list-' . $this->getTheme() . '-item', 'list-item-' . $i);
      if ($node->isCurrent()) {
        $classes[] = 'list-selected';
      }

      $node_father = Node::current($this->env)->getFather();

      if ($node_father->exists && ($node_father->name == $node->name)) {
        $classes[] = 'list-selected-father';
      }

      $thumbfile = new Image($this->env, $node->getThumbnail(), $node);
      $thumbnail = ($thumbfile->exists) ? $thumbfile->render() : '';
      $thumbclass = ($thumbfile->exists) ? 'thumb-img' : 'thumb-void';
      // TODO change way to include tpl.
      $tpl = file_get_contents($this->module_path . '/tpl/' . $this->getTheme() . '.tpl.php');
      $tpl = transformCodeTags($this->env, preg_replace("/\[LISTITEM\]/is", string_normalize($dir_url), $tpl));

      // TODO: node tokens should not be rendered in the list module?!
      $tpl = preg_replace("/\[NODE:user_actions\]/is", implode('', $user_actions), $tpl);
      $tpl = preg_replace("/\[NODE:title\]/is", $node->getTitle(), $tpl);
      $tpl = preg_replace("/\[NODE:thumbnail\]/is", '<div class="thumb ' . $thumbclass . '">' . $thumbnail . '</div>', $tpl);
      $tpl = preg_replace("/\[COUNTER\]/is", $i, $tpl);
      $tpl = preg_replace("/\[NODE:name\]/is", $dir_url, $tpl);
      $tpl = preg_replace("/\[NODE:date\]/is", $node->getDateTime(), $tpl);
      $tpl = preg_replace("/\[NODE:teaser\]/is", strip_tags(preg_replace('/\[(.*)\]/', '', $node->getTeaser())), $tpl);
      $tpl = preg_replace("/\[IMG\:/is", '[IMG:/' . $dir_url . '/', $tpl);

      $tpl = '<li class="' . implode(' ', $classes) . '">' . $tpl . '</li>';

      $this->tpl_items[] = $tpl;
    }
  }

  public function getDirs() {
    return $this->dirs;
  }

}
