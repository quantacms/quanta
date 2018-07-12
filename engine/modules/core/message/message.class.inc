<?php
define('MESSAGE_NOMODULE', 'unknown');
define('MESSAGE_ERROR', 'error');
define('MESSAGE_WARNING', 'warning');
define('MESSAGE_NOTICE', 'notice');
define('MESSAGE_GENERIC', 'generic');
define('MESSAGE_CONFIRM', 'confirm');
define('MESSAGE_TYPE_LOG', 'log');
define('MESSAGE_TYPE_SCREEN', 'screen');

/**
 * Class Message
 * A Message can be any kind of message to store (log) or to display to the
 * navigating user (screen).
 */
class Message {
  public $body;
  public $module;
  public $type;
  /**
   * @var Environment
   */
  public $env;
  public $severity;

  /**
   * @param Environment $env
   * @param $body
   * @param string $severity
   * @param string $type
   * @param string $module
   * @internal param string $text
   */
  public function __construct($env, $body, $severity = MESSAGE_GENERIC, $type = MESSAGE_TYPE_SCREEN, $module = MESSAGE_NOMODULE) {
    $this->env = $env;
    $this->body = $body;
    $this->type = $type;
    $this->module = $module;
    $this->severity = $severity;
    $doctor = $env->getData('doctor');

    // If the Doctor is curing the environment, show messages in the blackboard.
    if (Doctor::isCuring($env)) {

      switch ($this->severity) {
        case MESSAGE_WARNING:
        case MESSAGE_ERROR:
          $doctor->ko($this->body);
          break;
        default:
          $doctor->talk($this->body);
          break;
      }
    }
    else {
      $this->env->addData('message', array($this));
      if ($type == MESSAGE_TYPE_SCREEN) {
        $_SESSION['messages'][] = serialize($this);
      }
    }
  }

  /**
   * @param string $type
   * @return string
   */
  public static function burnMessages($type = MESSAGE_TYPE_SCREEN) {
    $output = '';
    if (isset($_SESSION['messages'])) {
      foreach($_SESSION['messages'] as $k => $mess) {
        $message = unserialize($mess);
        if ($message->type == $type) {
          $output .= '<div class="message message-severity-' . $message->severity . '">' . $message->body . '</div>';
          unset($_SESSION['messages'][$k]);
        }
      }
    }
  return $output;
  }

}