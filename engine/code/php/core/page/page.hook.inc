<?php
/**
 * Implements hook_body_classes.
 */
function page_body_classes($vars) {
  $vars['page']->addBodyClass('page');
}

function page_page_init($vars) {
  if ($vars['env']->getData('content') == NULL) {
    redirect('/403');
  }
}

function page_init($vars) {
  if ($vars['env']->getRequestedPath() == '403') {
    $vars['env']->setData('content', file_get_contents('core/page/tpl/403.inc'), 'page');
  }
}