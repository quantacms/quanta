<?php

/**
 * Implements qtag LANGUAGE.
 *
 * Returns language code of the current language.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_LANGUAGE($env, $target, $attributes) {
  return Localization::getLanguage($env);
}

/**
 * Implements qtag FALLBACK_LANGUAGE.
 *
 * Return the language code of the fallback language.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_FALLBACK_LANGUAGE($env, $target, $attributes) {
  return Localization::getFallbackLanguage($env);
}

/**
 * Implements qtag LANGUAGE_SWITCHER.
 *
 * Renders a language switcher, allowing to switch between active languages.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_LANGUAGE_SWITCHER($env, $target, $attributes) {

  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $language_switcher_tpl = 'language_switcher';

  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $attributes['active_items'] = Localization::getLanguage($env);
  $attributes['symlinks'] = 'no';

  if (isset($attributes['compact'])){
    // Uses name for label instead of title.
    $language_switcher_tpl = 'language_switcher_compact';
  }
  $dirlist = new DirList($env, DIR_LANGUAGES, $language_switcher_tpl, $attributes, 'localization');
  // Don't show language switch link, if node is not available in that language.
  foreach ($dirlist->getItems() as $langcode => $lang) {
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
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
 */
function qtag_TRANSLATE_LINKS($env, $target, $attributes) {
  $attributes['active_items'] = Localization::getLanguage($env);
  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $attributes['symlinks'] = 'no';
  $dirlist = new DirList($env, DIR_LANGUAGES, 'translate_links', $attributes, 'localization');
  return $dirlist->render();
}

/**
 * Implements qtag TRANSLATE.
 *
 * Renders a translate link.
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param string $target
 *   The qtag's target.
 *
 * @param array $attributes
 *   The qtag's attributes.
 *
 * @return string
 *   The rendered qtag.
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
