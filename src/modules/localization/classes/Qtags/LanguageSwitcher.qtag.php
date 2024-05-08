<?php
namespace Quanta\Qtags;

/**
 * Returns the current language.
 */
class LanguageSwitcher extends HtmlTag {
  /**
   * Render the Qtag.
   *
   * @return string
   *   The rendered Qtag.
   */
  public function render() {

    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $language_switcher_tpl = 'language_switcher';
    $has_translation = $this->getAttribute('has_translation');

    // We don't want translate links to be considered as editable nodes.
    $this->attributes['editable'] = 'false';
    $this->attributes['active_items'] = \Quanta\Common\Localization::getLanguage($this->env);
    $this->attributes['symlinks'] = 'no';

    if (isset($this->attributes['compact'])){
      // Uses name for label instead of title.
      $language_switcher_tpl = 'language_switcher_compact';
    }
    $dirlist = new \Quanta\Common\DirList($this->env, \Quanta\Common\Localization::DIR_LANGUAGES, $language_switcher_tpl, $this->attributes, 'localization');
    // Don't show language switch link, if node is not available in that language.
    if($has_translation){
      foreach ($dirlist->getItems() as $langcode => $lang) {
        if (!$node->hasTranslation($langcode)) {
          $dirlist->removeDir($langcode);
        }
      }
    }


    $this->html_body = $dirlist->render();
    return parent::render();
  }
}
