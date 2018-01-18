<?php
// TODO: redo the crap!
/**
 * Wiki Hook for USER.
 * @param $attributes
 * @param $uname
 * @param $string
 * @param $env
 * @return mixed|string
 */
  function wiki_USERATTRIBUTE($attributes, $uname, $string, $env) {
    $user = ($uname == NULL) ? UserFactory::current($env) : new User($env, $uname);
    switch($attributes['name']) {
      case 'name':
        $string = $user->getName();
        break;

      default:
        $string = '';
        break;
    }
    return $string;
  }


  function wiki_LOGIN($user, $var, $string, $page, $uname = NULL) {
		$user = UserFactory::current($page->env);
		return $user->exists ? '<a href="#" class="logout-link">Logout</a>' : '<a href="#" class="login-link">Login</a>';
	}

  function wiki_REGISTER($user, $var, $string, $page, $uname = NULL) {
		$user = UserFactory::current($page->env);
		return $user->exists ? '' : '<a href="#" class="register-link">Sign up</a>';
	}
