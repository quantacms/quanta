<?php
namespace Quanta\Qtags;
use Quanta\Common\Localization;
use Quanta\Common\DirList;

/**
 * Renders Translation links.
 */
class TranslateLinks extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $this->attributes['active_items'] = Localization::getLanguage($this->env);
    // We don't want translate links to be considered as editable nodes.
    $this->attributes['editable'] = 'false';
    $this->attributes['symlinks'] = 'no';
    $dirlist = new DirList($this->env, Localization::DIR_LANGUAGES, 'translate_links', $this->attributes, 'localization');
    return $dirlist->render();
  }
}
