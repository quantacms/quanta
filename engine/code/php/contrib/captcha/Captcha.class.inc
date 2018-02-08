<?php

define("CAPTCHA_MATH", 'math');

/**
 * Class Captcha
 */
class Captcha {
  private $env;
  public $challenge_type;

  public $math_index;
  public $math_string;
  public $math_n1;
  public $math_n2;
  /**
   * Used for determine if the user is human.
   */
  public $otherext = array();

  /**
   * Captcha constructor.
   * @param $env
   * @param $challenge_type
   */
  function __construct($env, $challenge_type) {
    $this->env = $env;
    $this->challenge_type = $challenge_type;
    $this->math_index = rand(2, 9);
    $this->math_n1 = rand(1, 4);
    $this->math_n2 = rand(1, 5);

    $this->math_string = $this->math_index;
    $md5 = md5(rand(0,10000));

    for ($i = 0; $i < $this->math_index; $i++) {
      $this->math_string .= substr($md5, $i , 1);
    }
    $this->math_string .= Captcha::shuffle_convert($this->math_n1 + $this->math_n2);
    for ($q = 0; $q < 10; $q++) {
      $i++;
      $this->math_string .= substr($md5, $i , 1);
    }
    // TODO: Finish

  }

  /** Todo: temporary ugly - still useful */
  public function render() {
    return '<div class="captcha">
    <input type="hidden" name="edit-captcha" id="edit-captcha" value="' . $this->math_string . '" />
    <div class="captcha-challenge"><span class="captcha-desc">Easy math question:</span> ' .
    '<b>' . $this->math_n1 . '</b> + <b>' . $this->math_n2 . '</b> = '
    . '<input type="text" name="edit-captcha_math" name="captcha_math" size ="2" /></div>
    <div class="description">Used to prove you are human</div>';
  }

  /**
   * @param $number
   * @return bool|mixed
   */
  public static function shuffle_convert($number) {
    $captcha = array(1 => 'q', 2 => 'r', 3 => '9', 4 => 'y', 5 => '6', 6 => 'i', 7 => '?', 8 => '1', 9 => 't', 0 => '$');
    return isset($captcha[$number]) ? $captcha[$number] : FALSE;
  }

  /**
   * Validate the captcha challenge.
   * @param $captcha
   * @param $input
   * @return bool
   */
  public static function validate($captcha, $input) {
    $pos = intval(substr($captcha, 0 , 1))+1;
    $challenge_char = substr($captcha, $pos, 1);
  /**  print "CAPTCHA:" . $captcha;
    print "INPUT:" . $input;
    print "SHUFFLED:" . Captcha::shuffle_convert($input);
    print "POS:" . $pos;
    print "CHAR:" . $challenge_char;
*/
    return $challenge_char == Captcha::shuffle_convert($input);
  }
}