<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 02/06/15
 * Time: 10:04
 */

class Domain {
  public $domain;
  public $name;
  public $extension;
  public $expire;
  public $updated;
  public $creation;
  public $expire_date;
  public $updated_date;
  public $creation_date;
  public $email;
  public $whois;
  public $status;

  /**
   * Used for determine if the domain is taken in other extensions.
   */
  public $otherext = array();
  function __construct($domain) {
    $this->domain = strtolower($domain);
    $exp = explode('.', $this->domain);
    $this->name = trim($exp[0]);
    $this->extension = $exp[1];
  }


  public static function generate($regex) {
    $allnames = array();
    preg_match_all('/\[([a-zA-Z0-9\-\,]+)\]/', $regex, $matches);
    foreach ($matches[1] as $match) {
      $commas = explode(',', $match);
      if (count($commas) > 1) {
        $l = $commas;
      }
      elseif (strtolower($match) == $match) {
        $l = array($match => $match);
      }
      else {
        if (!is_file('contrib/domain/collections/' . $match . '.txt')) {
          print "ERR;Wrong lists or characters in your expression: <b>" . $match . "</b>";
          break;
        }
        $l = file('contrib/domain/collections/' . $match . '.txt');
      }
      if (count($allnames) == 0) {
        $allnames = $l;
      } else {
        $newnames = array();
        foreach ($l as $word) {
          foreach ($allnames as $existing) {
            $newnames[] = trim($existing) . trim($word);
          }
        }
        $allnames = $newnames;
      }
    }
    return json_encode(array('domains' => $allnames));
  }

  public function toString() {
    return $this->domain;
  }

  public function saveDB($search_id = 0) {
    mysql_query('delete from domain where domain="' . $this->domain . '"');
    mysql_query('insert into domain values("' . $this->domain . '", "' . $search_id .'", "KO", "' . mysql_real_escape_string($this->whois) . '", "' . $this->expire . '", "' . $this->updated . '", "' . $this->creation . '", "' . $this->email . '", "' . time() . '")') or die ('ERR;' . mysql_error());
  }
} 