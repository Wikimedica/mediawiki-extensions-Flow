<?php

namespace Flow\Data\Listener;

use Flow\Attachment\AttachmentStore;
use Flow\Model\PostRevision;
use Flow\Model\PostSummary;
use Flow\Model\UUID;
use Flow\Model\Workflow;

/**
 * When a post or topic-summary revision is saved, find the
 * Special:FlowAttachment links in its content and bind those attachments to
 * the post (for a summary: the topic's root post, so visibility follows the
 * topic's moderation state). Attachment visibility follows the moderation
 * state of the bound post, and uploads made while composing a new topic get
 * re-homed from the board workflow to the topic workflow here.
 */
class AttachmentListener extends AbstractListener {

	private AttachmentStore $store;

	public function __construct( AttachmentStore $store ) {
		$this->store = $store;
	}

	/**
	 * @inheritDoc
	 */
	public function onAfterInsert( $revision, array $row, array $metadata ) {
		if ( !isset( $metadata['workflow'] ) ) {
			return;
		}
		if ( $revision instanceof PostRevision ) {
			$postId = $revision->getPostId();
		} elseif ( $revision instanceof PostSummary ) {
			// The summary target is the topic's root post
			$postId = $revision->getSummaryTargetId();
		} else {
			return;
		}
		/** @var Workflow $workflow */
		$workflow = $metadata['workflow'];

		// Works on both stored formats: html (href attribute) and wikitext
		// (external link target).
		if ( !preg_match_all(
			'~FlowAttachment/([0-9a-z]{16,19})(?:/|$|["\'\s?])~i',
			$revision->getContentRaw(),
			$matches
		) ) {
			return;
		}

		$ids = [];
		foreach ( array_unique( $matches[1] ) as $alnum ) {
			$ids[] = UUID::create( strtolower( $alnum ) );
		}

		$this->store->associateWithPost( $ids, $postId, $workflow->getId() );
	}
}
