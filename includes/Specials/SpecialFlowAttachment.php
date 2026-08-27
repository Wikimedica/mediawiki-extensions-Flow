<?php

namespace Flow\Specials;

use Flow\Attachment\AttachmentStore;
use Flow\Attachment\TopicAttachment;
use Flow\Collection\PostCollection;
use Flow\Container;
use Flow\Exception\FlowException;
use Flow\Model\UUID;
use Flow\Model\Workflow;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Serves Flow topic attachments: Special:FlowAttachment/<id>/<filename>.
 *
 * Every request re-checks that the viewer is a registered user, may read the
 * topic the attachment belongs to, and — because attachment visibility
 * follows the post that references it — may view that post's current
 * moderation state.
 */
class SpecialFlowAttachment extends SpecialPage {

	public function __construct() {
		parent::__construct( 'FlowAttachment', '', /* listed */ false );
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireNamedUser( 'flow-error-attachment-login-required' );

		$parts = explode( '/', $subPage ?? '', 2 );
		$attachment = null;
		if ( $parts[0] !== '' ) {
			try {
				$attachment = $this->getStore()->getById( UUID::create( strtolower( $parts[0] ) ) );
			} catch ( FlowException $e ) {
				// invalid id format; treated as not found
			}
		}

		if ( !$attachment || !$this->userCanView( $attachment ) ) {
			// One error for both cases: do not reveal whether the id exists
			$this->getOutput()->setStatusCode( 404 );
			$this->getOutput()->addWikiMsg( 'flow-error-attachment-not-found' );
			return;
		}

		$this->streamAttachment( $attachment );
	}

	private function getStore(): AttachmentStore {
		return MediaWikiServices::getInstance()->getService( 'FlowAttachmentStore' );
	}

	private function userCanView( TopicAttachment $attachment ): bool {
		$user = $this->getUser();

		$postId = $attachment->getPostId();
		if ( !$postId ) {
			// Not referenced by any saved post yet: only the uploader may
			// fetch it (e.g. while still composing the reply). No workflow
			// check here: while composing a new topic, the recorded workflow
			// may not even be stored yet (the workflow loader hands the
			// upload API an unsaved workflow for boards whose content does
			// not reference one).
			return $user->getId() === $attachment->getUserId();
		}

		// The topic must be readable
		/** @var Workflow|null $workflow */
		$workflow = Container::get( 'storage.workflow' )->get( $attachment->getWorkflowId() );
		if ( !$workflow ) {
			return false;
		}
		if ( !MediaWikiServices::getInstance()->getPermissionManager()
			->userCan( 'read', $user, $workflow->getArticleTitle() )
		) {
			return false;
		}

		// Visibility follows the moderation state of the referencing post
		try {
			$revision = PostCollection::newFromId( $postId )->getLastRevision();
		} catch ( FlowException $e ) {
			return false;
		}
		return Container::get( 'permissions' )->isAllowed( $revision, 'view' );
	}

	private function streamAttachment( TopicAttachment $attachment ): void {
		$store = $this->getStore();
		$path = $store->getStoragePath( $attachment );

		if ( !$store->getBackend()->fileExists( [ 'src' => $path ] ) ) {
			$this->getOutput()->setStatusCode( 404 );
			$this->getOutput()->addWikiMsg( 'flow-error-attachment-not-found' );
			return;
		}

		$this->getOutput()->disable();

		if ( $attachment->isInlineImage() ) {
			$disposition = 'inline';
			$contentType = $attachment->getMime();
		} else {
			// Force a download for anything that is not a known-safe image,
			// so active content (SVG, HTML-ish files) never executes in the
			// wiki's origin.
			$disposition = 'attachment';
			$contentType = $attachment->getMime() ?: 'application/octet-stream';
		}
		// RFC 5987 encoding for non-ASCII names
		$fileName = rawurlencode( $attachment->getName() );

		$store->getBackend()->streamFile( [
			'src' => $path,
			'headers' => [
				"Content-Type: $contentType",
				"Content-Disposition: $disposition; filename*=UTF-8''$fileName",
				'Content-Security-Policy: default-src \'none\'; sandbox',
				'X-Content-Type-Options: nosniff',
				'Cache-Control: private, max-age=3600, must-revalidate',
				'Vary: Cookie',
			],
		] );
	}
}
