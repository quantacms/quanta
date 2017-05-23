<?php

function fontawesome_metadata($vars) {
  // Setup body classes per each item in the current node lineage.
  $vars['page']->addData('metadata', array(
    '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />',
  ));
} 