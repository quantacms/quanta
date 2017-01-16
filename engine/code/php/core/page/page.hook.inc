<?php
/**
 * Implements hook_body_classes.
 */
function page_body_classes($vars) {
  $vars['page']->addData('body_classes', array('page'));
}

