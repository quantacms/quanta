<?php

function search_init($vars) {
  if ($vars['env']->getRequestedPath() == 'search') {
    $vars['env']->setData('content', search($vars['env']), 'node');
    $vars['env']->setData('title', 'search', 'node');
  }
}