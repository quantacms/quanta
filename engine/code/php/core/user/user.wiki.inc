<?php
// TODO: redo the crap!
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


  function wiki_LOGIN($user, $var, $string, $page, $uname = NULL) {
		$user = User::current($page->env);
		return $user->exists ? '<a href="#" class="logout-link">Logout</a>' : '<a href="#" class="login-link">Login</a>';
	}

  function wiki_REGISTER($user, $var, $string, $page, $uname = NULL) {
		$user = User::current($page->env);
		return $user->exists ? '' : '<a href="#" class="register-link">Sign up</a>';
	}
