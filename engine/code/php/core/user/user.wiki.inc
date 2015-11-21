<?php
  /**
   * Wiki Hook for IMG.
   */
  function wiki_USER($replace, $userfull, $var, $string, $page) {
    $user = User::current($page->env);
    if (isset($user->{$var})) {
      // If var is an user property...
      $string = str_replace($replace, $user->{$var}, $string);
    } elseif ($user->getData($var) !== NULL) {
      // If var is in User data (external modules)...
      $string = str_replace($replace, $user->getData($var), $string);
    } else {
      // Last emergency option - print var name itself.
      $string = str_replace($replace, $var, $string);
    }
    return $string;
  }
