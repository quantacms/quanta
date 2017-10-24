<?php

/**
 * Returns current language.
 */
function wiki_LANGUAGE($attributes, $form_id, $string, $env) {
  $language = $env->getLanguageNode();
}

/**
 * Language switcher.
 */
function wiki_LANGUAGE_SWITCHER($attributes, $target, $string, $env) {

  $node = empty($target) ? NodeFactory::current($env) : NodeFactory::load($env, $target);

  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $attributes['active_items'] = $env->getLanguage();

  $dirlist = new DirList($env, '_languages', 'language_switcher', array('sortbytime' => 'asc') + $attributes, 'locale');

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
 */
function wiki_TRANSLATE_LINKS($attributes, $value, $string, $env) {
  $attributes['active_items'] = $env->getLanguage();
  // We don't want translate links to be considered as editable nodes.
  $attributes['nowrap'] = TRUE;
  $dirlist = new DirList($env, '_languages', 'translate_links', array('sortbytime' => 'asc') + $attributes, 'locale');
  return $dirlist->render();
}

/**
 * Translate link.
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