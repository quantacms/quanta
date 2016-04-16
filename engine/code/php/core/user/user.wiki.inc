<?php
  /**
   * Wiki Hook for USER.
   */
  function wiki_USER($userfull, $var, $string, $page, $uname = NULL) {

    $user = ($uname == NULL) ? User::current($page->env) : new User($page->env, $uname);

    if (isset($user->{$var})) {
      // If var is an user property...
      $item = $user->{$var};
    }
    elseif ($var == 'link') {


      $item = ($uname == USER_ANONYMOUS) ? USER_ANONYMOUS : '<a class="internal" href="/' . $user->getName() . '">' . $user->getName() . '</a>';
    }

    elseif ($user->getData($var) !== NULL) {
      // If var is in User data (external modules)...
      $item = $user->getData($var);
    }
    else {
      // Last emergency option - print var name itself.
      $item = $var;
    }

    return $item;
  }
