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
}
