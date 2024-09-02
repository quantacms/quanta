<?php
namespace Quanta\Common;

/**
 * Class Localization
 *
 * This class manages languages negotiation in the system.
 */
class Localization {
  const LANGUAGE_NEUTRAL = "language-neutral";
  const LANGUAGE_NEGOTIATION_SESSION = "session";
  const LANGUAGE_NEGOTIATION_PREFIX = "path";
  const DIR_LANGUAGES = "db/_languages";
  const DIR_TRANSLATIONS = "db/_translations";
  public static $dir_languages = self::DIR_LANGUAGES;
  public static $dir_translations = self::DIR_TRANSLATIONS;

  /**
   * Environment's language is always current one.
   *
   * @param Environment $env
   *   The Environment.
   * @return mixed
   */
  public static function getLanguage(Environment $env) {
    if (!empty($env->getData('language'))) { 
      $lang = $env->getData('language'); 
    }
    elseif (!empty($_SESSION['language'])) {
      $lang = $_SESSION['language'];
    }
    else {
      // TODO: we have to check that the fallback language is OK
      // without creating a loop in loading the language node...
      $lang = self::getFallbackLanguage($env);
      // No language set. Set the current language as the fallback language.
      $_SESSION['language'] = $lang;
    }
    return $lang;
  }

  /**
   * Get the current language negotiation method.
   * LANGUAGE_NEGOTIATION_PREFIX will add a prefix language string
   * (i.e.) /en/ to all URLs.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return array
   *   The languages.
   */
  public static function getLanguageNegotiation(Environment $env) {
    // If the fallback language has already been defined...
    if (empty($env->getData('language_negotiation'))) {
      $env->setData('language_negotiation', self::LANGUAGE_NEGOTIATION_PREFIX);
    }
    return $env->getData('language_negotiation');
  }

  /**
   * Get all enabled languages.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return array
   *   The languages.
   */
  public static function getEnabledLanguages(Environment $env) {
    // If the fallback language has already been defined...
    if (empty($env->getData('enabled_languages'))) {
      $language_list = $env->scanDirectory($env->dir['languages'], array('symlinks' => 'no','value_as_key'=>TRUE));
      $env->setData('enabled_languages', $language_list);
    }
    return $env->getData('enabled_languages');
  }
    /**
   * Get the fallback language.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @return string
   *   The fallback language.
   */
  public static function getFallbackLanguage(Environment $env) {
    // If the fallback language has not been defined...
    if (empty($env->getData('fallback_language'))) {
      $vars = array();
      $vars = array('fallback_language' => NULL);
      $env->hook('fallback_language', $vars);
      // Let modules set a default fallback language.
      if (empty($vars['fallback_language'])) {
        $language_list = Localization::getEnabledLanguages($env);
	
	$fallback = array_pop($language_list);
      }
      else {
        $fallback = $vars['fallback_language'];
      }
      $env->setData('fallback_language', $fallback);
    }

    return $env->getData('fallback_language');

  }

  /**
   * Check if a language is enabled.
   *
   * @param Environment $env
   *   The Environment.
   * @param $lang
   *   The language to check.
   *
   * @return boolean
   *   TRUE if the language is enabled.
   */
  public static function hasEnabledLanguage(Environment $env, $lang) {
    $enabled_languages = self::getEnabledLanguages($env);
    $is_enabled = FALSE;
    foreach ($enabled_languages as $language) {
      if ($language == $lang) {
        $is_enabled = TRUE;
        break;
      }
    }
    return $is_enabled;
  }
  /**
   * Switch the current language.
   *
   * @param Environment $env
   *   The Environment.
   *
   * @param string $lang
   *   A language code to switch into.
   */
  public static function switchLanguage(Environment $env, $lang, $update_language = FALSE) {
    
    // This is triggered when there is an active "language switch" request
    // such as when a language switcher link is clicked.
    if (isset($_GET['update_language'])) {
      $update_language = $_GET['update_language'];
    }
    if ($update_language && !empty($update_language)) {
      $lang = $_GET['update_language']; 
    }
    $language = NodeFactory::load($env, $lang);
    if ($language->exists) {
      // Change the session's language if there is an explicit request to do so.
      if (isset($_GET['update_language'])) {
      	$_SESSION['language'] = $lang;
      } 
	
      // Setup the language.
      $env->setData('language', $lang);

      // TODO: deprecate or enhance.
      if (isset($_GET['notify'])){
        new Message($env, 'Language switched to ' . $language->getTitle());
      }
    }
    else {
      // TODO: error should be more severe. We should never end in this state.
      new Message($env, 'Error - this language is not enabled: ' . $lang);
    }
  }

  /**
   * Returns a translated version of a string.
   *
   * @param string $string
   *   The translatable string.
   *
   * @param array $replace
   *   An array of replacements to perform in the string.
   *
   * @return string
   *   The translated string.
   */
  public static function t($string, array $replace = array()) {
    // TODO: multilanguage strings implementation.
    foreach ($replace as $k => $replacement) {
      if ($replacement == NULL) {
        $replacement = '';
      }
      $string = str_replace($k, $replacement, $string);
    }
    return $string;
  }

  public static function translatableText(Environment $env, $text, $tag = NULL, $lang = NULL, $replace = []) {
    if ($lang == NULL) {
    	$lang = $_SESSION['language'];
    }
    $prefix = 'i18n';
    $output_text = $text;
    if ($tag != NULL) {
      $tagnode = $prefix . '-' . $tag;
      $node = NodeFactory::load($env, $tagnode);
      if (!($node->exists)) {
        $attributes = array('title' => $text);
        $node = NodeFactory::buildNode($env, $tagnode, Localization::DIR_TRANSLATIONS, $attributes, $lang);
      }
      elseif ($node->hasTranslation($lang) && ($node->title != NULL)){
        $output_text = $node->title;
      }	
    }
    $output_text = self::t($output_text,$replace);
    return $output_text; 
  }

  public static function mapLocale($env,$lang){
    $node = NodeFactory::load($env, $lang);
    $locale_code = 'it_IT';
    if($node->exists && !empty($node->getAttributeJSON('locale_code'))){
      $locale_code = $node->getAttributeJSON('locale_code');
    }
    else{
      new Message($env,
      t('Warning:You should add a language code to the language !language',
        array('!language' => $lang)
      ),
      \Quanta\Common\Message::MESSAGE_WARNING
    );
    }
    return $locale_code;
  }

  public static function getAlpha2CountryCode($env, $alpha3){
    $alpha3_to_alpha2 = array(
      "AFG" => "AF", // Afghanistan
      "ALB" => "AL", // Albania
      "DZA" => "DZ", // Algeria
      "AND" => "AD", // Andorra
      "AGO" => "AO", // Angola
      "ATA" => "AQ", // Antarctica
      "ARG" => "AR", // Argentina
      "ARM" => "AM", // Armenia
      "ABW" => "AW", // Aruba
      "AUS" => "AU", // Australia
      "AUT" => "AT", // Austria
      "AZE" => "AZ", // Azerbaijan
      "BHS" => "BS", // Bahamas
      "BHR" => "BH", // Bahrain
      "BGD" => "BD", // Bangladesh
      "BRB" => "BB", // Barbados
      "BLR" => "BY", // Belarus
      "BEL" => "BE", // Belgium
      "BLZ" => "BZ", // Belize
      "BEN" => "BJ", // Benin
      "BMU" => "BM", // Bermuda
      "BTN" => "BT", // Bhutan
      "BOL" => "BO", // Bolivia
      "BES" => "BQ", // Bonaire, Sint Eustatius and Saba
      "BIH" => "BA", // Bosnia and Herzegovina
      "BWA" => "BW", // Botswana
      "BVT" => "BV", // Bouvet Island
      "BRA" => "BR", // Brazil
      "IOT" => "IO", // British Indian Ocean Territory
      "BRN" => "BN", // Brunei Darussalam
      "BGR" => "BG", // Bulgaria
      "BFA" => "BF", // Burkina Faso
      "BDI" => "BI", // Burundi
      "CPV" => "CV", // Cabo Verde
      "KHM" => "KH", // Cambodia
      "CMR" => "CM", // Cameroon
      "CAN" => "CA", // Canada
      "CYM" => "KY", // Cayman Islands
      "CAF" => "CF", // Central African Republic
      "TCD" => "TD", // Chad
      "CHL" => "CL", // Chile
      "CHN" => "CN", // China
      "CXR" => "CX", // Christmas Island
      "CCK" => "CC", // Cocos (Keeling) Islands
      "COL" => "CO", // Colombia
      "COM" => "KM", // Comoros
      "COG" => "CG", // Congo
      "COD" => "CD", // Congo, Democratic Republic of the
      "COK" => "CK", // Cook Islands
      "CRI" => "CR", // Costa Rica
      "HRV" => "HR", // Croatia
      "CUB" => "CU", // Cuba
      "CUW" => "CW", // Curaçao
      "CYM" => "KY", // Cayman Islands
      "CYP" => "CY", // Cyprus
      "CZE" => "CZ", // Czechia
      "DNK" => "DK", // Denmark
      "DJI" => "DJ", // Djibouti
      "DMA" => "DM", // Dominica
      "DOM" => "DO", // Dominican Republic
      "ECU" => "EC", // Ecuador
      "EGY" => "EG", // Egypt
      "SLV" => "SV", // El Salvador
      "GNQ" => "GQ", // Equatorial Guinea
      "ERI" => "ER", // Eritrea
      "EST" => "EE", // Estonia
      "ETH" => "ET", // Ethiopia
      "FLK" => "FK", // Falkland Islands
      "FRO" => "FO", // Faroe Islands
      "FJI" => "FJ", // Fiji
      "FIN" => "FI", // Finland
      "FRA" => "FR", // France
      "GAB" => "GA", // Gabon
      "GMB" => "GM", // Gambia
      "GEO" => "GE", // Georgia
      "DEU" => "DE", // Germany
      "GHA" => "GH", // Ghana
      "GIB" => "GI", // Gibraltar
      "GRC" => "GR", // Greece
      "GRD" => "GD", // Grenada
      "GLP" => "GP", // Guadeloupe
      "GUM" => "GU", // Guam
      "GTM" => "GT", // Guatemala
      "GGY" => "GG", // Guernsey
      "GIN" => "GN", // Guinea
      "GNB" => "GW", // Guinea-Bissau
      "GUY" => "GY", // Guyana
      "HTI" => "HT", // Haiti
      "HMD" => "HM", // Heard Island and McDonald Islands
      "VAT" => "VA", // Holy See
      "HND" => "HN", // Honduras
      "HKG" => "HK", // Hong Kong
      "HUN" => "HU", // Hungary
      "ISL" => "IS", // Iceland
      "IND" => "IN", // India
      "IDN" => "ID", // Indonesia
      "IRN" => "IR", // Iran
      "IRQ" => "IQ", // Iraq
      "IRL" => "IE", // Ireland
      "IMN" => "IM", // Isle of Man
      "ISR" => "IL", // Israel
      "ITA" => "IT", // Italy
      "JAM" => "JM", // Jamaica
      "JPN" => "JP", // Japan
      "JEY" => "JE", // Jersey
      "JOR" => "JO", // Jordan
      "KAZ" => "KZ", // Kazakhstan
      "KEN" => "KE", // Kenya
      "KIR" => "KI", // Kiribati
      "KOR" => "KR", // Korea
      "KWT" => "KW", // Kuwait
      "KGZ" => "KG", // Kyrgyzstan
      "LAO" => "LA", // Lao People's Democratic Republic
      "LVA" => "LV", // Latvia
      "LBN" => "LB", // Lebanon
      "LSO" => "LS", // Lesotho
      "LBR" => "LR", // Liberia
      "LBY" => "LY", // Libya
      "LIE" => "LI", // Liechtenstein
      "LTU" => "LT", // Lithuania
      "LUX" => "LU", // Luxembourg
      "MAC" => "MO", // Macau
      "MDG" => "MG", // Madagascar
      "MWI" => "MW", // Malawi
      "MYS" => "MY", // Malaysia
      "MDV" => "MV", // Maldives
      "MLI" => "ML", // Mali
      "MLT" => "MT", // Malta
      "MHL" => "MH", // Marshall Islands
      "MTQ" => "MQ", // Martinique
      "MRU" => "MR", // Mauritania
      "MUS" => "MU", // Mauritius
      "MYT" => "YT", // Mayotte
      "MEX" => "MX", // Mexico
      "FSM" => "FM", // Micronesia
      "MDA" => "MD", // Moldova
      "MCO" => "MC", // Monaco
      "MNG" => "MN", // Mongolia
      "MNE" => "ME", // Montenegro
      "MSR" => "MS", // Montserrat
      "MAR" => "MA", // Morocco
      "MOZ" => "MZ", // Mozambique
      "MMR" => "MM", // Myanmar
      "NAM" => "NA", // Namibia
      "NRU" => "NR", // Nauru
      "NPL" => "NP", // Nepal
      "NLD" => "NL", // Netherlands
      "NCL" => "NC", // New Caledonia
      "NZL" => "NZ", // New Zealand
      "NIC" => "NI", // Nicaragua
      "NER" => "NE", // Niger
      "NGA" => "NG", // Nigeria
      "NIU" => "NU", // Niue
      "NFK" => "NF", // Norfolk Island
      "MNP" => "MP", // Northern Mariana Islands
      "NOR" => "NO", // Norway
      "OMN" => "OM", // Oman
      "PAK" => "PK", // Pakistan
      "PLW" => "PW", // Palau
      "PSE" => "PS", // Palestine, State of
      "PAN" => "PA", // Panama
      "PNG" => "PG", // Papua New Guinea
      "PRY" => "PY", // Paraguay
      "PER" => "PE", // Peru
      "PHL" => "PH", // Philippines
      "PCN" => "PN", // Pitcairn
      "POL" => "PL", // Poland
      "PRT" => "PT", // Portugal
      "PRI" => "PR", // Puerto Rico
      "QAT" => "QA", // Qatar
      "REU" => "RE", // Réunion
      "ROU" => "RO", // Romania
      "RUS" => "RU", // Russia
      "RWA" => "RW", // Rwanda
      "BLM" => "BL", // Saint Barthélemy
      "SHN" => "SH", // Saint Helena, Ascension and Tristan da Cunha
      "KNA" => "KN", // Saint Kitts and Nevis
      "LCA" => "LC", // Saint Lucia
      "SPM" => "PM", // Saint Pierre and Miquelon
      "VCT" => "VC", // Saint Vincent and the Grenadines
      "WSM" => "WS", // Samoa
      "SMR" => "SM", // San Marino
      "STP" => "ST", // Sao Tome and Principe
      "SAU" => "SA", // Saudi Arabia
      "SEN" => "SN", // Senegal
      "SYC" => "SC", // Seychelles
      "SLE" => "SL", // Sierra Leone
      "SGP" => "SG", // Singapore
      "SXM" => "SX", // Sint Maarten (Dutch part)
      "SVK" => "SK", // Slovakia
      "SVN" => "SI", // Slovenia
      "SLB" => "SB", // Solomon Islands
      "SOM" => "SO", // Somalia
      "ZAF" => "ZA", // South Africa
      "SGS" => "GS", // South Georgia and the South Sandwich Islands
      "SSD" => "SS", // South Sudan
      "ESP" => "ES", // Spain
      "LKA" => "LK", // Sri Lanka
      "SDN" => "SD", // Sudan
      "SUR" => "SR", // Suriname
      "SJM" => "SJ", // Svalbard and Jan Mayen
      "SWZ" => "SZ", // Eswatini
      "SWE" => "SE", // Sweden
      "CHE" => "CH", // Switzerland
      "SYR" => "SY", // Syrian Arab Republic
      "TJK" => "TJ", // Tajikistan
      "TZA" => "TZ", // Tanzania
      "THA" => "TH", // Thailand
      "TLS" => "TL", // Timor-Leste
      "TGO" => "TG", // Togo
      "TKL" => "TK", // Tokelau
      "TON" => "TO", // Tonga
      "TTO" => "TT", // Trinidad and Tobago
      "TUN" => "TN", // Tunisia
      "TUR" => "TR", // Turkey
      "TKM" => "TM", // Turkmenistan
      "TCA" => "TC", // Turks and Caicos Islands
      "TUV" => "TV", // Tuvalu
      "UGA" => "UG", // Uganda
      "UKR" => "UA", // Ukraine
      "ARE" => "AE", // United Arab Emirates
      "GBR" => "GB", // United Kingdom of Great Britain and Northern Ireland
      "USA" => "US", // United States of America
      "UMI" => "UM", // United States Minor Outlying Islands
      "URY" => "UY", // Uruguay
      "UZB" => "UZ", // Uzbekistan
      "VUT" => "VU", // Vanuatu
      "VEN" => "VE", // Venezuela
      "VNM" => "VN", // Viet Nam
      "VGB" => "VG", // Virgin Islands, British
      "VIR" => "VI", // Virgin Islands, U.S.
      "WLF" => "WF", // Wallis and Futuna
      "WSM" => "WS", // Samoa
      "YEM" => "YE", // Yemen
      "ZMB" => "ZM", // Zambia
      "ZWE" => "ZW"  // Zimbabwe
    );
    return isset($alpha3_to_alpha2[$alpha3]) ? $alpha3_to_alpha2[$alpha3] : $alpha3;
  }
}
