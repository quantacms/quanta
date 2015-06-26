<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 02/06/15
 * Time: 10:04
 */

/**
 * Class Points
 *
 */
class Points {

  /**
   * @param User $user
   * @param $type
   * @param $amount
   */
  public static function add($env, $user, $type, $amount) {
    if (!$user->exists) {
      return NULL;
    }
    $points = $user->getData('points');
    $points->{$type}->current += 1;
    $user->setData('points', $points);
    $user->save();
    return $points->{$type}->current;
  }

  /**
   * @param User $user
   * @param $type
   * @param $amount
   */
  public static function remove($env, $user, $type, $amount) {
    if (!$user->exists) {
      return NULL;
    }
    $points = $user->getData('points');
    $points->{$type}->current -= 1;
    if ($points->{$type}->current >= 0) {
      $user->setData('points', $points);
      $user->save();
      return $points->{$type}->current;
    } else {
      return FALSE;
    }
  }

  /**
   * @param User $user
   * @param $type
   * @return mixed
   */
  public static function get($env, $user, $type, $show = 'current') {
    if (!$user->exists) {
      return NULL;
    }
    $points_all = $user->getData('points');
    $points = $points_all->{$type}->{$show};
    return $points;
  }
}