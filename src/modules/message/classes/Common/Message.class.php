<?php

namespace Quanta\Common;

/**
 * Class Message
 * A Message can be any kind of message to store (log) or to display to the
 * navigating user (screen).
 */
class Message {
  const MESSAGE_NOMODULE = 'unknown';
  const MESSAGE_ERROR = 'error';
  const MESSAGE_WARNING = 'warning';
  const MESSAGE_NOTICE = 'notice';
  const MESSAGE_GENERIC = 'generic';
  const MESSAGE_CONFIRM = 'confirm';
  const MESSAGE_TYPE_LOG = 'log';
  const MESSAGE_TYPE_SCREEN = 'screen';
  const MESSAGE_TYPE_VALIDATION = 'validation';

  const MESSAGE_STYLE_SUCCESS = 'message-alert-success';
  const MESSAGE_STYLE_ALERT = 'message-alert-style';
  const MESSAGE_STYLE_WARNING = 'message-warning-style';
  const MESSAGE_STYLE_INFO = 'message-info-style';

  /** @var Environment $env */
  public $env;
  /** @var string $body */
  public $body;
  /** @var string $module */
  public $module;
  /** @var string $type */
  public $type;
  /** @var string $severity */
  public $severity;
  /** @var string $key */
  public $key;
  /** @var string $style */
  public $style;

  /**
   * Construct the message item.
   *
   * @param Environment $env
   *   The Environment.
   * @param $body
   *   The message's body.
   * @param string $severity
   *   The message's severity.
   * @param string $type
   *   The message's type.
   * @param string $module
   *   The module generating the message.
   */
  public function __construct($env, $body, $severity = self::MESSAGE_GENERIC, $type = self::MESSAGE_TYPE_SCREEN, $module = self::MESSAGE_NOMODULE, $key = null, $style = self::MESSAGE_STYLE_INFO) {
    $this->env = $env;
    $this->body = $body;
    $this->type = $type;
    $this->module = $module;
    $this->severity = $severity;
    $this->key = $key;
    $this->style = $style;
    $doctor = $env->getData('doctor');

    // If the Doctor is curing the environment, show messages in the blackboard.
    if (Doctor::isCuring($env)) {
      switch ($this->severity) {
        case self::MESSAGE_WARNING:
        case self::MESSAGE_ERROR:
          $doctor->ko($this->body);
          break;
        default:
          $doctor->talk($this->body);
          break;
      }
    }
    else {
      $this->env->addData('message', array($this));
      if ($type == self::MESSAGE_TYPE_SCREEN) {
        $isProduction = !empty($this->env->getData('IS_PRODUCTION')) && $this->env->getData('IS_PRODUCTION') === 'true';
        if ($isProduction) {
            // Log the error to Apache/PHP log
            error_log("Error message from quanta: " . $this->body, 0); // Logs to the server error log (Apache/PHP error log)
        } else {
            // Show the error message on screen
            if (!isset($_SESSION['messages'])) {
                $_SESSION['messages'] = [];
            }
          $_SESSION['messages'][] = serialize($this);
        }
      }
      elseif($type == self::MESSAGE_TYPE_VALIDATION){
         if (!isset($_SESSION['messages'])) {
                $_SESSION['messages'] = [];
            }
          $_SESSION['messages'][] = serialize($this);
      }
    }
  }

  /**
   * The properties that go into the session.
   *
   * Everything except $env. The Environment is a constructor argument, used
   * here and nowhere after — burnMessages() reads only body, type, key and
   * style — and serializing it was never merely wasteful:
   *
   *   - it drags the whole environment (class map, data, the node database
   *     instance) into $_SESSION on every screen message, and
   *   - it is *fatal* the moment anything reachable from it refuses to
   *     serialize. FilesDb keeps the exception behind its last swallowed
   *     failure in $last_error, and under the native extension that is a
   *     \QuantaDbException — a PHP-level exception object, which the engine
   *     forbids serializing. So one node-database failure turned every
   *     subsequent `new Message(...)` into an uncaught
   *     "Serialization of 'QuantaDbException' is not allowed", i.e. a warning
   *     the caller wanted logged became a 500.
   *
   * Excluding it costs nothing and removes both problems. A message restored
   * from the session has no $env; nothing reads one.
   *
   * @return array
   */
  public function __sleep() {
    return array('body', 'module', 'type', 'severity', 'key', 'style');
  }

  /**
   * Fetch from the Session all the existing messages of a given type.
   *
   * @param string $type
   *   The message type.
   *
   * @return string
   *   The messages.
   */
  public static function burnMessages($type = self::MESSAGE_TYPE_SCREEN, $for_shadow = false) {
    
    $output = $for_shadow ? [] : '';
    if (isset($_SESSION['messages'])) {
      foreach ($_SESSION['messages'] as $k => $mess) {
        $message = unserialize($mess);
        if ($message->type == $type) {
          if($for_shadow){
            $output[$message->key] = $message->body;
          }
          else{
            $output .= "[HTML_TAG|class={$message->style} message-popup:
                          {$message->body}
                        ]";
          }
          unset($_SESSION['messages'][$k]);
        }
      }
    }
    return $for_shadow ? json_encode($output) : $output;
  }
}