<?php

/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 02/06/15
 * Time: 10:07
 */
class DomainSearch {
  public $domain;
  /**
   * @var User
   */
  public $user;
  public $result;
  public $search_id;
  /**
   * @var Environment
   */
  public $env;

  public function __construct($env, $domain, $user) {
    $this->env = $env;
    $this->user = $user;
    $this->domain = new Domain($domain);

    if (!$this->searchLocalDB()) {
      $this->search();
    }

    $this->saveDB();
    $this->checkOtherExt('net');

    if ($this->domain->status == 'KO') {
      $this->domain->saveDB($this->search_id);
    }

  }



  public function searchLocalDB() {
    // Check if domain is in database...
    $check_exists = mysql_query('select * from domain where domain = "' . $this->domain->toString() . '" and expire_date > ' . (time() + (60 * 24 * 7))) or die(mysql_error());
    // Domain is already in database
    if ($dn = mysql_fetch_array($check_exists)) {
      // TODO: normalize var names
      $this->domain->expire = $dn['expire_date'];
      $this->domain->updated = $dn['update_date'];
      $this->domain->creation = $dn['creation_date'];

      $this->domain->expire_date = date('Y-m-d', $dn['expire_date']);
      $this->domain->updated_date = date('Y-m-d', $dn['update_date']);
      $this->domain->creation_date = date('Y-m-d', $dn['creation_date']);
      $this->domain->status = 'KO';
    }
  }

  public function search() {

    $nslist = array('-h whois.verisign-grs.com', '', '-h rs.internic.net');
    $nslist['com'] = array('-h whois.verisign-grs.com');
    $nslist['net'] = array('-h whois.verisign-grs.com');

    $rand = rand(0, (count($nslist[$this->domain->extension]) -1));
    exec('whois ' . $nslist[$this->domain->extension][$rand] . ' ' . $this->domain->toString(), $output);
    $cont = str_replace('<', '', str_replace('\\', '', htmlspecialchars_decode(html_entity_decode(implode($output)))));
    $available = strpos($cont,"No match");

    if ($available) {
      /*
      $startpage_curl=curl_init();
      curl_setopt($startpage_curl, CURLOPT_URL,'https://startpage.com/do/search?q=' . $this->domain->name . '&l=italiano');
      curl_setopt($startpage_curl, CURLOPT_TIMEOUT, '10');
      curl_setopt($startpage_curl, CURLOPT_RETURNTRANSFER, 1);
      $startpage_query = curl_exec($startpage_curl);
      curl_close($startpage_curl);
      preg_match_all('/Circa (.*) risultati/', $startpage_query, $startpage_match);
      if (isset($startpage_match[1][0])) {
        $this->domain->startpage = intval(str_replace('.', '', $startpage_match[1][0]));
      }
      */

    }
    else {
      foreach($output as $v) {
        $line = trim($v);

        if (preg_match('/Expiration Date:(.*)/', $line, $m)) {
          $this->domain->expire_date = trim(str_replace('T', ' ', str_replace('Z', ' ', $m[1])));
        } elseif (preg_match('/Updated Date:(.*)/', $line, $m)) {
          $this->domain->updated_date = trim(str_replace('T', ' ', str_replace('Z', ' ', $m[1])));
        } elseif (preg_match('/Creation Date:(.*)/', $line, $m)) {
          $this->domain->creation_date = trim(str_replace('T', ' ', str_replace('Z', ' ', $m[1])));
        }
      }

      $this->domain->expire = strtotime($this->domain->expire_date);
      $this->domain->updated = strtotime($this->domain->updated_date);
      $this->domain->creation = strtotime($this->domain->creation_date);




      //$this->domain->email = $email;
      $this->domain->whois = $cont;
      if (isset($_GET['debug'])) {
        print_r($m);
      }
    }
    $this->domain->status = ($available ? 'OK' : 'KO');
  }

  public function checkOtherExt($ext) {
    if ($this->domain->extension != $ext) {
      $nsearch = new DomainSearch($this->env, $this->domain->name . '.' . $ext, $this->user);
      $this->domain->otherext[$ext] = (trim($nsearch->domain->status) == 'KO') ? 'YES' : 'NO';
    }
  }


  public function saveDB() {
    $query = ('insert into search values("", "' . $this->domain->toString() . '", "' . $this->user->name . '", "' . $this->domain->status . '", "' . date('Y-m-d H:i:s') . '")');
    mysql_query($query) or die('ERR;' . mysql_error());
    $this->search_id = mysql_insert_id();
  }

  /**
   * @param Environment $env
   * @return string
   */
  public static function getCollections($env) {
    $files = $env->scanDirectory('contrib/domain/collections');
    $collections = array();
    foreach ($files as $file) {
      $carr = explode('.', $file);
      $collections[] = $carr[0];
    }
    return $collections;
  }
}