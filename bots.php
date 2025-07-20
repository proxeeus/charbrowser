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
 *   April 16, 2020 - Maudigan
 *       Initial Revision
 *   April 25, 2020 - Maudigan
 *     implement multi-tenancy
 *
 ***************************************************************************/
  
 
/*********************************************
                 INCLUDES
*********************************************/ 
//define this as an entry point to unlock includes
if ( !defined('INCHARBROWSER') ) 
{
   define('INCHARBROWSER', true);
}
include_once(__DIR__ . "/include/common.php");
include_once(__DIR__ . "/include/profile.php");
include_once(__DIR__ . "/include/db.php");
  
 
/*********************************************
       SETUP CHARACTER CLASS & PERMISSIONS
*********************************************/
$charName = preg_Get_Post('char', '/^[a-zA-Z]+$/', false, $language['MESSAGE_ERROR'],$language['MESSAGE_NO_CHAR'], true);

//character initializations 
$char = new Charbrowser_Character($charName, $showsoftdelete, $charbrowser_is_admin_page); //the Charbrowser_Character class will sanitize the character name
$charID = $char->char_id(); 
$name = $char->GetValue('name');

//block view if user level doesnt have permission
if ($char->Permission('bots')) $cb_error->message_die($language['MESSAGE_NOTICE'],$language['MESSAGE_ITEM_NO_VIEW']);
 
 
/*********************************************
        GATHER RELEVANT PAGE DATA
*********************************************/
//get factions from the db
$tpl = <<<TPL
SELECT bot_id, name, race, gender,
       class, face, level
FROM bot_data 
WHERE owner_id = %d 
ORDER BY name ASC 
TPL;
$query = sprintf($tpl, $charID);
$result = $cbsql->query($query);
if (!$cbsql->rows($result)) $cb_error->message_die($language['BOTS_BOTS']." - ".$name,$language['MESSAGE_NO_BOTS']);


$bots = $cbsql->fetch_all($result);  
 
 
/*********************************************
               DROP HEADER
*********************************************/
$d_title = " - ".$name.$language['PAGE_TITLES_BOTS'];
include(__DIR__ . "/include/header.php");
 
 
/*********************************************
            DROP PROFILE MENU
*********************************************/
output_profile_menu($name, 'bots');
 
 
/*********************************************
              POPULATE BODY
*********************************************/
$cb_template->set_filenames(array(
   'bots' => 'bots_body.tpl')
);


$cb_template->assign_both_vars(array(  
   'NAME'        => $name)
);
$cb_template->assign_vars(array(  
   'L_BOTS'  => $language['BOTS_BOTS'], 
   'L_DONE'      => $language['BUTTON_DONE'])
);
  
// Fetch bot IDs in the raid roster
$tpl_roster = <<<TPL
SELECT bot_id 
FROM bot_raid_roster
TPL;
$result_roster = $cbsql->query($tpl_roster);
$roster_bots = array_column($cbsql->fetch_all($result_roster), 'bot_id');

// Sort bots to prioritize those in the raid roster
usort($bots, function($a, $b) use ($roster_bots) {
    $a_in_roster = in_array($a['bot_id'], $roster_bots);
    $b_in_roster = in_array($b['bot_id'], $roster_bots);

    // Bots in the roster come first
    if ($a_in_roster && !$b_in_roster) return -1;
    if (!$a_in_roster && $b_in_roster) return 1;

    // Otherwise, sort alphabetically by name
    return strcmp($a['name'], $b['name']);
});

// Update bot names based on raid roster
foreach($bots as $bot) {
   $bot_name = $bot['name'];
   $bot_roster = "";
   if (in_array($bot['bot_id'], $roster_bots)) {
      $bot_roster = "Raider";
   }
   $cb_template->assign_both_block_vars("bots", array( 
      'NAME'    => $bot_name,
      'AVATAR_IMG' => getAvatarImage($bot['race'], $bot['gender'], $bot['face']),
      'RACE'    => $dbracenames[$bot['race']],
      'CLASS'   => $dbclassnames[$bot['class']],
      'LEVEL'   => $bot['level'],
      'ROSTER'  => $bot_roster)
   );
}
 
 
/*********************************************
           OUTPUT BODY AND FOOTER
*********************************************/
$cb_template->pparse('bots');

$cb_template->destroy();

include(__DIR__ . "/include/footer.php");
?>