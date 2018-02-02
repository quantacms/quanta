<?php

/**
 * Implements qtag LANGUAGE.
 *
 * Returns language code of the current language.
 */
function qtag_LANGUAGE($env, $target, $attributes) {
  return Locale::getLanguage($env);
}

/**
 * Implements qtag FALLBACK_LANGUAGE.
 *
 * Return the language code of the fallback language.
 */
function qtag_FALLBACK_LANGUAGE($env, $target, $attributes) {
  return Locale::getFallbackLanguage($env);
}

/**
 * Implements qtag LANGUAGE_SWITCHER.
 */
function qtag_LANGUAGE_SWITCHER($env, $target, $attributes) {

  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $language_switcher_tpl = 'language_switcher';

  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $attributes['active_items'] = Locale::getLanguage($env);
  $attributes['symlinks'] = 'no';

  if (isset($attributes['compact'])){
    //uses name for label instead of title
    $language_switcher_tpl = 'language_switcher_compact';
  }

  $dirlist = new DirList($env, DIR_LANGUAGES, $language_switcher_tpl, $attributes, 'locale');

  // Don't show language switch link, if node is not available in that language.
  foreach ($dirlist->getDirs() as $langcode => $lang) {
    if (!$node->hasTranslation($langcode)) {
      $dirlist->removeDir($langcode);
    }
  }

  return $dirlist->render();
}

/**
 * Implements qtag TRANSLATE_LINKS.
 *
 * Renders Translate links.
 */
function qtag_TRANSLATE_LINKS($env, $target, $attributes) {
  $attributes['active_items'] = Locale::getLanguage($env);
  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $attributes['symlinks'] = 'no';
  $dirlist = new DirList($env, DIR_LANGUAGES, 'translate_links', $attributes, 'locale');
  return $dirlist->render();
}

/**
 * Implements qtag TRANSLATE.
 *
 * Renders a translate link.
 *
 */
function qtag_TRANSLATE($env, $target, $attributes) {
  $node = NodeFactory::load($env, $target);
  $current = NodeFactory::current($env);
  if (!$node->exists) {
    return '';
  }
  $attributes['language'] = $node->getName();
  $attributes['title'] = $node->getTitle();
  return qtag_EDIT($env, $current->getName(), $attributes);
}