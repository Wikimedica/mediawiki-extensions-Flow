<?php

namespace Flow\Api;

use Flow\Collection\PostCollection;
use Flow\Container;
use Flow\Exception\FlowException;
use Flow\Model\UUID;
use Flow\WorkflowLoaderFactory;
use MediaWiki\Api\ApiBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * List the attachments of a Flow topic.
 *
 * Only registered users may list attachments; each attachment is filtered by
 * the moderation state of the post that references it, so files quoted in
 * hidden or deleted posts disappear from the list along with the post.
 */
class ApiFlowAttachmentList extends ApiBase {

	public function execute() {
		$user = $this->getUser();
		if ( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}

		$params = $this->extractRequestParams();
		$page = Title::newFromText( $params['page'] );
		if ( !$page || $page->getNamespace() !== NS_TOPIC ) {
			$this->dieWithError(
				[ 'apierror-invalidtitle', wfEscapeWikiText( $params['page'] ?? '' ) ], 'invalid-page'
			);
		}

		if ( !MediaWikiServices::getInstance()->getPermissionManager()
			->userCan( 'read', $user, $page )
		) {
			$this->dieWithError( 'apierror-readapidenied', 'permissiondenied' );
		}

		try {
			$workflowId = WorkflowLoaderFactory::uuidFromTitle( $page );
		} catch ( FlowException $e ) {
			$this->dieWithError(
				[ 'apierror-invalidtitle', wfEscapeWikiText( $params['page'] ) ], 'invalid-page'
			);
			return;
		}

		/** @var \Flow\Attachment\AttachmentStore $store */
		$store = MediaWikiServices::getInstance()->getService( 'FlowAttachmentStore' );
		/** @var \Flow\RevisionActionPermissions $permissions */
		$permissions = Container::get( 'permissions' );

		$attachments = [];
		foreach ( $store->getByWorkflow( $workflowId ) as $attachment ) {
			$postId = $attachment->getPostId();
			if ( !$postId ) {
				// Uploaded but never referenced by a saved post yet
				continue;
			}
			if ( !$this->isPostVisible( $postId, $permissions ) ) {
				continue;
			}
			$attachments[] = $attachment->toApiArray();
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'attachments' => $attachments,
		] );
	}

	private function isPostVisible( UUID $postId, $permissions ): bool {
		try {
			$revision = PostCollection::newFromId( $postId )->getLastRevision();
		} catch ( FlowException $e ) {
			return false;
		}
		return $permissions->isAllowed( $revision, 'view' );
	}

	public function isReadMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'page' => [
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
