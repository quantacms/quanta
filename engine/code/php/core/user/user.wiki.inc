<?php
  /**
   * Wiki Hook for USER.
   */
  function wiki_USER($userfull, $var, $string, $page) {
    $user = User::current($page->env);
    if (isset($user->{$var})) {
      // If var is an user property...
      return $user->{$var};
    } elseif ($user->getData($var) !== NULL) {
      // If var is in User data (external modules)...
      return $user->getData($var);
    } else {
      // Last emergency option - print var name itself.
      return $var;
    }
  }
