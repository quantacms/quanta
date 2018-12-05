<?php
namespace Quanta\Qtags;
use Quanta\Common\Api;
use Quanta\Common\Message;

/**
 * Allow the setting and retrieving of variables via QTags only.
 */
class Variable extends Qtag {
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

    $this->variable_name = 'variable_' . $this->getTarget();
    $this->value = $this->env->getData($this->variable_name);

    if (!empty($this->getAttribute('set'))) {
      if (!empty($this->value) && ($this->value != $this->getAttribute('set')) && empty($this->getAttribute('override'))) {
        new Message($this->env, t(
          'Warning: the variable !name has been set already. Possible solution: use the override attribute.',
          array('!name' => $this->variable_name)
        ), \Quanta\Common\Message::MESSAGE_WARNING);
      }
      else {
        $this->env->setData($this->variable_name, $this->getAttribute('set'));
      }
    }

    else {
      return $this->env->getData($this->variable_name);
    }
  }
}
