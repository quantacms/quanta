<?php
namespace Quanta\Qtags;
use Quanta\Common\Api;
use Quanta\Common\Message;

/**
 * Allow the setting and retrieving of variables via QTags only.
 */
class Session extends Qtag {
  protected $variable_name;
  protected $value;
  /**
   * Render the Qtag.
   *
   * @return text
   *   The rendered Qtag.
   */
  public function render() {
    // We always need to specify the variable name.
    if (empty(Api::string_normalize(strip_tags($this->getTarget())))) {
      return '';
    }

    $this->variable_name = $this->getTarget();
    $this->value = $_SESSION[$this->getTarget()];

    if (!empty($this->getAttribute('set'))) {
        $_SESSION[$this->variable_name] = $this->getAttribute('set');
    }
    else {
      return $_SESSION[$this->getTarget()];
    }
  }
}
