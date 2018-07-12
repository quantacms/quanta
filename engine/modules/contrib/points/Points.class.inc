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
   * @param $env
   * @param User $user
   * @param $type
   * @param $amount
   * @return null
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
   * @param $env
   * @param User $user
   * @param $type
   * @param $amount
   * @return bool|null
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
   * @param $env
   * @param User $user
   * @param $type
   * @param string $show
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