<?php
namespace Quanta\Common;

/**
 * Class Access
 * This abstract class is representing an Access check from some
 * user, to some specific action, into some specific node.
 *
 *
 * @see NodeAccess
 * @see UserAccess
 */
abstract class Access implements AccessChecker {
  /**
   * @var Environment $env
   */
  protected $env;
  /**
   * @var Node $node
   */
  protected $node;
  /**
   * @var User $actor
   */
  protected $actor;

  /**
   * @var string $action
   */
  protected $action;

  /**
   * @var string $action
   */
  protected $vars;
  /**
   * Constructs an access object.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $action
   *   The action for which the access is being check.
   *
   * @param array $vars
   *   Mixed variables.
   */
  public function __construct(Environment &$env, $action, array $vars) {
    $this->env = $env;
    $this->actor = isset($vars['user']) ? $vars['user'] : UserFactory::current($env);
    $this->node = isset($vars['node']) ? $vars['node'] : NodeFactory::current($env);
    $this->action = trim(strtolower($action));
    $this->vars = $vars;
  }

  /**
   * Sets the action related to this access object.
   *
   * @param string $action
   *   The action.
   */
  public function setAction($action) {
    $this->action = $action;
  }

  /**
   * Gets the action related to this access object.
   *
   * @return string
   *   The action.
   */
  public function getAction() {
    return $this->action;
  }

  /**
   * Checks access to an action.
   */
  public abstract function checkAction();

}
