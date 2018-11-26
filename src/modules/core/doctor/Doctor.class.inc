<?php
namespace Quanta\Common;

define('WEB_SERVER_APACHE', 'apache');
define('WEB_SERVER_NGINX', 'nginx');

define('DOCTOR_MODE_BASH', 'bash');
define('DOCTOR_MODE_BROWSER', 'browser');
define('DOCTOR_RECIPE', 'doctor_recipe.txt');

/**
 * Class Doctor
 * This class is used to perform .
 */
class Doctor extends DataContainer {
  // @var Environment env
  public $env;
  /**
   * @var string $unix_user
   *   The unix user php / doctor are running at.
   */
  public $unix_user;

  /**
   * @var string $web_server_user
   *   The web server type.
   */
  public $web_server_type;

  /**
   * @var string $web_server_user
   *   The web server user.
   */
  public $web_server_user;

  /**
   * @var string $mode
   *   Specifies if Doctor is running via bash or browser.
   */
  public $mode;

  /**
   * @var string $command
   *   The command given to the doctor.
   */
  public $command;

  /**
   * @var string $args
   *   The args passed to the doctor.
   */
  public $args;

  /**
   * Doctor constructor.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $command
   *   The doctor command being ran.
   *
   * @param string $mode
   *   The doctor mode (Bash by default).
   */
  public function __construct($env, $command, $doctor_args = array(), $mode = DOCTOR_MODE_BASH) {
    $this->env = $env;
    $this->mode = $mode;
    $this->command = $command;
    // Load command line parameters starting with --.

    foreach ($doctor_args as $doctor_arg) {
      $doctor_arg_expl = explode('=', $doctor_arg);
      if (substr($doctor_arg_expl[0], 0, 2) == '--') {
        $doctor_arg_name = substr($doctor_arg_expl[0], 2);
        $doctor_arg_val = ((count($doctor_arg_expl) == 0) ? $doctor_arg_name : $doctor_arg_expl[1]);
        $this->setData($doctor_arg_name, $doctor_arg_val);
      }
    }

    $this->env->setData('doctor', $this);
  }

  /**
   * Verify that all system directories (as defined with sysdir() commands
   * are existing, and create the missing ones.
   */
  public function checkSystemPaths() {
    $this->op("Checking system paths.");

    if (!is_dir($this->env->dir['sites'])) {
      $this->op('Creating sites directory...');
      $this->createDir($this->env->dir['sites']);
      $this->ok();
    }


    if (!is_dir($this->env->dir['docroot'])) {
      $this->op('Creating docroot directory...');
      $this->createDir($this->env->dir['docroot']);
      $this->ok();
    }

    if (!is_dir($this->env->dir['tmp'])) {
      $this->op('Creating tmp directory...');
      $this->createDir($this->env->dir['tmp']);
      $this->ok();
    }

    foreach ($this->env->dir as $dirname => $folder) {
      $this->op('Checking ' . $dirname . '(' . $folder . ')');
      if (!is_dir($folder) && !is_link($folder)) {
        $this->talk('non existing. Creating...');
        $this->createDir($folder);
        $this->ok();
      }
      else {
        $this->ok();
      }
    }
  }

  /**
   * Create a Directory.
   *
   * @param string $dir
   *   The directory.
   *
   */
  public function createDir($dir) {
    $dir_exp = explode('/', $dir);
    unset($dir_exp[count($dir_exp) - 1]);
    $father_dir = implode('/', $dir_exp);
    if (!is_writable($father_dir)) {
      $this->stop(
        t("Sorry, we can't continue with the setup because the current user has no privileges to create directories in: %father_dir.\nSuggested solution (unix): chown -R www-data %dir",
        array('%father_dir' => $father_dir, '%dir' => $dir)));
    }
    else {
      mkdir($dir, 0755, TRUE) or die('Fatal error - could not create directory ' . $dir . ' and something went wrong in checking its permissions. Aborting.');
    }
  }

  /**
   * Execute an UNIX command.
   *
   * @param string $command
   *   The command to run.
   *
   * @return mixed
   *   The results of the command.
   */
  public function execute($command) {
    exec($command, $results_arr);
    return $results_arr;
  }

  /**
   * Check & repair any broken links.
   */
  public function checkBrokenLinks() {
    $path = is_link($this->env->dir['docroot']) ? ($this->env->dir['sites'] . '/' . readlink($this->env->dir['docroot'])) : $this->env->dir['docroot'];
    $this->op("Searching for all symlinks in " . $path . "...");
    flush();
    $symlinks_find_cmd = "find " . $path . " -type l";
    $results_arr = $this->execute($symlinks_find_cmd);
    $this->talk("..." . count($results_arr) . ' found.');
    $good_links = 0;
    $wrong_links = 0;
    $fixed_links = 0;
    $unfixable_links = array();

    // Cycle all symlinks in the system.
    foreach ($results_arr as $k => $link) {
      // Retrieve the link destination.
      $link_target = readlink($link);
      $link_exists = is_dir($link_target);
      $link_split = array_reverse(explode('/', $link));
      $link_father = isset($link_split[0]) ? $link_split[1] : NULL;
      $link_name = $link_split[0];

      // If a link is not corresponding to a dir, attempt to find the real dir.
      if (!$link_exists) {
        $wrong_links++;
        $real_path = $this->env->nodePath($link_name);
        $this->ko("Missing link found (" . $link_target . ")");

        if (is_dir($real_path)) {

          $this->op("Linking " . $link_name . " (" . $real_path . ") into " . $link . "...");
          // Attempt to change the target dir of the link.
          if (NodeFactory::linkNodes($this->env, $link_name, $link_father, array('symlink_name' => $link_name, 'if_exists' => 'override'))) {
            $this->ok("...fixed! :-)");
            $fixed_links++;
          }
          else {
            $this->ko("...could not be fixed! :-(");
          }
        }
        else {
          $this->ko("...no alternative target found :-(");
          $unfixable_links[] = $link;
        }

      }
      else {
        $good_links++;
      }

    }

    $this->talk($good_links + $wrong_links . ' total links found.');
    if ($wrong_links > 0) {
      $this->talk($wrong_links . " wrong links found.");
    }
    if ($fixed_links > 0) {
      $this->ok($fixed_links . " wrong links FIXED!");
    }
    if (count($unfixable_links) > 0) {
      $this->ko("Could not fix those " . count($unfixable_links) . " wrong links. Maybe they were deleted?");
      $this->talk(implode("\n", $unfixable_links));
    }
  }

  /**
   * Make sure an index.html file exists. If not, create one using the default
   * from the example site.
   */
  public function checkExistingIndex() {

  }

  /**
   * Check the current user.
   */
  public function checkCurrentUnixUser() {
    $this->op('Checking current UNIX user...');
    $symlinks_find_cmd = "whoami";
    $results_arr = $this->execute($symlinks_find_cmd);
    $this->unix_user = array_pop($results_arr);
    if (empty($this->unix_user)) {
      $this->stop('Doctor could not retrieve your current user (using command: ' . $symlinks_find_cmd . '). Aborting');
    }
  }

  /**
   * Check the Server modules.
   */
  public function checkWebServerModules() {
    // We don't need to check modules for nginx.
    if ($this->web_server_type == WEB_SERVER_APACHE) {

      $this->op('Checking Web Server modules (Apache only)...');

      $modules = array(
        'rewrite',
        'headers',
      );
      $missing_modules = array();
      foreach ($modules as $module) {
        // Checking if modules are enabled...
        $check = $this->execute('apachectl -M | grep "' . $module . '_module"');
        $this->talk($module . '...');
        if (empty($check)) {
          $missing_modules[] = $module;
          $this->ko('not found!');
        }
        else {
          $this->ok('found!');
        }
      }
      // If there are missing apache modules, we have to block the process.
      if (!empty($missing_modules)) {
        $this->ko(array('You can not install Quanta without those apache modules: ' . implode(', ', $missing_modules)));
        $this->ko(array('Hint: on apache2, try those commands:'));
        foreach ($missing_modules as $missing_module) {
          $this->talk('a2enmod ' . $missing_module);
        }
        $this->stop('Aborting.');
      }
    }
  }

  /**
   * Check the webserver type(Apache or Nginx).
   */
  public function checkWebServerType() {
    if (!empty($this->getData('web-server-type'))) {
      $this->web_server_type = $this->getData('web-server-type');
      $this->talk('Pre-selecting server type...' . $this->web_server_type);
      // Pre-selected using --web-server-type parameter. Must be apache or nginx.
      if (($this->web_server_type != WEB_SERVER_NGINX) && ($this->web_server_type != WEB_SERVER_APACHE)) {
        $this->ko('You pre-selected an invalid web-server type: @type. Please either use "' . WEB_SERVER_NGINX . '"" or "' . WEB_SERVER_APACHE . '"');
        $this->stop('Aborting the install');
      }
      else {
        $this->ok('Pre-selecting server: ' . $this->web_server_type);
      }
      return;
    }

    // TODO: better procedural approach without either "return" or "if... else".
    $this->web_server_type = $this->ask("Which web server type are you using?\n" .
      WEB_SERVER_APACHE . ": Apache\n" .
      WEB_SERVER_NGINX . ": nginx\n",
      NULL,
      array(WEB_SERVER_APACHE, WEB_SERVER_NGINX)
    );

  }

  /**
   * Check the web Server user.
   *
   * Doctor will try to automatically fetch the apache username. If impossible, it will ask the client to provide one name.
   */
  public function checkWebServerUser() {
    if (!empty($this->getData('web-server-type'))) {
      $this->web_server_user = $this->getData('web-server-user');
      $this->ok('Pre-selecting Web Server user: ' . $this->web_server_user);
      return;
    }

    $this->op('Checking Web Server User...');

    $candidate_users = array(
      'www-data',
      'nobody',
      'apache2',
      'nginx',
    );

    foreach ($candidate_users as $candidate_user) {
      $results_arr = $this->execute('id -u ' . $candidate_user);
      if (!empty($results_arr)) {
        $this->web_server_user = $candidate_user;
        break;
      }
    }

    if (!empty($this->web_server_user)) {
      $this->talk('Web Server user detected: ' . $this->web_server_user);
      $is_web_server_user_ok = str_replace("\n", "", $this->ask('Please press enter if OK, or enter your webserver (Apache or nginx) user name here: '));
    }
    else {
      $this->talk('We could not detect a web server username.');
      $is_web_server_user_ok = $this->ask('Please enter your web server username (i.e. www-data):');
    }
    if (!empty($is_web_server_user_ok)) {
      $this->web_server_user = $is_web_server_user_ok;
    }
  }


  /**
   * If current user is not the same as web server user, manual chown commands have to be run
   * in order for Web Server to be able to access Quanta folders.
   */
  function checkWebServerVsUnixUser() {
    if ($this->web_server_user != $this->unix_user) {
      $this->op('IMPORTANT: as the current user (' . $this->unix_user . ') is different from your webserver user (' . $this->web_server_user . '), in order to allow your WebServer accessing those folders and use Quanta, you have to run this command manually:');
      $this->op('sudo chown -R ' . $this->web_server_user . ' "' . $this->env->dir['docroot'] . '"; sudo chown -R ' . $this->web_server_user . ' "' . $this->env->dir['tmp'] . '"');
    }
  }

  /**
   * When the doctor needs to know something...
   *
   * @param string $phrase
   *   What's the question from the doctor
   *
   * @param bool $hidden
   *   If you want a hidden input (i.e. for password input) set it to TRUE.
   *
   * @return string
   *   The answer
   */
  public function ask($phrase, $hidden = FALSE, $values = array()) {
    // TODO: display choice values.
    $allowed_values = array_flip($values);
    $this->talk($phrase);
    if ($hidden) {
      system('stty -echo');
    }
    $answer = str_replace("\n", "", fgets(STDIN));
    if ($hidden) {
      system('stty echo');
    }

    if (!empty($values) && !isset($allowed_values[$answer])) {
      $this->ko('Invalid choice: ' . $answer . var_export($values));
      $answer = $this->ask($phrase, $hidden, $values);
    }
    return $answer;
  }

  /**
   * This function runs the doctor tasks via hooks, as implemented by other modules.
   *
   * @param string $command
   *   The doctor command being ran.
   */
  public function cure($command = NULL) {
    // Run a custom command, or use the one specified in the command line.
    if (empty($command)) {
      $command = $this->command;
    }
    $doctor_hook_name = ($command == 'check') ? 'doctor' : 'doctor_' . $command;
    $this->op('Running doctor hooks:  ' . $doctor_hook_name . '(...)');
    $vars = array('doctor' => &$this);
    // Run pre setup.
    if ($doctor_hook_name == 'doctor_setup') {
      $this->env->hook('doctor_pre_setup', $vars);
    }
    if (!($this->env->hook(str_replace('-', '_', $doctor_hook_name), $vars))) {
      $this->ko("Wrong doctor command. Available doctor commands are: check, setup, clear-cache (i.e. doctor mysite.com check)\n");
    }


    $fop = fopen($this->env->dir['tmp'] . '/' . DOCTOR_RECIPE, 'w+');
    // TODO: what to write in the recipe?
    fwrite($fop, 'work in progress!');
    fclose($fop);
  }

  /**
   * When the doctor says something.
   *
   * @param string $phrase
   *   The message of the doctor.
   *
   * @param mixed $style
   *   The visual style of the message.
   */
  public function talk($phrase, $style = array('bash-color' => NULL, 'browser-style' => 'color:#fff')) {
    // Support arrays for multiline phrases.
    if (is_array($phrase)) {
      $phrase = implode("\n", $phrase);
    }
    switch ($this->mode) {
      case DOCTOR_MODE_BASH:
        print "\033[" . $style['bash-color'] . 'm' . $phrase . "\033[0m" . "\n";
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
   *
   * @param string $phrase
   *   The operation being performed by the doctor.
   */
  public function op($phrase) {
    $this->talk($phrase, array('browser-style' => 'color:#fff;font-weight:bold;', 'bash-color' => '1;33'));
  }


  /**
   * When the doctor says "no no!".
   *
   * @param string $phrase
   *   The stopping phrase of the doctor.
   */
  public function stop($phrase) {
    $this->ko($phrase);
    $this->talk('Doctor could not complete the visit. Please fix the above errors and run doctor again!', array('browser-style' => 'color:#f00;font-weight:bold;', 'bash-color' => '1;31'));
    die();
  }

  /**
   * When the doctor says "Ok!".
   *
   * @param string $phrase
   *   The OK phrase of the doctor.
   */
  public function ok($phrase = 'OK') {
    $this->talk($phrase, array('browser-style' => 'color:#0f0', 'bash-color' => '0;32'));
  }

  /**
   * When the doctor says something is going wrong.
   *
   * @param string $phrase
   *   The KO phrase of the doctor.
   */
  public function ko($phrase = 'KO') {
    $this->talk($phrase, array('browser-style' => 'color:#f00', 'bash-color' => '0;31'));
  }

  /**
   * When the doctor says "Yahoo!".
   *
   * @param string $phrase
   *   The enthusiastic phrase of the doctor.
   */
  public function yahoo($phrase = 'OK') {
    $this->talk($phrase, array('browser-style' => 'background:#333;padding:3px;text-transform:uppercase;font-weight:bold;font-size:1.3em;color:#ff3', 'bash-color' => '0;42'));
  }

  /**
   * Check if the doctor is curing (if we are in a Doctor page).
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return boolean
   *   True if the doctor task is running.
   *
   */
  public static function isCuring($env) {
    return !empty($env->getData('doctor'));
  }

  /**
   * After curing, the doctor gives hints to the user on those things
   * that could not be instantly cured.
   */
  public function recipe() {
    // TODO.
  }

  /**
   * After curing, the doctor goes home.
   */
  public function goHome() {
    $this->yahoo('Doctor has finished curing your environment!');
  }

  /**
   * Return the timestamp of the last doctor run.
   *
   * @param $env
   *   The Environment.
   *
   * @return int
   *   The timestamp of the last doctor run.
   */
  public static function timestamp($env) {
	  return filemtime($env->dir['tmp'] . '/' . DOCTOR_RECIPE);
	}

}
