<?php

  class Post extends Node {
    /**
     * Renders a node edit form.
     * @param $context
     * @return mixed
     */
    public function renderEditForm($context) {
      $this->context = $context;
      $edit_node = file_get_contents('core/node/tpl/node_edit.inc');
      return $edit_node;
    }


    /**
     * Renders a node delete form.
     * @param $context
     * @return mixed
     */
    public function renderDeleteForm($context) {
      $this->context = $context;
      $edit_node = file_get_contents('core/node/tpl/node_delete.inc');
      return $edit_node;
    }
  }