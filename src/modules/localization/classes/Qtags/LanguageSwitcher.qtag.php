<?php
namespace Quanta\Qtags;
use Quanta\Common\Localization;
use Quanta\Common\NodeFactory;
use Quanta\Common\DirList;

/**
 * Returns the current language.
 */
class LanguageSwitcher extends Qtag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $node = NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $language_switcher_tpl = 'language_switcher';

    // We don't want translate links to be considered as editable nodes.
    $this->attributes['editable'] = 'false';
    $this->attributes['active_items'] = Localization::getLanguage($this->env);
    $this->attributes['symlinks'] = 'no';

    if (isset($this->attributes['compact'])){
      // Uses name for label instead of title.
      $language_switcher_tpl = 'language_switcher_compact';
    }
    $dirlist = new DirList($this->env, Localization::DIR_LANGUAGES, $language_switcher_tpl, $this->attributes, 'localization');
    // Don't show language switch link, if node is not available in that language.
    foreach ($dirlist->getItems() as $langcode => $lang) {
      if (!$node->hasTranslation($langcode)) {
        $dirlist->removeDir($langcode);
      }
    }

    return $dirlist->render();  }
}
