<?php
set_time_limit('500000');
global $letter,$number,$character,$vowel;
function is_alphanumeric($test) {
return (preg_match("/^[a-z0-9-]+$/i", $test));
}
function retrieve ($letter) {
$A["L"] = array(
"a",
"b",
"c",
"d",
"e",
"f",
"g",
"h",
"i",
"j",
"k",
"l",
"m",
"n",
"o",
"p",
"q",
"r",
"s",
"t",
"u",
"v",
"w",
"x",
"y",
"z"
);

$A["V"]= array(
"a",
"e",
"i",
"o",
"u",
);

$a3= array(
"rs",
"st",
"ft",
"sh",
"nc",
"lt",
"ni",
"li",
"nt",
"sm",
"ll",
"mi"
);

$A["N"] = array(
"0",
"1",
"2",
"3",
"4",
"5",
"6",
"7",
"8",
"9"
);

$A["D"]=file("wordlists/BasicVocabulary.txt");
$A["K"]=file("wordlists/test.txt");
$A["I"]=file("wordlists/itacities.txt");
$A["A"]=file("wordlists/italian.txt");

$A["C"] = array(
"a",
"b",
"c",
"d",
"e",
"f",
"g",
"h",
"i",
"j",
"k",
"l",
"m",
"n",
"o",
"p",
"r",
"s",
"t",
"u",
"v",
"w",
"x",
"y",
"z",
"1",
"2",
"3",
"4",
"5",
"6",
"7",
"8",
"9",
"0"
);
$A['null']=array("0");

return $A[$letter];
}

class whois_search {
	
	
  var $mappa_estensione_server = array (
      "it" => "whois.nic.it",
      "com" => "rs.internic.net",
      "net"  => "rs.internic.net",
      "org"  => "whois.pir.org",
      "info" => "whois.afilias.net",
      "biz"  => "whois.neulevel.biz",
      "uk" => "whois.nic.uk",
      "fr" => "whois.nic.fr",
      "ws" => "whois.worldsite.ws",
      "ch" => "whois.nic.ch",
      "at" => "whois.nic.at",
  	  "be" => "whois.dns.be",
	  "in" => "domain.ncst.ernet.in/search.php",
	  "de" => "whois.denic.de",
	  "eu" =>"whois.eu",
	  "es" =>"whois.ripe.net",
	  "ca" => "whois.cira.ca",
	  "no" => "whois.norid.no",
	  "ru" => "whois.ripn.ru",
	  "fi" => "whois.ripe.net",
	  "to" => "whois.tonic.to",
	  "hk" => "whois.hkirc.hk",
	  "in" => "whois.registry.in",
	  "pro" => "whois.internic.net",
	  "ke" => "whois.kenic.or.ke",
	  "na" => "whois.na-nic.com.na",
	  "ua" => "whois.net.ua",
	  "ci" => "whois.nic.ci",
	  "re" => "whois.nic.re",
	  "se" => "whois.nic.se",
	  "us" => "whois.nic.us",
	  "ve" => "whois.nic.ve",
	  "tv" => "whois.www.tv"	  
      );
  function do_whois($dominio) {
    $dominio = strtolower(trim($dominio));
    $pos_punto = strrpos($dominio, ".");
    if (!$pos_punto) {
      return "nome di dominio non valido";
    } else {
      $estensione = substr($dominio, $pos_punto + 1);
      if (!array_key_exists($estensione,$this->mappa_estensione_server)) {
        return "estensione <b><i>.".$estensione."</i> non supportata";
      } 
    }
    $server = $this->mappa_estensione_server[$estensione];
    $puntatore_whois =  fsockopen($server, 43, $errno, $errstr, 30);
    $html_output = '';
    if (!$puntatore_whois) {
      $html_output = "$errstr ($errno)";
    } else {
       fputs($puntatore_whois, "$dominio\r\n");
       $html_output .= "<pre>\r\n";
       while (!feof($puntatore_whois)) {
         $html_output .= fread($puntatore_whois,128);
       }
      $html_output .= "</pre>";
       fclose ($puntatore_whois);
    }
    return $html_output;
  }
  function print_allowed_extension () {
    $vettore_estensioni = array_keys($this->mappa_estensione_server);
    $estensioni_supportate = '';
    for ($i = 0; $i < count($vettore_estensioni); $i++) {
      $estensioni_supportate .= '&nbsp;.'.$vettore_estensioni [$i].'&nbsp;';
    }
    return $estensioni_supportate;
  }
}




function truewhois($fulldomain) {
$domain=array();
$split=split("\.",$fulldomain);
$i=1;
$whois = new whois_search();
$domain['name']=$fulldomain;
$domain['status']="";
$domain['expire']="";

if ($split[$i]=="com" || $split[$i]=="net")
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"No match for ")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	

else if ($split[$i]=="info" || $split[$i]=="org"  ) {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"NOT FOUND")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="biz") {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"Not found")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}
else if ($split[$i]=="eu") {
		{
		$result=$whois->do_whois($fulldomain);
		if (strpos($result,"Status:      FREE")>0) 
		$domain['status']="FREE";
		else if (strpos($result,"Excessive querying")>0)
		$domain['status']="<b>Too many queries";
		else {
		$domain['status']="TAKEN";
		}
		}	
}

else if ($split[$i]=="de") {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"tatus:      free")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="es") {
	{
	
	$result=$whois->do_whois($fulldomain);
	echo $result;
	if (strpos($result,"no entries found")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="no") {
	{
	
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"no matches")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="ca") {
	{
	$result=$whois->do_whois($fulldomain);
	
	if (strpos($result,"status:      free")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="ru") {
	{
	
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"No entries found")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="to") {
	{
	
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"No match")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="it") {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"No entries found")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}


else if ($split[$i]=="tv") {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"information is not available for domain")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}


else if ($split[$i]=="us") {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"Not found")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="in") {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"NOT FOUND")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}

else if ($split[$i]=="se") {
	{
	$result=$whois->do_whois($fulldomain);
	if (strpos($result,"No data found")>0) 
	$domain['status']="FREE";
	else {
	$domain['status']="TAKEN";
	}
	}	
}




return $domain;
 }


?>