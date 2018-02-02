<?php

/**
 * Class Doctor
 * This class is used to perform .
 */
class Doctor extends DataContainer {
  // @var Environment env
  public $env;

  /**
   * Doctor constructor.
   * @param Environment $env
   */
  public function __construct($env) {
    $this->env = $env;
  }

  /**
   * Verify that all system directories (as defined with sysdir() commands
   * are existing, and create the missing ones.
   */
  public function checkSystemPaths() {
    Doctor::op("Checking system paths.");

    if (!is_dir($this->env->dir['sites'])) {
      Doctor::op('Creating sites directory...');
      mkdir($this->env->dir['sites']);
      Doctor::ok();
    }

    if (!is_dir($this->env->dir['engine'] . '/tmp')) {
      Doctor::op('Creating tmp directory...');
      mkdir($this->env->dir['engine'] . '/tmp');
      Doctor::ok();
    }

    foreach ($this->env->dir as $dirname => $folder) {
      Doctor::op('Checking ' . $dirname . '(<i>' . $folder . ')</i>');
      if (!is_dir($folder) && !is_link($folder)) {
        Doctor::ko('non existing. Creating...');
        mkdir($folder) or die("Error: can not create " . $folder . '. Check permission and path.');
        Doctor::ok();
      }
      else {
        Doctor::ok();
      }
    }
  }

  /**
   * Check & repair any broken links.
   */
  public function checkBrokenLinks() {
    $path = is_link($this->env->dir['docroot']) ? ($_SERVER['DOCUMENT_ROOT'] . '/sites/' . readlink($this->env->dir['docroot'])) : $this->env->dir['docroot'];
    Doctor::op("Searching for all symlinks in " . $path . "...");
    flush();
    $symlinks_find_cmd = "find " . $path . " -type l";
    exec($symlinks_find_cmd, $results_arr);
    Doctor::talk("..." . count($results_arr) . ' found.<br>');
    $wrong_links = 0;
    $fixed_links = 0;
    $unfixable_links = array ();

    // Cycle all symlinks in the system.
    foreach ($results_arr as $k => $link) {
      // Retrieve the link destination.
      $link_target = readlink($link);
      $link_exists = is_dir($link_target);
      $link_split = array_reverse(explode('/', $link_target));
      $link_father = isset($link_split[1]) ? $link_split[1] : NULL;
      $link_name = $link_split[0];
      $link_node = NodeFactory::load($this->env, $link_name);

      // If a link is not corresponding to a dir, attempt to find the real dir.
      if (!$link_exists) {
        $wrong_links++;
        $real_path = $this->env->nodePath($link_name);
        Doctor::op("Looking for " . $link_name . ' (' . $real_path . ')...<br>');

        if (is_dir($real_path)) {
          // Attempt to change the target dir of the link.
          NodeFactory::linkNodes($this->env, $link_node->realpath, $link_father, array ('symlink_name' => $link_name, 'if_exists' => 'override'));
          Doctor::ok("...fixed! :-) <br>");
          $fixed_links++;
        }
        else {
          Doctor::ko("...none found :-( <br>");
          $unfixable_links[] = $link;
        }

      }
      else {
        Doctor::talk($link . ' -> ' . $link_target . '<br><br>');
      }

    }
    Doctor::talk($wrong_links . " wrong links found.");
    if ($fixed_links > 0) {
      Doctor::ok($fixed_links . " wrong links <b style='color: green'>FIXED</b>!<br>");
    }
    if (count($unfixable_links) > 0) {
      Doctor::ko("Could not fix those " . count($unfixable_links) . " wrong links. Maybe they were deleted?");
      Doctor::talk(implode('<br>', $unfixable_links));
    }
  }

  /**
   * Make sure an index.html file exists. If not, create one using the default
   * from the example site.
   */
  public function checkExistingIndex() {
    Doctor::op('Looking for <b>Index...</b><br>');
    if (!is_file($this->env->dir['docroot'] . '/index.html')) {
      Doctor::ko('Not Found');

      $template_folder = $_SERVER['DOCUMENT_ROOT'] . '/_examples/_example2/*';
      // TODO: what to use as default for newly installed sites? Create templates folder.

      // TODO: do only on install hook
      exec('cp -R ' . $template_folder . ' ' . $this->env->dir['docroot']);
      Doctor::ok('Imported from ' . $template_folder);
    }
    else {
      Doctor::ok('Found');
    }

  }

  /**
   * Clear all the system cache.
   */
  public function clearCache() {
    Doctor::op('Clearing Cache');
    Cache::clear($this->env);
    Doctor::ok('Done!');
  }

  /**
   * Check that there is an existing homepage node.
   * If there is not, create a simple one, as this is the
   * default / main page for Quanta sites.
   */
  public function checkExistingHome() {
    Doctor::op('Looking for <b>Home</b> node<br>');
    $homenode = NodeFactory::load($this->env, 'home');
    if (!($homenode->exists)) {
      Doctor::ko('Not found! Attempting to create Homepage...');
      NodeFactory::buildNode($this->env, 'home', '_pages', array (
          'title' => 'Your Homepage',
          'body' => 'Welcome in your new QUANTA Homepage. That\'s where everything begins...',
        )
      );
    }
    else {
      Doctor::ok('Found at ' . $homenode->realpath . '!</b><br>');
    }
  }

  /**
   * Check that the administrator user exists. Create it if it doesn't exist.
   */
  public function checkAdminUser() {
    Doctor::op('Looking for <b>Admin</b> user');
    $adminuser = UserFactory::load($this->env, 'administrator');
    if (!($adminuser->exists)) {
      Doctor::ko('Not found! Attempting to create Admin User...');
      UserFactory::buildUser($this->env, 'administrator', array (
        'title' => 'Administrator',
        'password' => 'pass',
        'roles' => array ('admin'),
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

  /**
   * This functions runs the setup of the site - used at the first install,
   * or whenever something goes seriously wrong with the application settings.
   */
  public function runSetup() {
    // Check that all system paths do exist.
    $this->checkSystemPaths();
    // Clear all cache (for existing applications).
    $this->clearCache();
    Doctor::op('Running setup hooks...');
    // Run all setup hooks.
    $this->env->hook('setup', array ('doctor' => &$this));
    Doctor::ok();
    // TODO: move in modules - out of doctor.
    $this->checkExistingIndex();
    $this->checkExistingHome();
    $this->checkAdminUser();
    $this->runDoctor();
    Doctor::yahoo('<a href="/home">Check your new site now!</a>');
  }

  /**
   * This functions runs the non-setup related doctor tasks
   * as implemented by other modules.
   */
  public function runDoctor() {
    // TODO: move in modules - out of doctor.
    $this->checkBrokenLinks();
    Doctor::op('Running doctor hooks...');
    // Run all doctor hooks.
    $this->env->hook('doctor', array ('doctor' => &$this));
    Doctor::ok();
    Doctor::yahoo('Doctor has finished curing your environment!');
  }

  /**
   * When the doctor says something.
   * @param string $phrase
   * @param string $style
   */
  public static function talk($phrase, $style = 'color:#fff') {
    print '<div class="doctor-line" style="background:#000"><div class="doctor-phrase" style="' . $style . '">' . $phrase . '</div></div>';
    print '<script>window.scrollTo(0,document.body.scrollHeight);</script>';
    flush();
  }

  /**
   * When the doctor starts an operation.
   * @param string $phrase
   */
  public static function op($phrase) {
    Doctor::talk($phrase, 'color:#fff;font-weight:bold;');
  }

  /**
   * When the doctor says "Ok!".
   * @param string $phrase
   */
  public static function ok($phrase = 'OK') {
    Doctor::talk($phrase, 'color:#0f0');
  }

  /**
   * When the doctor says something is going wrong.
   * @param string $phrase
   */
  public static function ko($phrase = 'KO') {
    Doctor::talk($phrase, 'color:#f00');
  }

  /**
   * When the doctor says "Yahoo!".
   * @param string $phrase
   */
  public static function yahoo($phrase = 'OK') {
    Doctor::talk($phrase, 'background:#333;padding:3px;text-transform:uppercase;font-weight:bold;font-size:1.3em;color:#ff3');
  }

  /**
   * When the doctor finishes, and wants to go home.
   *
   * @param string $destination
   *   Where to redirect after doctor finishes running.
   *
   * @param int $delay
   *   Some seconds of delay, just in case...
   */
  public static function redirect($destination, $delay = 5) {
    Doctor::talk('Redirecting you to ' . $destination . ' in ' . $delay . ' seconds...');
    sleep($delay);
    print '<script>window.location.href="' . $destination . '";</script>';
  }

  /**
   * Check if the doctor is curing (if we are in a Doctor page).
   *
   * @return boolean
   *   True if the doctor task is running.
   *
   */
  public static function isCuring() {
    return isset($_GET['doctor']);
  }

  /**
   * After curing, the doctor gives hints to the user on those things
   * that could not be instantly cured.
   */
  public static function recipe() {

  }
}
