<?php
/*
	Drafts Modification for SMF 2.0/1.1
	
	Created by:		Charles Hill
	Website:		http://www.degreesofzero.com/
	
	Copyright 2008 - 2010.  All Rights Reserved.
*/

if (!defined('SMF'))
	die('Hacking attempt...');

/*
	SMF 2.0
	-------
	'htmlspecialchars' => $smcFunc['htmlspecialchars'],
	'db_fetch_row' => $smcFunc['db_fetch_row'],
	'db_fetch_assoc' => $smcFunc['db_fetch_assoc'],
	'db_free_result' => $smcFunc['db_free_result'],
	'db_num_rows' => $smcFunc['db_num_rows'],
	'db_insert_id' => $smcFunc['db_insert_id'],
	'un_htmlspecialchars' => 'un_htmlspecialchars'

	$smcFunc['db_query']($identifier, $query, $values);
	
	
	SMF 1.1
	-------
	'htmlspecialchars' => $func['htmlspecialchars'],
	'db_fetch_row' => 'mysql_fetch_row',
	'db_fetch_assoc' => 'mysql_fetch_assoc',
	'db_free_result' => 'mysql_free_result',
	'db_num_rows' => 'mysql_num_rows',
	'db_insert_id' => 'db_insert_id',
	'un_htmlspecialchars' => 'un_htmlspecialchars'

	db_query($query, $file, $line);
*/

function drafts_is_draft_author($draft_id)
{
	if (empty($draft_id))
		return false;
	
	// admins can do anything or, in this case, be anything...
	if (allowedTo('admin_forum'))
		return true;
	
	global $context;
	
	if (empty($context['user']['id']) || !allowedTo('save_drafts'))
	{
		global $txt;
		
		fatal_error($txt['drafts'][16], false);
	}
	
	global $db_prefix;
	
	$values = array(
		'member_id' => (int) $context['user']['id'],
		'draft_id' => (int) $draft_id
	);
	
	$request = db_query("
		SELECT draft_id
		FROM {$db_prefix}drafts
		WHERE member_id = $values[member_id]
			AND draft_id = $values[draft_id]
		LIMIT 1", __FILE__, __LINE__);
	
	if (mysql_num_rows($request) == 0)
	{
		mysql_free_result($request);
		
		global $txt;
		
		fatal_error($txt['drafts'][17], false);
	}
	
	mysql_free_result($request);
}

function drafts_save_draft()
{
	if (!empty($_POST['drafts-draft_id']))
		drafts_is_draft_author($_POST['drafts-draft_id']);

	global $board, $topic;
	
	// verify this user has permission to post a new topic to this board
	if (empty($topic))
		isAllowedTo('post_new', $board);

	global $context, $sourcedir, $db_prefix, $modSettings;
			
	$context['draft_saved'] = false;
	
	$values = array(
		'member_id' => (int) $context['user']['id'],
		'board_id' => (int) $board,
		'topic_id' => empty($topic) ? 0 : $topic,
		'subject' => $_POST['subject'],
		'body' => $_POST['message'],
		'timestamp' => time(),
		'is_sticky' => isset($_POST['sticky']) && !empty($modSettings['enableStickyTopics']) ? (int) $_POST['sticky'] : 0,
		'locked' => isset($_POST['lock']) ? (int) $_POST['lock'] : 0,
		'smileys_enabled' => !isset($_POST['ns']) ? 1 : 0,
		'icon' => preg_replace('~[\./\\\\*\':"<>]~', '', $_POST['icon']),
		'poll' => ''
	);
	
	if (isset($_REQUEST['poll']))
	{
		$poll = array(
			'question' => $_POST['question'],
			'choices' => $_POST['options'],
			'options' => array(
				'max_votes' => $_POST['poll_max_votes'],
				'hide' => $_POST['poll_hide'],
				'change_vote' => $_POST['poll_change_vote'],
				'expire' => isset($_POST['poll_expire']) ? $_POST['poll_expire'] : null
			)
		);
		
		$values['poll'] = @serialize($poll);
	}
	
	// updating an existing draft?
	if (!empty($_POST['drafts-draft_id']))
	{
		$values['draft_id'] = (int) $_POST['drafts-draft_id'];
		
		$result = db_query("
			UPDATE {$db_prefix}drafts
			SET board_id = $values[board_id],
				topic_id = $values[topic_id],
				is_sticky = $values[is_sticky],
				locked = $values[locked],
				smileys_enabled = $values[smileys_enabled],
				icon = '$values[icon]',
				body = '$values[body]',
				subject = '$values[subject]',
				timestamp = $values[timestamp],
				poll = '$values[poll]'
			WHERE draft_id = $values[draft_id]
				AND member_id = $values[member_id]
			LIMIT 1", __FILE__, __LINE__);
		
		$context['draft_id'] = $values['draft_id'];
		
		$context['draft_saved'] = $result;
	}
	else
	{
		// we're creating a new draft then?
		$result = db_query("
			INSERT INTO {$db_prefix}drafts
				(member_id, board_id, topic_id, body, subject, timestamp, is_sticky, locked, smileys_enabled, icon, poll)
			VALUES ($values[member_id], $values[board_id], $values[topic_id], '$values[body]', '$values[subject]', $values[timestamp], $values[is_sticky], $values[locked], $values[smileys_enabled], '$values[icon]', '$values[poll]')", __FILE__, __LINE__);
		
		if (!$result)
			$context['post_error']['messages'][] = $txt['drafts'][0];
		else
		{
			$values['draft_id'] = db_insert_id();
		
			// something went wrong
			if (empty($values['draft_id']))
				$context['post_error']['messages'][] = $txt['drafts'][0];
			else
				$context['draft_saved'] = true;
		}
	}
	
	drafts_prepare_draft_context($values);
}

function drafts_delete_draft($draft_id)
{
	if (empty($draft_id))
		return false;
	
	drafts_is_draft_author($draft_id);
	
	global $context;
	
	if (empty($context['user']['id']))
		return false;
	
	global $db_prefix;
	
	$values = array(
		'member_id' => (int) $context['user']['id'],
		'draft_id' => (int) $draft_id
	);
	
	$result = db_query("
		DELETE FROM {$db_prefix}drafts
		WHERE member_id = $values[member_id]
			AND draft_id = $values[draft_id]
		LIMIT 1", __FILE__, __LINE__);
	
	return $result;
}

function drafts_load_list_of_drafts($member_id = null)
{
	global $context;
	
	$member_id = $member_id === null ? $context['user']['id'] : $member_id;
	
	if (empty($member_id))
		return;
	
	global $func, $db_prefix, $scripturl;
	
	$values = array(
		'member_id' => (int) $member_id
	);
	
	// get drafts from the db
	$request = db_query("
		SELECT 
			d.draft_id, d.board_id, d.topic_id, d.subject, d.timestamp, d.poll,
			b.name AS board_name,
			msg.subject AS topic_subject
		FROM {$db_prefix}drafts AS d
			LEFT JOIN {$db_prefix}boards AS b ON (b.ID_BOARD = d.board_id)
			LEFT JOIN {$db_prefix}topics AS t ON (t.ID_TOPIC = d.topic_id)
			LEFT JOIN {$db_prefix}messages AS msg ON (msg.ID_MSG = t.ID_FIRST_MSG)
		WHERE d.member_id = $values[member_id]
		ORDER BY d.timestamp DESC", __FILE__, __LINE__);
		
	$list_of_drafts = array();
	
	while ($row = mysql_fetch_assoc($request))
	{
		$row['subject'] = un_htmlspecialchars($row['subject']);
		$truncate_subject = drafts_truncate($row['subject'], 20);
		
		if (strlen($truncate_subject) < strlen($row['subject']))
			$row['subject'] .= ' ...';
	
		$list_of_drafts[$row['draft_id']] = array(
			'id' => $row['draft_id'],
			'board' => array(
				'id' => $row['board_id'],
				'name' => $row['board_name']
			),
			'topic' => array(
				'id' => $row['topic_id'],
				'subject' => !empty($row['topic_id']) ? $row['topic_subject'] : ''
			),
			'subject' => $func['htmlspecialchars']($row['subject'], ENT_QUOTES),
			'last_saved' => timeformat($row['timestamp']),
			'edit' => $scripturl . '?action=post;board=' . $row['board_id'] . '.0;' . (!empty($row['poll']) ? 'poll;' : '') . (!empty($row['topic_id']) ? 'topic=' . $row['topic_id'] . '.0;' : '') . 'draft=' . $row['draft_id'],
			'post' => $scripturl . '?action=profile;sa=show_drafts;u=' . $member_id . ';postDraft='. $row['draft_id'] . ';sesc=' . $context['session_id']
		);
	}
	
	mysql_free_result($request);
	
	return $list_of_drafts;
}

function drafts_load_draft()
{
	if (empty($_REQUEST['draft']))
		return false;

	global $context, $db_prefix, $board, $topic;
	
	$values = array(
		'member_id' => (int) $context['user']['id'],
		'draft_id' => (int) $_REQUEST['draft'],
		'board_id' => (int) $board,
		'topic_id' => (int) $topic
	);
	
	$request = db_query("
		SELECT draft_id, subject, body, board_id, topic_id, is_sticky, locked, smileys_enabled, icon, poll
		FROM {$db_prefix}drafts
		WHERE member_id = $values[member_id]
			AND draft_id = $values[draft_id]
			AND board_id = $values[board_id]
			AND topic_id = $values[topic_id]
		LIMIT 1", __FILE__, __LINE__);
	
	if (mysql_num_rows($request) == 0)
	{
		global $txt;
		
		fatal_error($txt['drafts'][18], false);
	}
	
	while ($row = mysql_fetch_assoc($request))
		drafts_prepare_draft_context($row);
	
	mysql_free_result($request);
}

function drafts_prepare_draft_context($draft_info)
{
	global $context, $sourcedir;
	
	// only do this stuff if this is a draft for a topic
	if (empty($draft_info['topic_id']))
	{
		drafts_load_list_of_boards();
	
		$context['sticky'] = $draft_info['is_sticky'];
		$context['locked'] = $draft_info['locked'];
		
		if (!empty($draft_info['poll']) && isset($_REQUEST['poll']))
		{
			$poll = !is_array($draft_info['poll']) ? @unserialize($draft_info['poll']) : $draft_info['poll'];
			
			$context['question'] = $poll['question'];
			
			$context['poll_options'] = array(
				'max_votes' => empty($poll['options']['max_votes']) ? 1 : max(1, $poll['options']['max_votes']),
				'hide' => empty($poll['options']['hide']) ? 0 : $poll['options']['hide'],
				'change_vote' => !empty($poll['options']['change_vote']),
				'expire' => empty($poll['options']['expire']) ? '' : $poll['options']['expire']
			);
			
			$context['choices'] = array();
			
			if (!empty($poll['choices']))
			{
				$n = count($poll['choices']);
				$i = 0;
				
				foreach ($poll['choices'] as $choice)
					$context['choices'][] = array(
						'id' => $i++,
						'label' => $choice,
						'number' => $i,
						'is_last' => $i == $n
					);
				
				unset($n, $i, $choice);
			}
			
			unset($poll);
			
			// so the post form knows this is a poll
			$context['make_poll'] = true;
		}
	}
	
	$context['use_smileys'] = !empty($draft_info['smileys_enabled']);
	$context['icon'] = !empty($draft_info['icon']) ? $draft_info['icon'] : '';
	
	require_once($sourcedir . '/Subs-Post.php');
	
	// reverse what preparsecode() does last before we use un_preparsecode()
	// not quite sure why un_preparsecode() doesn't already do this...
	$context['message'] = strtr($draft_info['body'], array('&#91;]' => '[]', '&#91;&#039;' => '[&#039;'));
	
	$context['message'] = un_preparsecode($context['message']);
	$context['subject'] = $draft_info['subject'];
	
	$context['draft_id'] = $draft_info['draft_id'];
}

function drafts_truncate($string, $length, $break_with = ' ')
{
	if (empty($length) || strlen($string) < $length)
		return $string;
	
	$break_point = strpos($string, $break_with, $length);
	
	if ($break_point === false)
		return $string;
	
	return substr($string, 0, $break_point);
}

function drafts_get_num_drafts($member_id = null)
{
	global $db_prefix, $context, $txt;
	
	$values = array(
		'member_id' => $member_id === null ? (int) $context['user']['id'] : $member_id
	);
	
	$request = db_query("
		SELECT count(draft_id)
		FROM {$db_prefix}drafts
		WHERE member_id = $values[member_id]", __FILE__, __LINE__);
	
	list ($num_drafts) = mysql_fetch_row($request);
	
	mysql_free_result($request);
	
	// for the page title for Profiles
	$txt['show_drafts'] = $txt['drafts'][2];
	
	return $num_drafts;
}

function show_drafts($member_id)
{
	global $context;
	
	if ((!$context['user']['is_owner'] || !allowedTo('profile_view_own') || !allowedTo('save_drafts')) && !allowedTo('profile_view_any'))
		redirectexit('action=profile;u=' . $member_id);
	
	loadTemplate('Drafts');
	
	global $txt, $scripturl, $db_prefix;
	
	// are we deleting drafts?
	if (!empty($_POST['drafts-delete']))
	{
		// gotta check the session ID
		checkSession('post');
		
		$delete_drafts = array();
		
		// sanitize draft IDs
		foreach ($_POST['drafts-delete'] as $draft_id)
			$delete_drafts[] = (int) $draft_id;
		
		// delete the drafts from the db
		db_query("
			DELETE FROM {$db_prefix}drafts
			WHERE draft_id IN (" . implode(', ', $delete_drafts) . ")
				AND member_id = $member_id
			LIMIT " . count($delete_drafts), __FILE__, __LINE__);
		
		// send them back to where they came from
		redirectexit(preg_replace('~;draft=([0-9]+)~', '', str_replace('action=post2;', 'action=post;', $_SESSION['old_url'])));
	}
		
	// are we posting a draft as a topic?
	if (isset($_REQUEST['postDraft']))
	{
		// gotta check the session ID
		checkSession('get');
	
		// sanitize the draft ID
		$draft_id = (int) $_REQUEST['postDraft'];
		
		// get some info about this draft
		$request = db_query("
			SELECT 
				d.body, d.subject, d.board_id, d.topic_id, d.icon, d.smileys_enabled, d.locked, d.is_sticky, d.poll,
				b.countPosts AS count_posts
			FROM {$db_prefix}drafts AS d
				LEFT JOIN {$db_prefix}boards AS b ON (b.ID_BOARD = d.board_id)
			WHERE d.draft_id = $draft_id
			LIMIT 1", __FILE__, __LINE__);
		
		// it doesn't exist
		if (mysql_num_rows($request) == 0)
			fatal_error($txt['drafts'][19], false);
			
		// this draft does exist... so we can keep doing stuff... yay I like doing stuff :)
		while ($row = mysql_fetch_assoc($request))
		{
			// verify this user has permission to post a new topic to this board
			if (empty($row['topic_id']))
				isAllowedTo('post_new', $row['board_id']);
		
			global $sourcedir;
			
			require_once($sourcedir . '/Subs-Post.php');
			
			$posterOptions = array();
			$msgOptions = array();
			$topicOptions = array();
			
			// remember, can only post polls as a new topic
			if (empty($row['topic_id']) && !empty($row['poll']))
			{
				$poll = @unserialize($row['poll']);
				
				// Create the poll.
				$result = db_query("
					INSERT INTO {$db_prefix}polls
						(question, hideResults, maxVotes, expireTime, ID_MEMBER, posterName, changeVote)
					VALUES (SUBSTRING('$poll[question]', 1, 255), " . $poll['options']['hide'] . ", " . $poll['options']['max_votes'] . ",
						" . (empty($poll['options']['expire']) ? 0 : time() + $poll['options']['expire'] * 3600 * 24) . ", $member_id, SUBSTRING('" . addslashes($context['user']['username']) . "', 1, 255), " . $poll['options']['change_vote'] . ")", __FILE__, __LINE__);
				
				if (!$result)
					fatal_error($txt['drafts'][20], false);
				
				$poll_id = db_insert_id();
				
				if (empty($poll_id))
					fatal_error($txt['drafts'][20], false);
		
				$i = 0;
				$insert = array();
				
				// Create each answer choice.
				foreach ($poll['choices'] as $choice)
					$insert[] = '(' . $poll_id . ', ' . $i++ . ', SUBSTRING(\'' . $choice . '\', 1, 255))';
				
				unset($i, $choice);
		
				$result = db_query("
					INSERT INTO {$db_prefix}poll_choices
						(ID_POLL, ID_CHOICE, label)
					VALUES 
						" . implode(',
						', $insert), __FILE__, __LINE__);
				
				if (!$result)
				{
					// delete the poll we just created
					db_query("
						DELETE FROM {$db_prefix}polls
						WHERE ID_POLL = $poll_id
						LIMIT 1", __FILE__, __LINE__);
				
					fatal_error($txt['drafts'][20], false);
				}
				
				$topicOptions['poll'] = $poll_id;
				
				unset($insert, $poll_id, $poll);
			}
			
			// let's set some variables before we create the post
			$posterOptions['id'] = $member_id;
			
			$msgOptions['body'] = $row['body'];
			$msgOptions['subject'] = $row['subject'];
			$msgOptions['icon'] = $row['icon'];
			$msgOptions['smileys_enabled'] = $row['smileys_enabled'];
			
			$topicOptions['board'] = $row['board_id'];
			$topicOptions['id'] = $row['topic_id'];
			
			$is_topic = empty($topicOptions['id']);
			
			if ($is_topic)
			{
				$topicOptions['lock_mode'] = $row['locked'];
				$topicOptions['sticky_mode'] = $row['is_sticky'];
			}
			
			// tells createPost() to update the poster's post count
			$posterOptions['update_post_count'] = empty($row['count_posts']);
			
			// tells createPost() to mark the new topic/reply as read for the poster
			$topicOptions['mark_as_read'] = true;
			
			mysql_free_result($request);
			
			if (createPost($msgOptions, $topicOptions, $posterOptions))
			{
				global $board, $modSettings;
				
				$board = $topicOptions['board'];
				
				// delete the draft that was used
				db_query("
					DELETE FROM {$db_prefix}drafts
					WHERE draft_id = $draft_id
						AND member_id = $member_id
					LIMIT 1", __FILE__, __LINE__);
				
				if (!empty($topicOptions['lock_mode']))
					logAction('lock', array('topic' => $topicOptions['id']));
			
				if (!empty($topicOptions['sticky_mode']) && !empty($modSettings['enableStickyTopics']))
					logAction('sticky', array('topic' => $topicOptions['id']));
				
				require_once($sourcedir . '/Post.php');
				
				// Notify members of a new topic posted to this board
				if ($is_topic)
				{
					$_POST['message'] = $row['body'];
					$_POST['subject'] = $row['subject'];
					
					notifyMembersBoard();
				}
				// Notify members of a new reply posted to this topic
				else
					sendNotifications($topicOptions['id'], 'reply');
				
				redirectexit('board=' . $topicOptions['board'] . ';topic=' . $topicOptions['id'] . (!$is_topic ? '.msg' . $msgOptions['id'] . '#msg' . $msgOptions['id'] : '.0'));
			}
			
			// something went wrong
			fatal_error($txt['drafts'][20]);
		}
	}
	
	$context['list_of_drafts'] = drafts_load_list_of_drafts($member_id);
}

function drafts_load_list_of_boards()
{
	global $topic, $context;
	
	$context['list_of_boards'] = array();
	
	// has to be a new topic
	if (!empty($topic))
		return;

	// Get list of boards that can be posted in.
	$boards = boardsAllowedTo('post_new');
	
	if (empty($boards))
		fatal_lang_error('cannot_post_new');
		
	global $db_prefix, $user_info;
	
	$request = db_query("
		SELECT 
			b.ID_BOARD, b.name AS boardName, b.childLevel,
			c.name AS catName, c.ID_CAT
		FROM {$db_prefix}boards AS b
			LEFT JOIN {$db_prefix}categories AS c ON (c.ID_CAT = b.ID_CAT)
		WHERE $user_info[query_see_board]" . (in_array(0, $boards) ? '' : "
			AND b.ID_BOARD IN (" . implode(', ', $boards) . ")"), __FILE__, __LINE__);
	
	while ($row = mysql_fetch_assoc($request))
	{
		if (!isset($context['list_of_boards'][$row['ID_CAT']]))
			$context['list_of_boards'][$row['ID_CAT']] = array('name' => $row['catName'], 'boards' => array());
		
		$context['list_of_boards'][$row['ID_CAT']]['boards'][$row['ID_BOARD']] = array(
			'id' => $row['ID_BOARD'],
			'name' => $row['boardName'],
			'childLevel' => $row['childLevel'],
			'prefix' => str_repeat('&nbsp;', ($row['childLevel'] + 1) * 3)
		);
	}
	
	mysql_free_result($request);
}

?>