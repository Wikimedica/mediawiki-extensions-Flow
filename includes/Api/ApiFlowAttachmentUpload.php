<?php

namespace Flow\Api;

use Flow\Attachment\TopicAttachment;
use Flow\Container;
use Flow\Exception\InvalidInputException;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MWFileProps;
use UploadBase;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Upload a file as a Flow topic attachment.
 *
 * This deliberately bypasses the wiki's normal File: upload stack: no
 * description page, no licensing form. The file is scoped to the topic (or,
 * while composing a new topic, to the board until the topic is committed) and
 * only ever served through Special:FlowAttachment, which enforces that the
 * viewer is a registered user allowed to see the topic.
 */
class ApiFlowAttachmentUpload extends ApiBase {

	/**
	 * One out of this many uploads schedules a deferred cleanup of orphaned
	 * attachments (uploads whose draft was abandoned before saving).
	 */
	private const ORPHAN_CLEANUP_CHANCE = 50;

	public function __construct( ApiMain $main, string $action ) {
		parent::__construct( $main, $action );
	}

	public function execute() {
		$user = $this->getUser();
		if ( !$user->isNamed() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}

		$params = $this->extractRequestParams();

		$page = Title::newFromText( $params['page'] );
		if ( !$page ) {
			$this->dieWithError(
				[ 'apierror-invalidtitle', wfEscapeWikiText( $params['page'] ) ], 'invalid-page'
			);
		}

		// Resolve the workflow this upload is scoped to and make sure the
		// user may contribute there.
		try {
			$workflow = Container::get( 'factory.loader.workflow' )
				->createWorkflowLoader( $page )
				->getWorkflow();
		} catch ( InvalidInputException $e ) {
			$this->dieWithError( $e->getMessageObject(), 'invalid-page' );
			return;
		}
		$errors = $workflow->getPermissionErrors( 'edit', $user, 'secure' );
		if ( $errors ) {
			$this->dieStatus( $this->errorArrayToStatus( $errors, $user ) );
		}

		$request = $this->getMain()->getRequest();
		$upload = $request->getUpload( 'file' );
		if ( !$upload->exists() ) {
			$this->dieWithError( [ 'apierror-missingparam', 'file' ], 'missing-file' );
		}
		if ( $upload->isIniSizeOverflow() ||
			$upload->getSize() > UploadBase::getMaxUploadSize( 'file' )
		) {
			$this->dieWithError( 'file-too-large', 'file-too-large' );
		}
		if ( $upload->getSize() <= 0 || $upload->getTempName() === null ) {
			$this->dieWithError( 'empty-file', 'empty-file' );
		}

		$name = $this->sanitizeName( $params['name'] ?? $upload->getName() ?? '' );
		if ( $name === '' ) {
			$this->dieWithError( 'flow-error-attachment-bad-name', 'bad-name' );
		}

		$pos = strrpos( $name, '.' );
		$ext = $pos === false ? '' : strtolower( substr( $name, $pos + 1 ) );

		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		// Same extension policy as normal wiki uploads
		if ( UploadBase::checkFileExtensionList( [ $ext ], $config->get( 'ProhibitedFileExtensions' ) ) ||
			( $config->get( 'CheckFileExtensions' ) && $config->get( 'StrictFileExtensions' ) &&
				!UploadBase::checkFileExtension( $ext, $config->get( 'FileExtensions' ) ) )
		) {
			$allowed = $config->get( 'FileExtensions' );
			$this->dieWithError(
				[ 'filetype-banned-type', $ext,
					$this->getLanguage()->commaList( $allowed ), 1, count( $allowed ) ],
				'filetype-banned'
			);
		}

		$tempPath = $upload->getTempName();
		$mwProps = new MWFileProps( $services->getMimeAnalyzer() );
		$props = $mwProps->getPropsFromPath( $tempPath, $ext );

		// Content verification (MIME consistency, embedded scripts, virus scan)
		$verification = $services->getUploadVerification()->verifyFile( $tempPath, $ext, $props );
		if ( $verification !== true ) {
			$this->dieWithError( $verification, 'verification-error' );
		}

		$attachment = TopicAttachment::create(
			$workflow->getId(),
			$user,
			$name,
			(int)$props['size'],
			$props['mime'],
			$props['sha1']
		);

		/** @var \Flow\Attachment\AttachmentStore $store */
		$store = $services->getService( 'FlowAttachmentStore' );
		$status = $store->insert( $attachment, $tempPath );
		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $attachment->toApiArray() );

		// Opportunistic garbage collection: once in a while, clean up old
		// orphaned uploads after the response has been sent. The age
		// threshold is generous because drafts (kept in the browser) may be
		// resumed and saved days after their attachments were uploaded.
		if ( mt_rand( 1, self::ORPHAN_CLEANUP_CHANCE ) === 1 ) {
			$maxAge = (int)$config->get( 'FlowAttachmentOrphanAge' );
			\MediaWiki\Deferred\DeferredUpdates::addCallableUpdate( static function () use ( $maxAge ) {
				/** @var \Flow\Attachment\AttachmentStore $store */
				$store = MediaWikiServices::getInstance()->getService( 'FlowAttachmentStore' );
				$store->deleteOrphansBefore( wfTimestamp( TS_MW, time() - $maxAge ) );
			} );
		}
	}

	/**
	 * Make an arbitrary client-supplied file name safe for storage in a
	 * Special-page subpage URL and a Content-Disposition header.
	 */
	private function sanitizeName( string $name ): string {
		// Strip any path components
		$name = preg_replace( '~^.*[/\\\\]~', '', $name );
		// Replace characters that are invalid in titles or problematic in URLs
		$name = preg_replace( '~[^\p{L}\p{N} .,\'()\[\]&+%!$-]+~u', '-', $name );
		$name = trim( preg_replace( '~[ .-]{2,}~', '-', $name ), '- ' );
		// Keep well below the 255-byte column limit, preserving the extension
		if ( strlen( $name ) > 200 ) {
			$pos = strrpos( $name, '.' );
			$ext = $pos === false ? '' : substr( $name, $pos );
			$name = mb_strcut( $name, 0, 200 - strlen( $ext ) ) . $ext;
		}
		return $name;
	}

	public function getAllowedParams() {
		return [
			'page' => [
				ParamValidator::PARAM_REQUIRED => true,
			],
			'name' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'file' => [
				ParamValidator::PARAM_TYPE => 'upload',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function needsToken() {
		return 'csrf';
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}
}
