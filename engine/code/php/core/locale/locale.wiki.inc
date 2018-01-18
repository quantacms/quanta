<?php

/**
 * Returns current language.
 *
 * @param array $attributes
 *
 * @param string $target
 *
 * @param string $string
 *
 * @param Environment $env
 * @return mixed $language
 *   The language.
 */
function wiki_LANGUAGE($attributes, $target, $string, Environment $env) {
  return Locale::getLanguage($env);
}

function wiki_FALLBACK_LANGUAGE($attributes, $target, $string, Environment $env) {
  return Locale::getFallbackLanguage($env);
}

/**
 * Language switcher.
 * @param $attributes
 * @param $target
 * @param $string
 * @param Environment $env
 * @return bool|mixed|string
 */
function wiki_LANGUAGE_SWITCHER($attributes, $target, $string, Environment $env) {

  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);
  $language_switcher_tpl = 'language_switcher';

  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $attributes['active_items'] = Locale::getLanguage($env);


  if (isset($attributes['compact'])){
    //uses name for label instead of title
    $language_switcher_tpl = 'language_switcher_compact';
  }

  $dirlist = new DirList($env, DIR_LANGUAGES, $language_switcher_tpl, array('sortbytime' => 'asc') + $attributes, 'locale');

  // Don't show language switch link, if node is not available in that language.
  foreach ($dirlist->getDirs() as $langcode => $lang) {
    if (!$node->hasTranslation($langcode)) {
      $dirlist->removeDir($langcode);
    }
  }

  return $dirlist->render();
}

/**
 * Translate links.
 * @param $attributes
 * @param $value
 * @param $string
 * @param $env
 * @return bool|mixed|string
 */
function wiki_TRANSLATE_LINKS($attributes, $value, $string, $env) {
  $attributes['active_items'] = Locale::getLanguage($env);
  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $dirlist = new DirList($env, DIR_LANGUAGES, 'translate_links', array('sortbytime' => 'asc') + $attributes, 'locale');
  return $dirlist->render();
}

/**
 * Translate link.
 * @param $attributes
 * @param $node
 * @param $string
 * @param $env
 * @return bool|string|void
 */
function wiki_TRANSLATE($attributes, $node, $string, $env) {
  $node = NodeFactory::load($env, $node);
  $current = NodeFactory::current($env);
  if (!$node->exists) {
    return FALSE;
  }
  $attributes['language'] = $node->getName();
  $attributes['title'] = $node->getTitle();
  return wiki_EDIT($attributes, $current->getName(), $string, $env);
}