<?php

/**
 * Class Doctor
 * This class is used to perform .
 */
class Doctor extends DataContainer {
  public $env;
  public function __construct($env) {
    $this->env = $env;
  }

  public function checkBrokenLinks() {
    $path = is_link($this->env->dir['docroot']) ? ($_SERVER['DOCUMENT_ROOT'] . '/sites/' . readlink($this->env->dir['docroot'])) : $this->env->dir['docroot'];
    print "Searching for all symlinks in " . $path . "...";
    flush();
    $symlinks_find_cmd = "find " . $path . " -type l";
    exec($symlinks_find_cmd, $results_arr);
    print "..." . count($results_arr) . ' found.<br>';
    $wrong_links = 0;
    $fixed_links = 0;
    $unfixable_links = array();

    // Cycle all symlinks in the system.
    foreach ($results_arr as $k => $result_string) {
      $link_exists = is_dir(readlink($result_string));
      $link_name = array_reverse(explode('/', $result_string))[0];
      // If a link is not corresponding to a dir, attempt to find the real dir.

      if (!$link_exists) {
        $wrong_links++;
        $realpath = $this->env->nodePath($link_name);

        print "Looking for " . $link_name . ' (' . $realpath . ')...<br>';
        if (is_dir($realpath)) {
          // Attempt to change the target dir of the link.
          unlink($result_string);
          link($realpath, $result_string);
          print "...fixed! :-) <br>";
          $fixed_links++;
        } else {
          print "...none found :-( <br>";
          $unfixable_links[] = $result_string;
        }

      }

    }
    print $wrong_links . " wrong links found.<br>";
    if ($fixed_links > 0) {
      print $fixed_links . " wrong links <b style='color: green'>FIXED</b>!<br>";
    }
    if (count($unfixable_links) > 0) {
      print "<b style='color:red'>Could not fix those " . count($unfixable_links) . " wrong links. Maybe they were deleted?</b><br>";
      print implode('<br>', $unfixable_links);
    }

  }

  public function checkExistingHome() {
    print 'Looking for <b>Homepage...</b> node<br>';
    $homenode = NodeFactory::load($this->env, 'home');
    if (!($homenode->exists)) {
      print 'Not found! Attempting to create Homepage...<br>';
      $home = NodeFactory::buildNode($this->env, 'home', '_pages', array('title' => 'Your Homepage', 'Welcome in your new Homepage. That\'s where everything begins...'));

    }
    else {
    print '<b style="color:green">Found at ' . $homenode->realpath . '!</b><br>';
    }
  }

  public function checkAdminUser() {
    print 'Looking for <b>Admin</b> user<br>';
    $adminuser = UserFactory::load($this->env, 'administrator');
    if (!($adminuser->exists)) {
      print 'Not found! Attempting to create Admin User...<br>';
      $adminuser = UserFactory::buildUser($this->env, 'administrator', array('title' => 'Administrator', 'password' => 'pass', 'roles' => array('admin')));
      print 'Done!<br>';
    }
    else {
      print '<b style="color:green">Found!</b><br>';
    }
  }

  public function runAllTasks() {
    $this->checkBrokenLinks();
    $this->checkExistingHome();
    $this->checkAdminUser();

    print "<b>Doctor has finished curing your environment!</b>";
    die();
  }
}