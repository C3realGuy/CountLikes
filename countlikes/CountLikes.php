<?php

function cl_profile_areas(&$profile_areas)
{
	global $txt, $context;

	loadPluginLanguage('CerealGuy:CountLikes', 'CountLikes');
	$insert = array('showlikes' => array(
		'label' => $txt['cl_show_likes'],
		'enabled' => true,
		'function' => 'showLikes',
		'permission' => array(
			'own' => 'profile_view_own',
			'any' => 'profile_view_any',
		),
		'subsections' => array(
			'received' => array($txt['cl_show_received']),
			'given' => array($txt['cl_show_given']),
		),
	));
	$profile_areas = array_insert($profile_areas, 'info areas showposts', $insert, true);
	if (empty($_GET['area']))
	{
		loadPluginLanguage('CerealGuy:CountLikes', 'CountLikes');
		$query_arr = array('id_member' => $context['member']['id']);
		$query = wesql::query('SELECT count(id_content) FROM {db_prefix}likes WHERE id_member = {int:id_member} AND content_type = "post"', $query_arr);
		list ($context['given_likes']) = wesql::fetch_row($query);
		$query = wesql::query('SELECT count(id_content) FROM {db_prefix}likes a LEFT JOIN {db_prefix}messages b ON a.id_content=b.id_msg WHERE a.content_type = "post" AND b.id_member = {int:id_member}', $query_arr);
		list ($context['received_likes']) = wesql::fetch_row($query);
	}
}

function showLikes($memID)
{
	global $txt, $context, $settings;

	$context[$context['profile_menu_name']]['tab_data'] = array(
		'title' => $txt['cl_show_likes'],
		'description' => $txt['cl_show_help'],
		'icon' => 'profile_sm.gif',
		'tabs' => array(
			'received' => array(
			),
			'given' => array(
			),
		),
	);
	// Init
	$context['is_given'] = isset($_GET['sa']) && $_GET['sa'] == 'given' ? true : false;
	$queryArr =  array("id_member" => $memID);
	$context['start'] = (int) $_REQUEST['start'];
	$context['current_member'] = $memID;
	// Default to 10.
	if (empty($_REQUEST['viewscount']) || !is_numeric($_REQUEST['viewscount']))
		$_REQUEST['viewscount'] = '10';
	$settings['defaultMaxMessages'] = 10;
	$reverse = false;
	$range_limit = '';
	$maxIndex = (int) $settings['defaultMaxMessages'];
	if ($context['is_given'])
		$request = wesql::query('SELECT count(id_content) FROM {db_prefix}likes WHERE id_member = {int:id_member} AND content_type = "post"', $queryArr);
	else
		$request = wesql::query('SELECT count(id_content) FROM {db_prefix}likes a LEFT JOIN {db_prefix}messages b ON a.id_content=b.id_msg WHERE a.content_type = "post" AND b.id_member = {int:id_member}', $queryArr);
	list ($countLikes) = wesql::fetch_row($request);
	wesql::free_result($request);

	$request = wesql::query('SELECT MIN(id_content), MAX(id_content) FROM {db_prefix}likes a LEFT JOIN {db_prefix}messages b ON a.id_content=b.id_msg WHERE a.content_type = "post" AND b.id_member = {int:id_member}',$queryArr);
	list ($min_msg_member, $max_msg_member) = wesql::fetch_row($request);
	wesql::free_result($request);
	// Make sure the starting place makes sense and construct our friend the page index.
	$context['page_index'] = template_page_index('<URL>?action=profile;area=cl_posts' . (!empty($board) ? ';board=' . $board : ''), $context['start'], $countLikes, $maxIndex);
	$context['current_page'] = $context['start'] / $maxIndex;
	// Reverse the query if we're past 50% of the pages for better performance.
	$start = $context['start'];
	$reverse = $_REQUEST['start'] > $countLikes / 2;
	if ($reverse)
		$maxIndex = $msgCount < $context['start'] + $settings['defaultMaxMessages'] + 1 && $countLikes > $context['start'] ? $countLikes - $context['start'] : (int) $settings['defaultMaxMessages'];

	// Guess the range of messages to be shown.
	if ($countLikes > 1000)
	{
		$margin = floor(($max_msg_member - $min_msg_member) * (($start + $settings['defaultMaxMessages']) / $countLikes) + .1 * ($max_msg_member - $min_msg_member));
		// Make a bigger margin for topics only.
		if ($context['is_topics'])
		{
			$margin *= 5;
			$range_limit = $reverse ? 't.id_first_msg < ' . ($min_msg_member + $margin) : 't.id_first_msg > ' . ($max_msg_member - $margin);
		}
		else
			$range_limit = $reverse ? 'm.id_msg < ' . ($min_msg_member + $margin) : 'm.id_msg > ' . ($max_msg_member - $margin);
	}


	// Get Likes & Posts & info & stuff... lots to get
	$base_query = 'SELECT b.id_msg, b.id_topic, b.id_board, b.poster_time, b.id_member, b.subject, b.poster_name, b.body, b.smileys_enabled, c.name as bname, d.member_name, c.id_cat, e.name as cname FROM {db_prefix}likes a LEFT JOIN {db_prefix}messages b ON a.id_content=b.id_msg LEFT JOIN {db_prefix}boards c ON c.id_board=b.id_board LEFT JOIN {db_prefix}members d ON d.id_member=b.id_member LEFT JOIN {db_prefix}categories e ON e.id_cat = c.id_cat WHERE a.content_type = "post"';
	$oder_query = 'ORDER BY a.like_time DESC';
	$limit_query = 'LIMIT ' . $start . ', ' . $maxIndex;
	if ($context['is_given'])
		$request = wesql::query($base_query.'  AND a.id_member = {int:id_member} '.$oder_query.' '.$limit_query, $queryArr);
	else
		$request = wesql::query($base_query.' AND b.id_member = {int:id_member} '.$oder_query.' '.$limit_query, $queryArr);
	$msgs = array();
	$boards = array();
	$categories = array();
	$counter = 1;
	while ($row = wesql::fetch_assoc($request))
	{
		$context['posts'][] = array(
			'can_see' => true,
			'user_href' => "<a href=\"<URL>?action=profile;u={$row['id_member']}\">{$row['member_name']}</a>",
			'body' => parse_bbc($row['body'], 'post', array('smileys' => $row['smileys_enabled'], 'cache' => $row['id_msg'], 'user' => $memID)),
			'counter' => $counter,
			'alternate' => 1,
			'category' => array(
				'name' => $row['cname'],
				'id' => $row['id_cat'],
			),
			'board' => array(
				'name' => $row['bname'],
				'id' => $row['id_board'],
			),
			'topic' => $row['id_topic'],
			'subject' => $row['subject'],
			'start' => 'msg' . $row['id_msg'],
			'on_time' => on_timeformat($row['poster_time']),
			'timestamp' => $row['poster_time'],
			'id' => $row['id_msg'],
			'can_reply' => 1,
			'can_delete' => 0,
			'delete_possible' => null,
			'approved' => 1,
			'can_quote' => 1,
		);
		$msgs[] = $row['id_msg'];
		$counter++;
	}

	loadSource('Display');
	loadTemplate('Msg');
	prepareLikeContext($msgs);
	wetem::load('showLikes');
}

function template_showLikes()
{
	global $context, $txt, $settings;

	echo '<div class="pagesection">
			<nav>', $txt['pages'], ': ', $context['page_index'], '</nav>
		</div>';

	$remove_confirm = JavaScriptEscape($txt['remove_message_confirm']);

	if (!empty($context['posts']))
	{
		// For every post to be displayed, give it its own div, and show the important details of the post.
		foreach ($context['posts'] as $post)
		{
			echo '
		<div class="topic">
			<div class="windowbg', $post['alternate'] == 0 ? '2' : '', ' wrc core_posts">
				<div class="counter">', $post['counter'], '</div>
				<div class="topic_details">';

			if ($post['can_see'])
				echo '
					<h5><strong><a href="<URL>?board=', $post['board']['id'], '.0">', $post['board']['name'], '</a> / <a href="<URL>?topic=', $post['topic'], '.', $post['start'], '#msg', $post['id'], '">', $post['subject'], '</a></strong></h5>';
			else
				echo '
					<h5 title="', $txt['board_off_limits'], '"><strong>', $post['board']['name'], ' / ', $post['subject'], '</strong></h5>';

			echo '		<span class="smalltext">'.$post['user_href'].'</span>
					<span class="smalltext">«&nbsp;', $post['on_time'], '&nbsp;»</span>
				</div>
				<div class="list_posts">';

			if (!$post['approved'])
				echo '
					<div class="approve_post">
						<em>', $txt['post_awaiting_approval'], '</em>
					</div>';

			echo '
					', $post['body'], '
				</div>';

			if ($post['can_reply'] || $post['can_quote'] || $post['can_delete'] || (!empty($settings['likes_enabled']) && !empty($context['liked_posts'][$post['id']])))
			{
				echo '
				<div class="actionbar">
					<ul class="actions">';

				// If they *can* reply?
				if ($post['can_reply'])
					echo '
						<li><a href="<URL>?action=post;topic=', $post['topic'], '.', $post['start'], '" class="reply_button">', $txt['reply'], '</a></li>';

				// If they *can* quote?
				if ($post['can_quote'])
					echo '
						<li><a href="<URL>?action=post;topic=', $post['topic'], '.', $post['start'], ';quote=', $post['id'], '" class="quote_button">', $txt['quote'], '</a></li>';

				// How about... even... remove it entirely?!
				if ($post['can_delete'])
					echo '
						<li><a href="<URL>?action=deletemsg;msg=', $post['id'], ';topic=', $post['topic'], ';profile;u=', $context['member']['id'], ';start=', $context['start'], ';', $context['session_query'], '" class="remove_button" onclick="return ask(', $remove_confirm, ', e);">', $txt['remove'], '</a></li>';

				echo '
					</ul>';

				if (!empty($settings['likes_enabled']) && !empty($context['liked_posts'][$post['id']]))
					template_show_likes($post['id'], false);

				echo '
				</div>';
			}

			echo '
			</div>
		</div>';
		}
	}
	else
	{
		echo '<div class="windowbg2 padding center">
			'.($context['is_given'] ? $txt['cl_no_given'] :  $txt['cl_no_received']).'
		</div>';
	
	}
	// Show more page numbers.
	echo '
		<div class="pagesection" style="margin-bottom: 0">
			<nav>', $txt['pages'], ': ', $context['page_index'], '</nav>
		</div>';
}
