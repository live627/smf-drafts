<?php
/*
	Drafts Modification for SMF 2.0/1.1

	Created by:		Charles Hill
	Website:		http://www.degreesofzero.com/

	Copyright 2008 - 2010.  All Rights Reserved.


	This script is meant to be run either from the package manager or directly by URL.

	ATTENTION: If you are MANUALLY installing or upgrading with this package, please access
	it directly, with a URL like the following:
		http://www.yourdomain.com/forum/upgrade1-1-201.php (or similar)
*/

// If SSI.php is in the same place as this file, and SMF isn't defined, this is being run standalone.
if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('SMF'))
	require_once(dirname(__FILE__) . '/SSI.php');
// Hmm... no SSI.php and no SMF?
elseif (!defined('SMF'))
	die('<b>Error:</b> Cannot install - please verify you put this in the same place as SMF\'s index.php.');

global $db_prefix;

$insert_drafts = array();

// old version of the drafts mod previously installed... let's do some stuff
if (mysql_num_rows(db_query("SHOW TABLES LIKE '{$db_prefix}drafts'", __FILE__, __LINE__)) > 0 && mysql_num_rows(db_query("SHOW COLUMNS FROM {$db_prefix}drafts LIKE 'draftID'", __FILE__, __LINE__)) > 0)
{
	$add_columns = array();

	// for backwards compatibility... make sure these columns are in the existing table
	foreach (array(
		'locked' => 'tinyint(4) unsigned NOT NULL default \'0\'',
		'isSticky' => 'tinyint(4) unsigned NOT NULL default \'0\'',
		'smileysEnabled' => 'tinyint(4) unsigned NOT NULL default \'0\'',
		'topicID' => 'int(10) unsigned NOT NULL default \'0\'',
		'icon' => 'varchar(16) NOT NULL default \'xx\''
	) as $k => $sql)
		if (mysql_num_rows(db_query("SHOW COLUMNS FROM {$db_prefix}drafts LIKE '$k'", __FILE__, __LINE__)) == 0)
			$add_columns[] = '`' . $k . '` ' . $sql;

	unset($k, $sql);

	// add the columns that don't already exist
	if (!empty($add_columns))
		db_query("
			ALTER TABLE {$db_prefix}drafts
				" . implode(',
				ADD ', $add_columns), __FILE__, __LINE__);

	unset($add_columns);

	$request = db_query("
		SELECT draftID, memberID, boardID, topicID, timestamp, locked, isSticky, smileysEnabled, icon, body, subject
		FROM {$db_prefix}drafts", __FILE__, __LINE__);

	while ($row = mysql_fetch_assoc($request))
		$insert_drafts[] = "(" . (int) $row['draftID'] . ", " . (int) $row['memberID'] . ", " . (int) $row['boardID'] . ", " . (int) $row['topicID'] . ", " . (int) $row['timestamp'] . ", " . (int) $row['locked'] . ", " . (int) $row['isSticky'] . ", " . (int) $row['smileysEnabled'] . ", SUBSTRING('$row[icon]', 1, 16), '" . mysql_real_escape_string(str_replace('\'', '&#39;', un_htmlspecialchars(stripslashes($row['body'])))) . "', SUBSTRING('" . mysql_real_escape_string(str_replace('\'', '&#39;', un_htmlspecialchars(stripslashes($row['subject'])))) . "', 1, 255))";

	mysql_free_result($request);

	// just so we're sure we set up the table properly
	db_query("
		DROP TABLE IF EXISTS {$db_prefix}drafts", __FILE__, __LINE__);
}

// creates the drafts table
db_query("
	CREATE TABLE IF NOT EXISTS {$db_prefix}drafts(
		draft_id mediumint(8) NOT NULL auto_increment,
		member_id mediumint(8) NOT NULL default '0',
		board_id smallint(5) NOT NULL default '0',
		topic_id mediumint(8) NOT NULL default '0',
		timestamp int(10) NOT NULL default '0',
		locked tinyint(1) NOT NULL default '0',
		is_sticky tinyint(1) NOT NULL default '0',
		smileys_enabled tinyint(1) NOT NULL default '0',
		icon varchar(16) NOT NULL default 'xx',
		body text NOT NULL,
		subject tinytext NOT NULL,
		poll text NOT NULL,
		PRIMARY KEY (draft_id))", __FILE__, __LINE__);

// put the drafts back into the db
if (!empty($insert_drafts))
	db_query("
		INSERT INTO {$db_prefix}drafts
			(`draft_id`, `member_id`, `board_id`, `topic_id`, `timestamp`, `locked`, `is_sticky`, `smileys_enabled`, `icon`, `body`, `subject`)
		VALUES
			" . implode(',
			', $insert_drafts), __FILE__, __LINE__);

unset($insert_drafts);

?>