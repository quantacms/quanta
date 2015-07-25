<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 02/06/15
 * Time: 10:04
 */
class NameArcher {


  /**
   * Which level corresponds to a specific exp
   * @param $exp
   * @return int
   */
  public static function getLevel($exp) {
    if ($exp <= 100) {
      return 1;
    }
    $level = intval(log(intval($exp / 100), 2)) + 1;
    return $level;
  }

  /**
   * How much experience is needed for next level.
   * @param $level
   * @return int|number
   */
  public static function expToLevel($level) {
    if ($level < 2) {
      return 0;
    }
    $need = pow(2, $level - 1) * 100;
    return ($need);
  }

  /**
   * Percentage to next level
   * @param $exp
   * @param $level
   * @return int
   */
  public static function percToLevel($exp, $level) {
    $prev = NameArcher::expToLevel($level - 1);
    $need = NameArcher::expToLevel($level);
    return intval(($exp - $prev) / ($need - $prev) * 100);
  }

}