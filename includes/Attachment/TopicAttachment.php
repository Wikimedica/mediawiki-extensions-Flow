<?php

namespace Flow\Attachment;

use Flow\Model\UUID;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentity;

/**
 * A file uploaded to a Flow topic.
 *
 * Attachments are scoped to a topic workflow: the file itself lives in the
 * flow-attachments FileBackend and is only ever served through
 * Special:FlowAttachment, which re-checks permissions on every request.
 */
class TopicAttachment {

	/**
	 * MIME types that may be rendered inline as an <img>. Everything else is
	 * rendered as a download card and served with an attachment disposition.
	 * SVG is deliberately excluded (scriptable content).
	 */
	private const INLINE_IMAGE_MIMES = [
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
	];

	private UUID $id;
	private UUID $workflowId;
	private ?UUID $postId;
	private int $userId;
	private string $name;
	private int $size;
	private string $mime;
	private string $sha1;

	private function __construct() {
	}

	public static function create(
		UUID $workflowId,
		UserIdentity $user,
		string $name,
		int $size,
		string $mime,
		string $sha1
	): TopicAttachment {
		$attachment = new self();
		$attachment->id = UUID::create();
		$attachment->workflowId = $workflowId;
		$attachment->postId = null;
		$attachment->userId = $user->getId();
		$attachment->name = $name;
		$attachment->size = $size;
		$attachment->mime = $mime;
		$attachment->sha1 = $sha1;

		return $attachment;
	}

	public static function fromStorageRow( \stdClass $row ): TopicAttachment {
		$attachment = new self();
		$attachment->id = UUID::create( $row->fa_id );
		$attachment->workflowId = UUID::create( $row->fa_workflow_id );
		$attachment->postId = $row->fa_post_id !== null ? UUID::create( $row->fa_post_id ) : null;
		$attachment->userId = (int)$row->fa_user_id;
		$attachment->name = $row->fa_name;
		$attachment->size = (int)$row->fa_size;
		$attachment->mime = $row->fa_mime;
		$attachment->sha1 = $row->fa_sha1;

		return $attachment;
	}

	public function toStorageRow(): array {
		return [
			'fa_id' => $this->id->getBinary(),
			'fa_workflow_id' => $this->workflowId->getBinary(),
			'fa_post_id' => $this->postId ? $this->postId->getBinary() : null,
			'fa_user_id' => $this->userId,
			'fa_name' => $this->name,
			'fa_size' => $this->size,
			'fa_mime' => $this->mime,
			'fa_sha1' => $this->sha1,
		];
	}

	public function getId(): UUID {
		return $this->id;
	}

	public function getWorkflowId(): UUID {
		return $this->workflowId;
	}

	public function getPostId(): ?UUID {
		return $this->postId;
	}

	public function getUserId(): int {
		return $this->userId;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getSize(): int {
		return $this->size;
	}

	public function getMime(): string {
		return $this->mime;
	}

	public function getSha1(): string {
		return $this->sha1;
	}

	/**
	 * Whether this file may be displayed inline as an image.
	 */
	public function isInlineImage(): bool {
		return in_array( $this->mime, self::INLINE_IMAGE_MIMES, true );
	}

	public function getExtension(): string {
		$pos = strrpos( $this->name, '.' );
		return $pos === false ? '' : strtolower( substr( $this->name, $pos + 1 ) );
	}

	/**
	 * The Special:FlowAttachment title serving this file.
	 */
	public function getTitle(): \MediaWiki\Title\Title {
		return SpecialPage::getTitleFor(
			'FlowAttachment',
			$this->id->getAlphadecimal() . '/' . $this->name
		);
	}

	/**
	 * Full URL of the serving endpoint. Used as the href the editors insert,
	 * so it must be stable and canonical.
	 */
	public function getUrl(): string {
		return $this->getTitle()->getFullURL();
	}

	/**
	 * Serialize for API output and JS consumption.
	 */
	public function toApiArray(): array {
		return [
			'id' => $this->id->getAlphadecimal(),
			'workflowId' => $this->workflowId->getAlphadecimal(),
			'postId' => $this->postId ? $this->postId->getAlphadecimal() : null,
			'userId' => $this->userId,
			'name' => $this->name,
			'size' => $this->size,
			'mime' => $this->mime,
			'isImage' => $this->isInlineImage(),
			'url' => $this->getUrl(),
			'timestamp' => $this->id->getTimestamp(),
		];
	}
}
