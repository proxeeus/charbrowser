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
 *   September 26, 2014 - Maudigan
 *      Updated character table name
 *   September 28, 2014 - Maudigan
 *      added code to monitor database performance
 *   May 24, 2016 - Maudigan
 *      general code cleanup, whitespace correction, removed old comments,
 *      organized some code. A lot has changed, but not much functionally
 *      do a compare to 2.41 to see the differences.
 *      Implemented new database wrapper.
 *   October 3, 2016 - Maudigan
 *      Made the item links customizable
 *   January 7, 2018 - Maudigan
 *      fixed a typo when loading the $lots array
 *   January 7, 2018 - Maudigan
 *      Modified database to use a class.
 *   March 22, 2020 - Maudigan
 *     impemented common.php
 *   April 2, 2020 - Maudigan
 *     search by seller name (thanks croco/kinglykrab)
 *   April 3, 2020 - Maudigan
 *     add icons to inspect
 *     added number_format to prices
 *     added seller to the sort links
 *   May 17, 2020 - Maudigan
 *     rewrote the query logic to be a little cleaner, faster and to use
 *     the new php sort/join functions
 *   July 28, 2020
 *     put gold before silver in the item cost display
 *   March 16, 2022 - Maudigan
 *     added item type to the API for each item
 *   December 2, 2022 - Maudigan
 *     converted get/post fetch into a function with regex matching
 *     When there's 0 results it shows a message box popup, which is
 *     annoying cause you have to hit back; modified it to just display
 *     an empty bazaar window instead of a popup message.
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
include_once(__DIR__ . "/include/itemclass.php");
include_once(__DIR__ . "/include/db.php");
include_once(__DIR__ . "/include/spellcache.php");


/*********************************************
             GET/VALIDATE VARS
*********************************************/
//results per page
$perpage=25;

//fetch and prevalidate GET/POST vars
$start      = preg_Get_Post('start', '/^[0-9]+$/', '0', $language['MESSAGE_ERROR'], $language['MESSAGE_START_NUMERIC']);
$orderby    = preg_Get_Post('orderby', '/^[a-zA-Z]*$/', false, $language['MESSAGE_ERROR'], $language['MESSAGE_ORDER_ALPHA']);
$direction  = preg_Get_Post('direction', '/^(DESC|ASC|desc|asc)$/', false);
$class      = preg_Get_Post('class', '/^[0-9\-]+$/', '-1', $language['MESSAGE_ERROR'], $language['MESSAGE_CLASS_NUMERIC']);
$race       = preg_Get_Post('race', '/^[0-9\-]+$/', '-1', $language['MESSAGE_ERROR'], $language['MESSAGE_RACE_NUMERIC']);
$slot       = preg_Get_Post('slot', '/^[0-9\-]+$/', '-1', $language['MESSAGE_ERROR'], $language['MESSAGE_SLOT_NUMERIC']);
$stat       = preg_Get_Post('stat', '/^[A-Za-z\-1]+$/', '-1', $language['MESSAGE_ERROR'], $language['MESSAGE_CLASS_NUMERIC']);
$type       = preg_Get_Post('type', '/^[0-9\-]+$/', '-1', $language['MESSAGE_ERROR'], $language['MESSAGE_TYPE_NUMERIC']);
$pricemin   = preg_Get_Post('pricemin', '/^[0-9]+$/', false, $language['MESSAGE_ERROR'], $language['MESSAGE_PRICE_NUMERIC']);
$pricemax   = preg_Get_Post('pricemax', '/^[0-9]+$/', false, $language['MESSAGE_ERROR'], $language['MESSAGE_PRICE_NUMERIC']);
$item_dirty = preg_Get_Post('item', '/^[a-zA-Z0-9\-\ \']*$/', '', $language['MESSAGE_ERROR'], $language['MESSAGE_ITEM_ALPHA']);
$seller     = preg_Get_Post('char', '/^[a-zA-Z]*$/', '', $language['MESSAGE_ERROR'], $language['MESSAGE_NAME_ALPHA']);

//security against sql injection, escape strings that don't have 
//sufficiently restricted regex checks in the above section
$item = $cbsql_content->escape_string($item_dirty); 

//convert integer parameters
$start = intval($start);
$class = intval($class);
$race  = intval($race);
$slot  = intval($slot);
$type  = intval($type);

//build baselink for column sort and pagination links
$baselink=(($charbrowser_wrapped) ? $_SERVER['SCRIPT_NAME'] : "index.php") . "?page=bazaar&char=$seller&class=$class&race=$race&slot=$slot&type=$type&pricemin=$pricemin&pricemax=$pricemax&item=$item_dirty&stat=$stat";

//can only search stats for stats in the dropdown
if ($stat != '-1' && !array_key_exists ($stat, $language['BAZAAR_ARRAY_SEARCH_STAT']))
{
   $cb_error->message_die($language['MESSAGE_ERROR'],$language['MESSAGE_STAT_INVALID']);
}

//set default orderby and sort vars
if ($orderby === false) 
{
   //if orderby isn't set and they did a stat
   //search, order it by the stat they picked
   if ($stat != '-1')
   {
      $orderby = $stat;
   }
   else
   {
      $orderby = 'Name';
   }
}
if ($direction === false) 
{
   //if direction isn't set and they did a stat
   //search, then we should list the stats
   //in descending order
   if ($stat != '-1')
   {
      $direction = 'DESC';
   }
   else
   {
      $direction = 'ASC';
   }
}

//dont display bazaaar if blocked in config.php
if ($blockbazaar) $cb_error->message_die($language['MESSAGE_ERROR'],$language['MESSAGE_ITEM_NO_VIEW']);


/*********************************************
        BUILD AND EXECUTE THE SEARCH
*********************************************/

//generating our list of items is problematic because the trader records and the item
// records can be in a different database. We accomplish it by querying the trader results
// prefiltered with the trader filters. We use that to build a list of IDs. We then query
// all the item results using that list of IDs and any user filters. 
//we then manually join those item results and the trader results from before, then sort
// it by the user provided ordering. 

//QUERY ALL THE TRADER ITEMS, PREFILTERED BY TRADER FIELDS
//build the where clause
$filters = array();
if ($seller) $filters[] = "character_data.name = '".$seller."'";
if ($pricemin) $filters[] = "trader.item_cost >= ".($pricemin * 1000);
if ($pricemax) $filters[] = "trader.item_cost <= ".($pricemax * 1000);
$where = generate_where($filters);

$tpl = <<<TPL
SELECT character_data.name as charactername,
       trader.item_cost as tradercost,
       trader.item_id
FROM character_data 
INNER JOIN trader
        ON character_data.id = trader.char_id
        %s
TPL;

$query = sprintf($tpl, $where);
$result = $cbsql->query($query);

//continue to query the content tables if we have item for sell
$totalitems = 0;
if ($cbsql->rows($result))
{      
   //build item id list for items being sold   
   $filtered_trader_rows = $cbsql->fetch_all($result);  
   $filtered_trader_item_ids = get_id_list($filtered_trader_rows, 'item_id');

   //GET THE ITEM ROWS FOR ALL THE ITEMS FOR SELL, PREFILTERED
   //build the where clause
   $filters = array();
   $filters[] = "id IN (".$filtered_trader_item_ids.")";
   if ($item) $filters[] = "Name LIKE '%".str_replace(" ","%",$item)."%'";
   if($class > -1) $filters[] = "classes & ".$class;
   if($race > -1) $filters[] = "races & ".$race;
   if($type > -1) $filters[] = "itemtype = ".$type;
   if($slot > -1) $filters[] = "slots & ".$slot;
   $where = generate_where($filters);

   $tpl = <<<TPL
SELECT *
FROM items 
%s
TPL;

   $query = sprintf($tpl, $where);
   $result = $cbsql_content->query($query);

   //continue if we have results
   if ($cbsql->rows($result)) 
   {
      //get the item results as an array
      $filtered_items = $cbsql_content->fetch_all($result); 

      //DO A MANUAL JOIN OF THE RESULTS
      //loop through the trader rows and join the item stats to it in a new array
      $joined_results = manual_join($filtered_trader_rows, 'item_id', $filtered_items, 'id', 'inner');
      $totalitems = cb_count($joined_results);


      //DO A MANUAL SORT OF THE RESULTS
      if ($orderby == 'Name' || $orderby == 'charactername') {
         $sort_type = 'string';
      }
      else {
         $sort_type = 'int';
      }
      sort_by($joined_results, $orderby, $direction, $sort_type);


      //LIMIT TO 1 PAGE OF RESULTS
      $truncated_results = array();
      $finish = min($start + $perpage, $totalitems);
      for ($i = $start; $i < $finish; $i++) { 
         $truncated_results[] = $joined_results[$i];
      }

      //precache all the spells on the items using the item set
      $cbspellcache->build_cache_itemset($truncated_results);
   }
   else
   {
      $totalitems = 0;
   }
}

/*********************************************
               DROP HEADER
*********************************************/
$d_title = " - ".$language['PAGE_TITLES_BAZAAR'];
include(__DIR__ . "/include/header.php");


/*********************************************
            DROP PROFILE MENU
*********************************************/
//if you're looking at a players store, treat it like
//a profile page
if ($seller) {
   output_profile_menu($seller, 'bazaar');
}


/*********************************************
              POPULATE BODY
*********************************************/
//build body template
$cb_template->set_filenames(array(
  'bazaar' => 'bazaar_body.tpl')
);

$cb_template->assign_both_vars(array(
   'ORDERBY' => $orderby,
   'DIRECTION' => $direction,
   'START' => $start,
   'PERPAGE' => $perpage,
   'TOTALITEMS' => $totalitems)
);

$cb_template->assign_vars(array(
   'ITEM' => $item_dirty,
   'SELLER' => $seller,
   'STORENAME' => ($seller) ? " - ".ucwords(strtolower($seller)) : "",
   'ORDER_LINK' => $baselink."&start=$start&direction=".(($direction=="ASC") ? "DESC":"ASC"),
   'PAGINATION' => cb_generate_pagination("$baselink&orderby=$orderby&direction=$direction", $totalitems, $perpage, $start, true),
   'PRICE_MIN' => $pricemin,
   'PRICE_MAX' => $pricemax,

   'L_BAZAAR' => $language['BAZAAR_BAZAAR'],
   'L_NAME' => $language['BAZAAR_NAME'],
   'L_PRICE' => $language['BAZAAR_PRICE'],
   'L_ITEM' => $language['BAZAAR_ITEM'],
   'L_SEARCH' => $language['BAZAAR_SEARCH'],
   'L_SEARCH_NAME' => $language['BAZAAR_SEARCH_NAME'],
   'L_SEARCH_CLASS' => $language['BAZAAR_SEARCH_CLASS'],
   'L_SEARCH_RACE' => $language['BAZAAR_SEARCH_RACE'],
   'L_SEARCH_SLOT' => $language['BAZAAR_SEARCH_SLOT'],
   'L_SEARCH_STAT' => $language['BAZAAR_SEARCH_STAT'],
   'L_SEARCH_TYPE' => $language['BAZAAR_SEARCH_TYPE'],
   'L_SEARCH_PRICE_MIN' => $language['BAZAAR_SEARCH_PRICE_MIN'],
   'L_SEARCH_PRICE_MAX' => $language['BAZAAR_SEARCH_PRICE_MAX'])
);

//dump items for this page if we found any
if (isset($truncated_results) && is_array($truncated_results))
{
   $slotcounter = 0;
   foreach ($truncated_results as $lot)
   {
      $tempitem = new Charbrowser_Item($lot);
      $price = $lot["tradercost"];
      $plat = number_format(floor($price/1000));
      $price = $price % 1000;
      $gold = number_format(floor($price/100));
      $price = $price % 100;
      $silver = number_format(floor($price/10));
      $copper  = number_format($price % 10);
      $cb_template->assign_both_block_vars("items", array(
         'SELLER' => $lot['charactername'],
         'PRICE' => (($plat)?$plat."p ":"").(($gold)?$gold."g ":"").(($silver)?$silver."s ":"").(($copper)?$copper."c ":""),
         'NAME' => $tempitem->name(),
         'ID' => $tempitem->id(),
         'ICON' => $tempitem->icon(),
         'LINK' => QuickTemplate($link_item, array('ITEM_ID' => $tempitem->id())),
         'HTML' => $tempitem->html(),
         'ITEMTYPE' => $tempitem->skill(),
         'SLOT' => $slotcounter)
      );
      if ($stat != '-1') 
      {   
         $cb_template->assign_block_vars("items.stat_col", array(
            'STAT' => $tempitem->fetchColumn($stat))
         );
      }
      $slotcounter ++;
   }
}

//if they selected a stat, output a conditional template display
if ($stat != '-1') {   
   $cb_template->assign_block_vars("switch_stat", array(
      'STAT' => $stat,
      'L_STAT' => $language['BAZAAR_ARRAY_SEARCH_STAT'][$stat])
   );
}


//built combo box options
foreach ($language['BAZAAR_ARRAY_SEARCH_TYPE'] as $key => $value ) {
   $cb_template->assign_block_vars("select_type", array(
      'VALUE' => $key,
      'OPTION' => $value,
      'SELECTED' => (($type == $key) ? "selected":""))
   );
}
foreach ($language['BAZAAR_ARRAY_SEARCH_CLASS'] as $key => $value ) {
   $cb_template->assign_block_vars("select_class", array(
      'VALUE' => $key,
      'OPTION' => $value,
      'SELECTED' => (($class == $key) ? "selected":""))
   );
}
foreach ($language['BAZAAR_ARRAY_SEARCH_RACE'] as $key => $value ) {
   $cb_template->assign_block_vars("select_race", array(
      'VALUE' => $key,
      'OPTION' => $value,
      'SELECTED' => (($race == $key) ? "selected":""))
   );
}
foreach ($language['BAZAAR_ARRAY_SEARCH_SLOT'] as $key => $value ) {
   $cb_template->assign_block_vars("select_slot", array(
      'VALUE' => $key,
      'OPTION' => $value,
      'SELECTED' => (($slot == $key) ? "selected":""))
   );
}
foreach ($language['BAZAAR_ARRAY_SEARCH_STAT'] as $key => $value ) {
   $cb_template->assign_block_vars("select_stat", array(
      'VALUE' => $key,
      'OPTION' => $value,
      'SELECTED' => (($stat == $key) ? "selected":""))
   );
}


/*********************************************
           OUTPUT BODY AND FOOTER
*********************************************/
$cb_template->pparse('bazaar');

$cb_template->destroy();

include(__DIR__ . "/include/footer.php");
?>
