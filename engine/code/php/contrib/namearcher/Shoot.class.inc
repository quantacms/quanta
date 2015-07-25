<?php
class Shoot {
  /**
   * @var Environment $env
   */
  private $env;
  /**
   * @var Domain $domain
   */
  private $domain;
  /**
   * @var User $user
   */
  private $user;

  public function __construct($env, $domain, $user) {
    $this->env = $env;
    $this->domain = $domain;
    $this->user = $user;
  }

  /**
   * Attempt a shoot to a domain from the user.
   */
  public function shoot() {

  }
}