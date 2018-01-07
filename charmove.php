<?php
/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   Portions of this program are derived from publicly licensed software
 *   projects including, but not limited to phpBB, Magelo Clone, 
 *   EQEmulator, EQEditor, and Allakhazam Clone.
 *
 *                                  Author:
 *                           Maudigan(Airwalking) 
 *
 *   September 26, 2014 Maudigan
 *     updated character table name, zone id column name, and removed zonename
 *   September 28, 2014 - Maudigan
 *      added code to monitor database performance
 *   October 4, 2014 - Maudigan
 *      renamed sql $cb_template to $query_tpl so as to not interfere with  
 *      the html template object
 *   May 24, 2016 - Maudigan
 *      general code cleanup, whitespace correction, removed old comments,
 *      organized some code. A lot has changed, but not much functionally
 *      do a compare to 2.41 to see the differences. 
 *      Implemented new database wrapper.
 *   January 7, 2018 - Maudigan
 *      Modified database to use a class.
 *  
 ***************************************************************************/
  
 
/*********************************************
                 INCLUDES
*********************************************/ 
define('INCHARBROWSER', true);
include_once(__DIR__ . "/include/config.php");
include_once(__DIR__ . "/include/language.php");
include_once(__DIR__ . "/include/functions.php");
include_once(__DIR__ . "/include/global.php");
include_once(__DIR__ . "/include/db.php");
 
 
//do not let anyone use the API on this screen
//we don't want to make it easier for people to brute
//force guess a login
//dont make a header if there is an API request 
if (isset($_GET['api']))  cb_message_die($language['MESSAGE_ERROR'],$language['MESSAGE_NOAPI']);
 
/*********************************************
             SUPPORT FUNCTIONS
*********************************************/
//TRYMOVE - attempts to move a character
function trymove($name, $login, $zone) {
   global $language, $charmovezones;
   global $cbsql;

   if (!$login || !$zone || !$name) return $login." / ".$name." / ".$zone." - one or more fields was left blank";
   if (!preg_match("/^[a-zA-Z]*\z/", $name)) return $login." / ".$name." / ".$zone." - character name contains illegal characters";
   //if (!preg_match("/^[a-zA-Z]*\z/", $login)) return $login." / ".$name." / ".$zone." - login contains illegal characters";
   if (!preg_match("/^[a-zA-Z]*\z/", $zone)) return $login." / ".$name." / ".$zone." - zone contains illegal characters";
   if (!$charmovezones[$zone]) return $login." / ".$name." / ".$zone." - zone is not a legal selection";  
  
   //get zone id, and verify shortname from db
   $tpl = <<<TPL
SELECT long_name, short_name, zoneidnumber 
FROM zone 
WHERE LCASE(short_name) = LCASE('%s') 
LIMIT 1
TPL;
   $query = sprintf($tpl, $cbsql->escape_string($zone));
   $result = $cbsql->query($query);  
   if (!$cbsql->rows($result))  return $login." / ".$name." / ".$zone." - zone database error";  
  
   $row = $cbsql->nextrow($result);
   $zonesn = $row['short_name'];
   $zoneln = $row['long_name'];
   $zoneid = $row['zoneidnumber'];

   //verify acct info is correct
   $tpl = <<<TPL
SELECT character_data.id 
FROM character_data 
JOIN account 
  ON account.id = character_data.account_id 
WHERE LCASE(account.name) = LCASE('%s') 
AND LCASE(character_data.name) = LCASE('%s') 
LIMIT 1
TPL;
   $query = sprintf($tpl, $cbsql->escape_string($login),
                          $cbsql->escape_string($name));
   $result = $cbsql->query($query); 

   if (!$cbsql->rows($result))  { 
      sleep(2);
      return $login." / ".$name." / ".$zone." - Login or character name was not correct";  
   }

   $row = $cbsql->nextrow($result);
   $charid = $row['id'];

   //move em
   $tpl = <<<TPL
UPDATE character_data 
SET zone_id = '%s', 
    x = '%s', 
    y = '%s', 
    z = '%s', 
WHERE id = '%s'
TPL;
   $query = sprintf($tpl, $cbsql->escape_string($zoneid),
                          $cbsql->escape_string($charmovezones[$zone]['x']),
                          $cbsql->escape_string($charmovezones[$zone]['y']),
                          $cbsql->escape_string($charmovezones[$zone]['z']),
                          $cbsql->escape_string($charid));
   $result = $cbsql->query($query);


   return $login." / ".$name." - moved to ".$zoneln;
}


/*********************************************
        GATHER RELEVANT PAGE DATA
*********************************************/
//dont display if blocked in config.php 
if ($blockcharmove) cb_message_die($language['MESSAGE_ERROR'],$language['MESSAGE_ITEM_NO_VIEW']);

$names = $_GET['name'];
$zones = $_GET['zone'];
$logins = $_GET['login'];
$char = $_GET['char'];
 
 
/*********************************************
               DROP HEADER
*********************************************/
$d_title = " - ".$language['PAGE_TITLES_CHARMOVE'];
include(__DIR__ . "/include/header.php");
 
 
/*********************************************
              POPULATE BODY
 This isn't just loading data into the
 template. For simplicity the moves are
 actually executed in this section.
*********************************************/
if ($names && $logins && $zones) {
   $cb_template->set_filenames(array(
      'mover' => 'charmove_result_body.tpl')
   );
   
   $cb_template->assign_vars(array( 
      'L_CHARACTER_MOVER' => $language['CHARMOVE_CHARACTER_MOVER'],
      'L_BOOKMARK' => $language['CHARMOVE_BOOKMARK'],
      'L_BACK' => $language['BUTTON_BACK'])
   );
   
   foreach ($names as $key => $value) {
      $cb_template->assign_block_vars( "results", array( 
         'OUTPUT' => trymove($value, $logins[$key], $zones[$key]))
      );
   }
}
else {
   $cb_template->set_filenames(array(
      'mover' => 'charmove_body.tpl')
   );
   
   $cb_template->assign_vars(array( 
      'CHARNAME' => $char, 
      'L_CHARACTER_MOVER' => $language['CHARMOVE_CHARACTER_MOVER'],
      'L_LOGIN' => $language['CHARMOVE_LOGIN'],
      'L_CHARNAME' => $language['CHARMOVE_CHARNAME'],
      'L_ZONE' => $language['CHARMOVE_ZONE'],
      'L_ADD_CHARACTER' => $language['CHARMOVE_ADD_CHARACTER'],
      'L_MOVE' => $language['BUTTON_CHARMOVE'])
   );

   foreach($charmovezones as $key => $value) {
      $cb_template->assign_block_vars( "zones", array(
         'VALUE' => $key)
      );
   }
}
 
 
/*********************************************
           OUTPUT BODY AND FOOTER
*********************************************/
$cb_template->pparse('mover');

$cb_template->destroy;

include(__DIR__ . "/include/footer.php");
?>