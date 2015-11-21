<?php

function search_init($vars) {
  if ($vars['env']->getRequestedPath() == 'search') {
    $vars['env']->setData('content', search($vars['env']));
    $vars['env']->setData('title', 'search');
  }
}