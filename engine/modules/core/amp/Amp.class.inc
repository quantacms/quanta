<?php
/**
 * Class AMP.
 * This class manages handling of Google Amp pages in Quanta.
 */

/**
 * Class
 */
class Amp {
  /**
   * Check if the current page is in amp.
   * @param $env
   */
  public static function isActive(&$env) {
    return $env->request[1] == 'amp';
  }
}
