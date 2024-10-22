<?php
namespace Quanta\Common;

/**
 * Class Api.
 * This class contains static utility methods used everywhere throughout the app.
 */
class Api {
  /**
   * Return first occurrence of HTML / XML element in a string.
   *
   * @param $string
   * @param $tag
   * @return string
   */
  public static function parsetag($string, $tag) {
    $pattern = "/<" . $tag . ">(.*?)<\/" . $tag . ">/s";
    preg_match_all($pattern, $string, $matches);
    return isset($matches[1][0]) ? $matches[1][0] : '';
  }

  /**
   * Instant JS redirect to another page.
   *
   * @param string $where
   *   Where to redirect the user.
   */
  public static function redirect($where) {
    print '<script>top.location.href="' . $where . '";</script>';
    exit;
  }

  /**
   * Check if a string is alphanumeric.
   *
   * @param $string
   *   The string.
   *
   * @return boolean
   *   TRUE if the string is alphanumeric.
   */
  public static function is_alphanumeric($string) {
    return preg_match('/^[A-Za-z0-9_]+$/', $string);
  }

  /**
   * Check if a string represents a valid email address.
   *
   * @param $email
   *   The email address to check.
   *
   * @return bool
   *   TRUE if the argument is a valid email address.
   */
  public static function valid_email($email) {
    return (!filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE);
  }

  /**
 * Check if a string represents a valid phone number.
 *
 * @param $phone
 *   The phone number to check.
 *
 * @return bool
 *   TRUE if the argument is a valid phone number.
 */
  public static function valid_phone($phone) {
    if (empty($phone)) {
      return;
    }
    // Define the regex pattern for valid phone numbers
    $pattern = '/^(00|\+)[1-9]\d{1,14}$/';
    // Check if the phone number matches the pattern
    return preg_match($pattern, $phone);
  }

  /**
 * Check if a string represents a strong password.
 *
 * @param $password
 *   The password.
 *
 * @return bool
 *   TRUE if the argument is a strong password.
 */
  public static function valid_password($password) {
    if (empty($password)) {
      return false;
    }
    // Define the regex pattern for a strong password
    //Password must contain at least 1 uppercase letter, 1 special character, 1 number, and be at least 8 characters long.
    $pattern = '/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()-_+=])[A-Za-z\d!@#$%^&*()-_+=]{8,}$/';
    // Check if the password matches the pattern
    return preg_match($pattern, $password);
  }

   /**
   * Check if a captcha response is valid.
   *
   * @param $captcha_response
   * 
   *
   * @return bool
   *   TRUE if the argument is a valid captcha response.
   */
  public static function valid_captcha($env,$captcha_response) {
    $recaptchaSecret = $env->getData('CAPTCHA_SECRET_KEY');
    $response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=$recaptchaSecret&response=$captcha_response");
    $responseKeys = json_decode($response, true);

    if (empty($responseKeys["success"])|| intval($responseKeys["success"]) !== 1) {
      return false;
    } 

    return true;
  }

  /**
   * Check if a string represents a valid URL.
   *
   * @param $url
   *   The URL to check.
   *
   * @return bool
   *   Returns True if the URL is valid.
   */
  public static function valid_url($url) {
    return (!filter_var($url, FILTER_VALIDATE_URL) === FALSE);

  }

  /**
   * Normalize a string by removing all quanta-related characters.
   *
   * @param string $string
   *   The string to normalize.
   *
   * @param bool $nl2br
   *   If true, convert newlines to br in the string.
   *
   * @return string
   *   The normalized string.
   */
  public static function string_normalize($string, $nl2br = FALSE) {
    if (!is_string($string)) {
      return '';
    }
    $string = str_replace('[', '&lbrack;', $string);
    $string = str_replace(']', '&rbrack;', $string);
    $string = str_replace('{', '&lbrace;', $string);
    $string = str_replace('}', '&rbrace;', $string);
    $string = str_replace(':', '&colon;', $string);
    $string = str_replace('|', '&verbar;', $string);

    if (!$nl2br) {
      $string = preg_replace('~[\r\n\t]+~', '', $string);;
    }

    // Remove tabs and newlines.
    // TODO: was necessary as it broke INPUT tags by weirdly printing the value...
    return $string;

  }

  /**
   * Strip all qtags from a string.
   *
   * @param string $string
   *   The string to be stripped.
   *
   * @param array $keep_qtags
   *   Some Qtags that could be kept.
   *
   * @return string
   *   The normalized string.
   */
  public static function strip_qtags($string, $keep_qtags = array()) {
    return preg_replace('/\{.*?\}/', '',  preg_replace('/\[.*?\]/', '', $string));
  }

  /**
   * Normalize a path by replacing all special characters.
   *
   * @param string $s
   *   The string to normalize.
   *
   * @return string
   *   The normalized string.
   */
  public static function normalizePath($s) {
    $s = Api::remove_accents($s);
    $s = strtolower($s);
    $s = str_replace(' ', '-', $s);
    $s = str_replace('_', '-', $s);
    $s = preg_replace("/[^A-Za-z0-9\-]/", '', $s);;

    while (strpos($s, '--') > 0) {
      $s = str_replace('--', '-', $s);
    }
    $s = trim($s, '-');

    return $s;
  }


  /**
   * Normalize a path by replacing all special characters for files.
   *
   * @param string $f
   *   The file to normalize.
   *
   * @return string
   *   The normalized file.
   */
  public static function normalizeFilePath($f) {
    $file_arr = explode('.', $f);
    $file_ext = array_pop($file_arr);
    $file_name = implode('.', $file_arr);
    $file_name = Api::normalizePath($file_name);
    return $file_name . '.' . $file_ext;
  }



  /**
   * Converts all accent characters to ASCII characters.
   *
   * If there are no accent characters, then the string given is just returned.
   *
   * @param string $string
   *   Text that might have accent characters
   *
   * @return string
   *   Filtered string with replaced "nice" characters.
   */
  public static function remove_accents($string) {
    $unwanted_array = array(
      '̊'=>'','̧'=>'','̨'=>'','̄'=>'','̱'=>'','’' => '',
      'Á'=>'a','á'=>'a','À'=>'a','à'=>'a','Ă'=>'a','ă'=>'a','ắ'=>'a','Ắ'=>'A','Ằ'=>'A',
      'ằ'=>'a','ẵ'=>'a','Ẵ'=>'A','ẳ'=>'a','Ẳ'=>'A','Â'=>'a','â'=>'a','ấ'=>'a','Ấ'=>'A',
      'ầ'=>'a','Ầ'=>'a','ẩ'=>'a','Ẩ'=>'A','Ǎ'=>'a','ǎ'=>'a','Å'=>'a','å'=>'a','Ǻ'=>'a',
      'ǻ'=>'a','Ä'=>'a','ä'=>'a','ã'=>'a','Ã'=>'A','Ą'=>'a','ą'=>'a','Ā'=>'a','ā'=>'a',
      'ả'=>'a','Ả'=>'a','Ạ'=>'A','ạ'=>'a','ặ'=>'a','Ặ'=>'A','ậ'=>'a','Ậ'=>'A','Æ'=>'ae',
      'æ'=>'ae','Ǽ'=>'ae','ǽ'=>'ae','ẫ'=>'a','Ẫ'=>'A',
      'Ć'=>'c','ć'=>'c','Ĉ'=>'c','ĉ'=>'c','Č'=>'c','č'=>'c','Ċ'=>'c','ċ'=>'c','Ç'=>'c','ç'=>'c',
      'Ď'=>'d','ď'=>'d','Ḑ'=>'D','ḑ'=>'d','Đ'=>'d','đ'=>'d','Ḍ'=>'D','ḍ'=>'d','Ḏ'=>'D','ḏ'=>'d','ð'=>'d','Ð'=>'D',
      'É'=>'e','é'=>'e','È'=>'e','è'=>'e','Ĕ'=>'e','ĕ'=>'e','ê'=>'e','ế'=>'e','Ế'=>'E','ề'=>'e',
      'Ề'=>'E','Ě'=>'e','ě'=>'e','Ë'=>'e','ë'=>'e','Ė'=>'e','ė'=>'e','Ę'=>'e','ę'=>'e','Ē'=>'e',
      'ē'=>'e','ệ'=>'e','Ệ'=>'E','Ə'=>'e','ə'=>'e','ẽ'=>'e','Ẽ'=>'E','ễ'=>'e',
      'Ễ'=>'E','ể'=>'e','Ể'=>'E','ẻ'=>'e','Ẻ'=>'E','ẹ'=>'e','Ẹ'=>'E',
      'ƒ'=>'f',
      'Ğ'=>'g','ğ'=>'g','Ĝ'=>'g','ĝ'=>'g','Ǧ'=>'G','ǧ'=>'g','Ġ'=>'g','ġ'=>'g','Ģ'=>'g','ģ'=>'g',
      'H̲'=>'H','h̲'=>'h','Ĥ'=>'h','ĥ'=>'h','Ȟ'=>'H','ȟ'=>'h','Ḩ'=>'H','ḩ'=>'h','Ħ'=>'h','ħ'=>'h','Ḥ'=>'H','ḥ'=>'h',
      'Ỉ'=>'I','Í'=>'i','í'=>'i','Ì'=>'i','ì'=>'i','Ĭ'=>'i','ĭ'=>'i','Î'=>'i','î'=>'i','Ǐ'=>'i','ǐ'=>'i',
      'Ï'=>'i','ï'=>'i','Ḯ'=>'I','ḯ'=>'i','Ĩ'=>'i','ĩ'=>'i','İ'=>'i','Į'=>'i','į'=>'i','Ī'=>'i','ī'=>'i',
      'ỉ'=>'I','Ị'=>'I','ị'=>'i','Ĳ'=>'ij','ĳ'=>'ij','ı'=>'i',
      'Ĵ'=>'j','ĵ'=>'j',
      'Ķ'=>'k','ķ'=>'k','Ḵ'=>'K','ḵ'=>'k',
      'Ĺ'=>'l','ĺ'=>'l','Ľ'=>'l','ľ'=>'l','Ļ'=>'l','ļ'=>'l','Ł'=>'l','ł'=>'l','Ŀ'=>'l','ŀ'=>'l',
      'Ń'=>'n','ń'=>'n','Ň'=>'n','ň'=>'n','Ñ'=>'N','ñ'=>'n','Ņ'=>'n','ņ'=>'n','Ṇ'=>'N','ṇ'=>'n','Ŋ'=>'n','ŋ'=>'n',
      'Ó'=>'o','ó'=>'o','Ò'=>'o','ò'=>'o','Ŏ'=>'o','ŏ'=>'o','Ô'=>'o','ô'=>'o','ố'=>'o','Ố'=>'O','ồ'=>'o',
      'Ồ'=>'O','ổ'=>'o','Ổ'=>'O','Ǒ'=>'o','ǒ'=>'o','Ö'=>'o','ö'=>'o','Ő'=>'o','ő'=>'o','Õ'=>'o','õ'=>'o',
      'Ø'=>'o','ø'=>'o','Ǿ'=>'o','ǿ'=>'o','Ǫ'=>'O','ǫ'=>'o','Ǭ'=>'O','ǭ'=>'o','Ō'=>'o','ō'=>'o','ỏ'=>'o',
      'Ỏ'=>'O','Ơ'=>'o','ơ'=>'o','ớ'=>'o','Ớ'=>'O','ờ'=>'o','Ờ'=>'O','ở'=>'o','Ở'=>'O','ợ'=>'o','Ợ'=>'O',
      'ọ'=>'o','Ọ'=>'O','ộ'=>'o','Ộ'=>'O','ỗ'=>'o','Ỗ'=>'O','ỡ'=>'o','Ỡ'=>'O',
      'Œ'=>'oe','œ'=>'oe',
      'ĸ'=>'k',
      'Ŕ'=>'r','ŕ'=>'r','Ř'=>'r','ř'=>'r','ṙ'=>'r','Ŗ'=>'r','ŗ'=>'r','Ṛ'=>'R','ṛ'=>'r','Ṟ'=>'R','ṟ'=>'r',
      'S̲'=>'S','s̲'=>'s','Ś'=>'s','ś'=>'s','Ŝ'=>'s','ŝ'=>'s','Š'=>'s','š'=>'s','Ş'=>'s','ş'=>'s',
      'Ṣ'=>'S','ṣ'=>'s','Ș'=>'S','ș'=>'s',
      'ſ'=>'z','ß'=>'ss','Ť'=>'t','ť'=>'t','Ţ'=>'t','ţ'=>'t','Ṭ'=>'T','ṭ'=>'t','Ț'=>'T',
      'ț'=>'t','Ṯ'=>'T','ṯ'=>'t','™'=>'tm','Ŧ'=>'t','ŧ'=>'t',
      'Ú'=>'u','ú'=>'u','Ù'=>'u','ù'=>'u','Ŭ'=>'u','ŭ'=>'u','Û'=>'u','û'=>'u','Ǔ'=>'u','ǔ'=>'u','Ů'=>'u','ů'=>'u',
      'Ü'=>'u','ü'=>'u','Ǘ'=>'u','ǘ'=>'u','Ǜ'=>'u','ǜ'=>'u','Ǚ'=>'u','ǚ'=>'u','Ǖ'=>'u','ǖ'=>'u','Ű'=>'u','ű'=>'u',
      'Ũ'=>'u','ũ'=>'u','Ų'=>'u','ų'=>'u','Ū'=>'u','ū'=>'u','Ư'=>'u','ư'=>'u','ứ'=>'u','Ứ'=>'U','ừ'=>'u','Ừ'=>'U',
      'ử'=>'u','Ử'=>'U','ự'=>'u','Ự'=>'U','ụ'=>'u','Ụ'=>'U','ủ'=>'u','Ủ'=>'U','ữ'=>'u','Ữ'=>'U',
      'Ŵ'=>'w','ŵ'=>'w',
      'Ý'=>'y','ý'=>'y','ỳ'=>'y','Ỳ'=>'Y','Ŷ'=>'y','ŷ'=>'y','ÿ'=>'y','Ÿ'=>'y','ỹ'=>'y','Ỹ'=>'Y','ỷ'=>'y','Ỷ'=>'Y',
      'Z̲'=>'Z','z̲'=>'z','Ź'=>'z','ź'=>'z','Ž'=>'z','ž'=>'z','Ż'=>'z','ż'=>'z','Ẕ'=>'Z','ẕ'=>'z',
      'þ'=>'p','ŉ'=>'n','А'=>'a','а'=>'a','Б'=>'b','б'=>'b','В'=>'v','в'=>'v','Г'=>'g','г'=>'g','Ґ'=>'g','ґ'=>'g',
      'Д'=>'d','д'=>'d','Е'=>'e','е'=>'e','Ё'=>'jo','ё'=>'jo','Є'=>'e','є'=>'e','Ж'=>'zh','ж'=>'zh','З'=>'z','з'=>'z',
      'И'=>'i','и'=>'i','І'=>'i','і'=>'i','Ї'=>'i','ї'=>'i','Й'=>'j','й'=>'j','К'=>'k','к'=>'k','Л'=>'l','л'=>'l',
      'М'=>'m','м'=>'m','Н'=>'n','н'=>'n','О'=>'o','о'=>'o','П'=>'p','п'=>'p','Р'=>'r','р'=>'r','С'=>'s','с'=>'s',
      'Т'=>'t','т'=>'t','У'=>'u','у'=>'u','Ф'=>'f','ф'=>'f','Х'=>'h','х'=>'h','Ц'=>'c','ц'=>'c','Ч'=>'ch','ч'=>'ch',
      'Ш'=>'sh','ш'=>'sh','Щ'=>'sch','щ'=>'sch','Ъ'=>'-',
      'ъ'=>'-','Ы'=>'y','ы'=>'y','Ь'=>'-','ь'=>'-',
      'Э'=>'je','э'=>'je','Ю'=>'ju','ю'=>'ju','Я'=>'ja','я'=>'ja','א'=>'a','ב'=>'b','ג'=>'g','ד'=>'d','ה'=>'h','ו'=>'v',
      'ז'=>'z','ח'=>'h','ט'=>'t','י'=>'i','ך'=>'k','כ'=>'k','ל'=>'l','ם'=>'m','מ'=>'m','ן'=>'n','נ'=>'n','ס'=>'s','ע'=>'e',
      'ף'=>'p','פ'=>'p','ץ'=>'C','צ'=>'c','ק'=>'q','ר'=>'r','ש'=>'w','ת'=>'t'
    );

    return strtr( $string , $unwanted_array );
  }

  /**
   * Minify a JS / CSS file.
   * //TODO: move elsewhere.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $file
   *   The JS / CSS file.
   *
   * @return string
   *   The minified JS / CSS file.
   */
  public static function minify($env, $file) {
    $exp = explode('.', $file);
    $ext = $exp[count($exp) - 1];
    $command = $env->dir['vendor'] . '/matthiasmullie/minify/bin/minify' . $ext . ' ' . $file;
    exec($command, $arr);
    return $arr;
  }

  /**
   * Filter a string to make it xss-safe.
   *
   * @param string $string
   *   The String to check.
   *
   * @return string
   *   The filtered string.
   */
  public static function filter_xss($string) {
    if ($string != NULL) {
      $filtered_string = htmlspecialchars($string, ENT_QUOTES,'utf-8');
    }
    else {
      $filtered_string = '';
    }
    return $filtered_string;

  }

  /**
   * Get Browser (user agent) info.
   *
   * @return mixed
   *   Array with user agent info.
   */
  public static function getBrowser() {
    $u_agent = !empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : NULL;
    if (empty($u_agent)) {
      return NULL;
    }
    $bname = 'Unknown';
    $platform = 'Unknown';

    //First get the platform?
    if (preg_match('/linux/i', $u_agent)) {
      $platform = 'linux';
    } elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
      $platform = 'mac';
    } elseif (preg_match('/windows|win32/i', $u_agent)) {
      $platform = 'windows';
    }

    // TODO: use an array and a cycle, instead of a long if... elseif queue.
    // Next get the name of the useragent yes seperately and for good reason
    if(preg_match('/MSIE/i',$u_agent) && !preg_match('/Opera/i',$u_agent)) {
      $bname = 'Internet Explorer';
      $ub = "MSIE";
    } elseif(preg_match('/Trident/i',$u_agent)) {
      // this condition is for IE11
      $bname = 'Internet Explorer';
      $ub = "rv";
    } elseif(preg_match('/Firefox/i',$u_agent)) {
      $bname = 'Mozilla Firefox';
      $ub = "Firefox";
    } elseif(preg_match('/Chrome/i',$u_agent)) {
      $bname = 'Google Chrome';
      $ub = "Chrome";
    } elseif(preg_match('/Safari/i',$u_agent)) {
      $bname = 'Apple Safari';
      $ub = "Safari";
    } elseif(preg_match('/Opera/i',$u_agent)) {
      $bname = 'Opera';
      $ub = "Opera";
    } elseif(preg_match('/Netscape/i',$u_agent)) {
      $bname = 'Netscape';
      $ub = "Netscape";
    } else {
      $ub = "Unknown";
    }

    // Finally get the correct version number
    // Added "|:"
    $known = array('Version', $ub, 'other');
    $pattern = '#(?<browser>' . join('|', $known) .
      ')[/|: ]+(?<version>[0-9.|a-zA-Z.]*)#';
    preg_match_all($pattern, $u_agent, $matches);

    // see how many we have
    $i = count($matches['browser']);
    if ($i != 1) {
      //we will have two since we are not using 'other' argument yet
      //see if version is before or after the name
      if (strripos($u_agent,"Version") < strripos($u_agent,$ub)) {
        $version= $matches['version'][0];
      } else {
        $version= $matches['version'][1];
      }
    } else {
      $version= $matches['version'][0];
    }

    // check if we have a number
    if ($version==null || $version=="") {
      $version="?";
    }

    return array(
      'userAgent' => $u_agent,
      'name'      => $bname,
      'version'   => $version,
      'platform'  => $platform,
      'pattern'   => $pattern
    );
  }

  /**
     * Replace elements in an array while preserving excluded elements.
     *
     * This function replaces the elements of the original array with the elements from 
     * a replacement array, but keeps the elements from the original array that are in 
     * the excluded list intact.
     *
     * @param array $original
     *   The original array to be replaced.
     * @param array $replacement
     *   The array that will replace the original array.
     * @param array $excluded
     *   An array of elements that should remain unchanged from the original array.
     *
     * @return array
     *   A new array where the elements from the original array are replaced by the 
     *   replacement array, except for the excluded elements which remain unchanged.
     */
    public static function replace_array_with_exclusions($original, $replacement, $excluded)
    {
        $preservedExcluded = array_filter($original, function ($element) use ($excluded) {
            return in_array($element, $excluded);
        });

        return array_merge($replacement, $preservedExcluded);
    }

}
