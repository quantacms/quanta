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

  public function checkSystemPaths() {
    Doctor::talk("Checking system paths.");
      foreach ($this->env->dir as $dirname => $folder) {
        Doctor::talk('Checking ' . $dirname . '(<i>' . $folder. ')</i>');
        if (!is_dir($folder) && !is_link($folder)) {
          Doctor::ko('non existing. Creating...');
          mkdir($folder) or die("Error: can not create " . $folder . '. Check permission and path.');
          Doctor::ok();
        } else {
          Doctor::ok();
        }
      }
  }

  // Check & repair any broken links.
  public function checkBrokenLinks() {
    $path = is_link($this->env->dir['docroot']) ? ($_SERVER['DOCUMENT_ROOT'] . '/sites/' . readlink($this->env->dir['docroot'])) : $this->env->dir['docroot'];
    Doctor::talk("Searching for all symlinks in " . $path . "...");
    flush();
    $symlinks_find_cmd = "find " . $path . " -type l";
    exec($symlinks_find_cmd, $results_arr);
    Doctor::talk("..." . count($results_arr) . ' found.<br>');
    $wrong_links = 0;
    $fixed_links = 0;
    $unfixable_links = array();

    // Cycle all symlinks in the system.
    foreach ($results_arr as $k => $result_string) {
      $link_target = readlink($result_string);
      $link_exists = is_dir($link_target);
      $link_name = array_reverse(explode('/', $result_string))[0];
      // If a link is not corresponding to a dir, attempt to find the real dir.

      if (!$link_exists) {
        $wrong_links++;
        $realpath = $this->env->nodePath($link_name);
        Doctor::talk("Looking for " . $link_name . ' (' . $realpath . ')...<br>');

        if (is_dir($realpath)) {
          // Attempt to change the target dir of the link.
          unlink($result_string);
          link($realpath, $result_string);
          Doctor::ok("...fixed! :-) <br>");
          $fixed_links++;
        } else {
          Doctor::ko("...none found :-( <br>");
          $unfixable_links[] = $result_string;
        }

      }
      else {
        Doctor::talk($result_string . ' -> ' . $link_target . '<br><br>');
      }

    }
    Doctor::talk($wrong_links . " wrong links found.");
    if ($fixed_links > 0) {
      Doctor::ok($fixed_links . " wrong links <b style='color: green'>FIXED</b>!<br>");
    }
    if (count($unfixable_links) > 0) {
      Doctor::ko("<b style='color:red'>Could not fix those " . count($unfixable_links) . " wrong links. Maybe they were deleted?");
      Doctor::talk(implode('<br>', $unfixable_links));
    }

  }

  public function checkExistingHome() {
    Doctor::talk('Looking for <b>Homepage...</b> node<br>');
    $homenode = NodeFactory::load($this->env, 'home');
    if (!($homenode->exists)) {
      Doctor::ko('Not found! Attempting to create Homepage...');
      NodeFactory::buildNode($this->env, 'home', '_pages', array('title' => 'Your Homepage', 'Welcome in your new Homepage. That\'s where everything begins...'));

    }
    else {
      Doctor::ok('Found at ' . $homenode->realpath . '!</b><br>');
    }
  }

  public function checkAdminUser() {
    Doctor::talk('Looking for <b>Admin</b> user');
    $adminuser = UserFactory::load($this->env, 'administrator');
    if (!($adminuser->exists)) {
      Doctor::talk('Not found! Attempting to create Admin User...');
      UserFactory::buildUser($this->env, 'administrator', array(
        'title' => 'Administrator',
        'password' => 'pass',
        'roles' => array('admin'),
        'email' => 'admin@changeme.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        ));
      Doctor::ok('Done!');
    }
    else {
      Doctor::ok('Found!');
    }
  }

  public function runAllTasks() {
    $this->checkSystemPaths();
    $this->checkBrokenLinks();
    $this->checkExistingHome();
    $this->checkAdminUser();
    Doctor::talk('Running setup hooks...');
    // Run all setup hooks.
    $this->env->hook('setup');
    Doctor::ok();

    Doctor::talk('Running doctor hooks...');
    // Run all doctor hooks.
    $this->env->hook('doctor');
    Doctor::ok();

    print "<b>Doctor has finished curing your environment!</b>";
    die();
  }

  public static function talk($phrase, $color = '#fff') {
    print '<div class="doctor-line" style="background:#000"><div class="doctor-phrase" style="color:' . $color . '">' . $phrase . '</div></div>';
    flush();
  }

  public static function ok($phrase = 'OK') {
    Doctor::talk($phrase, '#0f0');
  }

  public static function ko($phrase = 'KO') {
    Doctor::talk($phrase, '#f00');
  }
}