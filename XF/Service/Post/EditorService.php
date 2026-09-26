<?php

declare(strict_types=1);

namespace foroPL\LimpiaCucarachas\XF\Service\Post;

use XF\Entity\Post;
use XF\Finder\SpamTriggerLogFinder;

class EditorService extends XFCP_EditorService
{
	/**
	 * Core only spam-checks edits to visible posts, so a message sitting in the
	 * approval queue can be rewritten without leaving any trace. Note those
	 * edits in the spam trigger log: the moderator still sees the originally
	 * flagged text next to the warning, and the rewrite itself is evidence of
	 * bad faith.
	 */
	public function checkForSpam()
	{
		$post = $this->post;
		$visitor = \XF::visitor();

		if ($post->message_state == 'moderated'
			&& $post->user_id == $visitor->user_id
			&& $visitor->isSpamCheckRequired()
		)
		{
			$this->noteEditInSpamLog($post);
		}

		parent::checkForSpam();
	}

	/**
	 * The approval queue shows the details of the oldest spam trigger log entry
	 * for the content, so the warning goes there to sit next to the originally
	 * flagged text.
	 */
	protected function noteEditInSpamLog(Post $post): void
	{
		$triggerLog = $this->finder(SpamTriggerLogFinder::class)
			->where('content_type', 'post')
			->where('content_id', $post->post_id)
			->order('log_date')
			->fetchOne();

		if (!$triggerLog)
		{
			return;
		}

		$details = $triggerLog->details_;

		foreach ($details AS $detail)
		{
			if (($detail['phrase'] ?? '') === 'foropl_lc_edited_after_flag')
			{
				return;
			}
		}

		$details[] = ['phrase' => 'foropl_lc_edited_after_flag'];

		$triggerLog->details = $details;
		$triggerLog->save();
	}
}
