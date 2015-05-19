<?php
  /**
   * Wiki Hook for IMG.
   */
  function wiki_USER($replace, $userfull, $var, $string, $page) {
    $user = User::current($page->env);
    $string = str_replace($replace, $user->{$var}, $string);
    return $string;
  }
