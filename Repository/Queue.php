<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\Repository;

use XF\Finder\PostFinder;
use XF\Finder\ProfilePostCommentFinder;
use XF\Finder\ProfilePostFinder;
use XF\Finder\ThreadFinder;
use XF\Mvc\Entity\Repository;

class Queue extends Repository
{
	public function getModeratedContentCount(int $userId): int
	{
		$posts = $this->finder(PostFinder::class)
			->where('user_id', $userId)
			->where('message_state', 'moderated')
			->total();
		$threads = $this->finder(ThreadFinder::class)
			->where('user_id', $userId)
			->where('discussion_state', 'moderated')
			->total();
		$profilePosts = $this->finder(ProfilePostFinder::class)
			->where('user_id', $userId)
			->where('message_state', 'moderated')
			->total();
		$profilePostComments = $this->finder(ProfilePostCommentFinder::class)
			->where('user_id', $userId)
			->where('message_state', 'moderated')
			->total();

		return $posts + $threads + $profilePosts + $profilePostComments;
	}

	/**
	 * Counts the messages a user has published since the given timestamp across
	 * every message table: posts (which include a thread's first post), profile
	 * posts and profile post comments. Threads are not counted separately to
	 * avoid double-counting their first post.
	 */
	public function getRecentContentCount(int $userId, int $since): int
	{
		return (int) $this->db()->fetchOne("
			SELECT
				(SELECT COUNT(*) FROM xf_post
					WHERE user_id = ? AND post_date >= ? AND message_state <> 'deleted')
				+
				(SELECT COUNT(*) FROM xf_profile_post
					WHERE user_id = ? AND post_date >= ? AND message_state <> 'deleted')
				+
				(SELECT COUNT(*) FROM xf_profile_post_comment
					WHERE user_id = ? AND comment_date >= ? AND message_state <> 'deleted')
				AS total
		", [$userId, $since, $userId, $since, $userId, $since]);
	}
}
