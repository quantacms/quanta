<?php
namespace Quanta\Common;
/**
 * Class AMP.
 * This class manages handling of Google Amp pages in Quanta.
 */

class Amp {
  /**
   * Check if the current page is in amp.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return bool
   *   True if we are in an AMP context.
   */
  public static function isActive(&$env) {
    return $env->request[1] == 'amp';
  }
}
