<?php
mysql_connect('localhost', 'root', 'root');
mysql_select_db('domains');
/**
 * Implementation of hook_action_node_add.
 * There is a node add request.
 * @param $vars
 */
function domain_action_domain_search($vars) {
  $response_json = json_encode(array('Prova' => 'si'));
  exit($response_json);
}


function domain_init($vars) {
  $user = UserFactory::current($vars['env'], TRUE);
  $vars['env']->addInclude('engine/code/php/contrib/domain/js/search.js');
  $vars['env']->addInclude('engine/code/php/contrib/domain/css/domain.css');
  switch ($vars['env']->getRequestedPath()) {

    case 'searchbox':
      // TODO: check credits here.
      print json_encode(array('OK'));
      exit;
      break;

    case 'domainAction':

      switch ($_REQUEST['action']) {
        case 'favorite':
          if ($_GET['value'] == '1') {
            //$user->addFavorite($_GET['domain']);
          }
          else {
            //$user->removeFavorite($_GET['domain']);
          }
          print json_encode('OK');
          break;
        case 'generate':
          print Domain::generate($_REQUEST['regex']);
          break;

        case 'expiring':
          $q = mysql_query('select * from domain where expire_date > 0 && expire_date < ' . time() . ' order by expire_date desc limit 100000');
          while ($fetch = mysql_fetch_array($q)) {
            print $fetch['domain'] . '<br/> '; // . date('Y-m-d', $fetch['expire_date']) . '<br/>';
          }
          break;

        case 'show_favorites':
          include('favorites.php');
          break;

        case 'search':
          $energy_current = Points::remove($vars['env'], $user, 'energy', '1');
          $energy_max = Points::get($vars['env'], $user, 'energy', 'max');
          if (!$energy_current) {
            print json_encode(array(
              'status' => 'ERROR',
              'error' => 'Your energy is finished! <a href="#" onclick="infoBox(\'zero_points\')">I want more!</a>',
            ));
          } else {
            $exp = Points::add($vars['env'], $user, 'experience', '1');
            $search = new DomainSearch($vars['env'], $_GET['domain'], $user);
            $result = $search->domain;
            $result->energy_current = $energy_current;
            $result->energy_max = $energy_max;
            $level = NameArcher::getLevel($exp);
            $result->exp_current = $exp;
            $result->exp_tonext = NameArcher::expToLevel($level+1);
            $result->exp_perctonext = NameArcher::percToLevel($exp, $level+1);
            $result->level = $level;

            print json_encode($result);
          }
          break;

        default:
          break;
      }

      exit;
      break;
    default:
      break;
  }
}


/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 05/06/15
 * Time: 11:49
 */

/*
function domain_user_load($vars) {
  $favorites = $vars['user']->getData('domain_favorites');
  if (!$favorites) {
    $vars['user']->setData("domain_favorites", array());
  }
}
*/
/*
function domain_user_save($vars) {
  $favorites = $vars['user']->getData('domain_favorites');
  if (!$favorites) {
    $vars['user']->setData("domain_favorites", array());
  }
}
*/

function domain_shadow_user_edit($vars) {
  $user = UserFactory::current($vars['env']);
  // TODO: refactor / hook

  $tab = '<table><th>Domain</th><th>Status</th><th></th>';
  $favorites_q = mysql_query("select * from favorites") or die(mysql_error());
  while ($fav = mysql_fetch_array($favorites_q)) {
    //$ns = new DomainSearch($vars['env'], $fav['domain'], $user);
    $tab .= "<tr><td class='favorite-item'><b>" . $fav['domain'] . "</b></td><td>TODO</td><td><a href='#' rel='". $fav['domain']. "' class='favorite selected'>Remove</a></td></tr>";
  }
  $tab .= '</table>';

  $vars['shadow']->addTab('Your Shots', $tab, 10);
}


function domain_complete($vars) {
  mysql_close();
}