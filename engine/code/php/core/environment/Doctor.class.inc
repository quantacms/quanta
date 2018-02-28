<?php
define('DOCTOR_MODE_BASH', 'bash');
define('DOCTOR_MODE_BROWSER', 'browser');

/**
 * Class Doctor
 * This class is used to perform .
 */
class Doctor extends DataContainer {
  // @var Environment env
  public $env;
  /**
   * @var string $mode
   *   Specifies if Doctor is running via bash or browser.
   */
  public $mode;
  /**
   * Doctor constructor.
   *
   * @param Environment $env
   *   The Environment.
   */
  public function __construct($env, $mode = DOCTOR_MODE_BASH) {
    $this->env = $env;
    $this->mode = $mode;
  }

  /**
   * Verify that all system directories (as defined with sysdir() commands
   * are existing, and create the missing ones.
   */
  public function checkSystemPaths() {
    $this->op("Checking system paths.");

    if (!is_dir($this->env->dir['sites'])) {
      $this->op('Creating sites directory...');
      mkdir($this->env->dir['sites']);
      $this->ok();
    }

    if (!is_dir($this->env->dir['engine'] . '/tmp')) {
      $this->op('Creating tmp directory...');
      mkdir($this->env->dir['engine'] . '/tmp');
      $this->ok();
    }

    foreach ($this->env->dir as $dirname => $folder) {
      $this->op('Checking ' . $dirname . '(' . $folder . ')');
      if (!is_dir($folder) && !is_link($folder)) {
        $this->ko('non existing. Creating...');
        mkdir($folder) or die("Error: can not create " . $folder . '. Check permission and path.');
        $this->ok();
      }
      else {
        $this->ok();
      }
    }
  }

  /**
   * Check & repair any broken links.
   */
  public function checkBrokenLinks() {
    $path = is_link($this->env->dir['docroot']) ? ($this->env->dir['sites'] . '/' . readlink($this->env->dir['docroot'])) : $this->env->dir['docroot'];
    $this->op("Searching for all symlinks in " . $path . "...");
    flush();
    $symlinks_find_cmd = "find " . $path . " -type l";
    exec($symlinks_find_cmd, $results_arr);
    $this->talk("..." . count($results_arr) . ' found.');
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
        $this->op("Looking for " . $link_name . ' (' . $real_path . ')...');

        if (is_dir($real_path)) {
          // Attempt to change the target dir of the link.
          NodeFactory::linkNodes($this->env, $link_node->realpath, $link_father, array ('symlink_name' => $link_name, 'if_exists' => 'override'));
          $this->ok("...fixed! :-)");
          $fixed_links++;
        }
        else {
          $this->ko("...none found :-(");
          $unfixable_links[] = $link;
        }

      }
      else {
        $this->talk($link . ' -> ' . $link_target);
      }

    }
    $this->talk($wrong_links . " wrong links found.");
    if ($fixed_links > 0) {
      $this->ok($fixed_links . " wrong links FIXED!");
    }
    if (count($unfixable_links) > 0) {
      $this->ko("Could not fix those " . count($unfixable_links) . " wrong links. Maybe they were deleted?");
      $this->talk(implode('\n', $unfixable_links));
    }
  }

  /**
   * Make sure an index.html file exists. If not, create one using the default
   * from the example site.
   */
  public function checkExistingIndex() {
    $this->op('Looking for index.html...');
    if (!is_file($this->env->dir['docroot'] . '/index.html')) {
      $this->ko('Not Found');

      $template_folder = $_SERVER['DOCUMENT_ROOT'] . '/_examples/_example2/*';
      // TODO: what to use as default for newly installed sites? Create templates folder.

      // TODO: do only on install hook
      exec('cp -R ' . $template_folder . ' ' . $this->env->dir['docroot']);
      $this->ok('Imported from ' . $template_folder);
    }
    else {
      $this->ok('Found');
    }

  }

  /**
   * Clear all the system cache.
   */
  public function clearCache() {
    $this->op('Clearing Cache');
    Cache::clear($this->env);
    $this->ok('Done!');
  }

  /**
   * Check that there is an existing homepage node.
   * If there is not, create a simple one, as this is the
   * default / main page for Quanta sites.
   */
  public function checkExistingHome() {
    $this->op('Looking for Home node');
    $homenode = NodeFactory::load($this->env, 'home');
    if (!($homenode->exists)) {
      $this->ko('Not found! Attempting to create Homepage...');
      NodeFactory::buildNode($this->env, 'home', '_pages', array (
          'title' => 'Your Homepage',
          'body' => 'Welcome in your new QUANTA Homepage. That\'s where everything begins...',
        )
      );
    }
    else {
      $this->ok('Found at ' . $homenode->realpath . '!');
    }
  }

  /**
   * Check that the administrator user exists. Create it if it doesn't exist.
   */
  public function checkAdminUser() {
    $this->op('Looking for Admin user');
    $adminuser = UserFactory::load($this->env, 'administrator');
    if (!($adminuser->exists)) {
      $this->ko('Not found! Attempting to create Admin User...');
      UserFactory::buildUser($this->env, 'administrator', array (
        'title' => 'Administrator',
        'password' => 'pass',
        'roles' => array ('admin'),
        'email' => 'admin@changeme.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
      ));
      $this->ok('Done!');
    }
    else {
      $this->ok('Found!');
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
    $this->op('Clearing cache...');
    $this->clearCache();
    $this->ok('Done!');

    $this->op('Running setup hooks...');
    // Run all setup hooks.
    $vars = array ('doctor' => &$this);
    $this->env->hook('setup', $vars);
    $this->ok();
    // TODO: move in modules - out of doctor.
    $this->checkExistingIndex();
    $this->checkExistingHome();
    $this->checkAdminUser();
    $this->runDoctor();
    if ($this->mode == DOCTOR_MODE_BROWSER) {
      $this->yahoo(qtag_LINK($this->env, 'home', array('title' => 'Check your new site now!')));
    }
  }

  /**
   * This functions runs the non-setup related doctor tasks
   * as implemented by other modules.
   */
  public function runDoctor() {
    // TODO: move in modules - out of doctor.
    $this->checkBrokenLinks();
    $this->op('Running doctor hooks...');
    // Run all doctor hooks.
    $vars = array ('doctor' => &$this);
    $this->env->hook('doctor', $vars);
    $this->ok();
    $this->yahoo('Doctor has finished curing your environment!');
  }

  /**
   * When the doctor says something.
   * @param string $phrase
   * @param string $style
   */
  public function talk($phrase, $style = array('bash-color' => '0;37', 'browser-style' => 'color:#fff')) {

    switch ($this->mode) {
      case DOCTOR_MODE_BASH:
        print "\033[" . $style['bash-color'] .'m' . $phrase . "\033[0m" . "\n";
        break;

      case DOCTOR_MODE_BROWSER:
        print '<div class="doctor-line" style="background:#000"><div class="doctor-phrase" style="' . $style['browser-style'] . '">' . $phrase . '</div></div>';
        print '<script>window.scrollTo(0,document.body.scrollHeight);</script>';
        break;
    }
    flush();
  }

  /**
   * When the doctor starts an operation.
   * @param string $phrase
   */
  public function op($phrase) {
    $this->talk($phrase, array('browser-style' => 'color:#fff;font-weight:bold;', 'bash-color' => '1;33'));
  }

  /**
   * When the doctor says "Ok!".
   * @param string $phrase
   */
  public function ok($phrase = 'OK') {
    $this->talk($phrase, array('browser-style' => 'color:#0f0', 'bash-color' => '0;32'));
  }

  /**
   * When the doctor says something is going wrong.
   * @param string $phrase
   */
  public function ko($phrase = 'KO') {
    $this->talk($phrase, array('browser-style' => 'color:#f00', 'bash-color' => '0;31'));
  }

  /**
   * When the doctor says "Yahoo!".
   * @param string $phrase
   */
  public function yahoo($phrase = 'OK') {
    $this->talk($phrase, array('browser-style' => 'background:#333;padding:3px;text-transform:uppercase;font-weight:bold;font-size:1.3em;color:#ff3', 'bash-color' => '0;42'));
  }

  /**
   * @deprecated
   *
   * When the doctor finishes, and wants to go home.
   *
   * @param string $destination
   *   Where to redirect after doctor finishes running.
   *
   * @param int $delay
   *   Some seconds of delay, just in case...
   */
  public function redirect($destination, $delay = 5) {
    $this->talk('Redirecting you to ' . $destination . ' in ' . $delay . ' seconds...');
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
