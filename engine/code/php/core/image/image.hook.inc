<?php

function image_boot($vars) {
  if (isset($_REQUEST['thumb'])) {
    print trim(generateThumbnail($vars['env'], $_REQUEST['file'], 'thumb-' . str_replace('/', '-', $_REQUEST['file']), $_REQUEST['maxw'], $_REQUEST['maxh']));
    die();
  }
}