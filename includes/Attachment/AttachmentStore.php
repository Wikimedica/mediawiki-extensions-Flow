<?php

namespace Flow\Attachment;

use Flow\DbFactory;
use Flow\Model\UUID;
use StatusValue;
use Wikimedia\FileBackend\FileBackend;

/**
 * Persistence for topic attachments: metadata rows in flow_topic_attachment
 * and file contents in a private FileBackend that is never web-served
 * directly.
 */
class AttachmentStore {

	public const CONTAINER = 'flow-attachments';

	private DbFactory $dbFactory;
	private FileBackend $backend;

	public function __construct( DbFactory $dbFactory, FileBackend $backend ) {
		$this->dbFactory = $dbFactory;
		$this->backend = $backend;
	}

	public function getBackend(): FileBackend {
		return $this->backend;
	}

	/**
	 * Storage path of an attachment's file within the backend.
	 */
	public function getStoragePath( TopicAttachment $attachment ): string {
		$id = $attachment->getId()->getAlphadecimal();
		$ext = $attachment->getExtension();

		return $this->backend->getContainerStoragePath( self::CONTAINER ) .
			'/' . substr( $id, 0, 2 ) .
			'/' . $id . ( $ext !== '' ? ".$ext" : '' );
	}

	/**
	 * Store the uploaded file and insert the metadata row.
	 */
	public function insert( TopicAttachment $attachment, string $tempPath ): StatusValue {
		$dst = $this->getStoragePath( $attachment );

		$status = $this->backend->prepare( [
			'dir' => dirname( $dst ),
			'noAccess' => true,
			'noListing' => true,
		] );
		if ( !$status->isOK() ) {
			return $status;
		}

		$status = $this->backend->store( [
			'src' => $tempPath,
			'dst' => $dst,
			'overwriteSame' => true,
		] );
		if ( !$status->isOK() ) {
			return $status;
		}

		$this->dbFactory->getDB( DB_PRIMARY )->newInsertQueryBuilder()
			->insertInto( 'flow_topic_attachment' )
			->row( $attachment->toStorageRow() )
			->caller( __METHOD__ )
			->execute();

		return StatusValue::newGood();
	}

	public function getById( UUID $id ): ?TopicAttachment {
		$row = $this->dbFactory->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( '*' )
			->from( 'flow_topic_attachment' )
			->where( [ 'fa_id' => $id->getBinary() ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ? TopicAttachment::fromStorageRow( $row ) : null;
	}

	/**
	 * @param UUID[] $ids
	 * @return TopicAttachment[] keyed by alphadecimal id
	 */
	public function getByIds( array $ids ): array {
		if ( !$ids ) {
			return [];
		}
		$res = $this->dbFactory->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( '*' )
			->from( 'flow_topic_attachment' )
			->where( [ 'fa_id' => array_map( static fn ( UUID $id ) => $id->getBinary(), $ids ) ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$attachments = [];
		foreach ( $res as $row ) {
			$attachment = TopicAttachment::fromStorageRow( $row );
			$attachments[$attachment->getId()->getAlphadecimal()] = $attachment;
		}
		return $attachments;
	}

	/**
	 * All attachments of a topic workflow, oldest first.
	 *
	 * @return TopicAttachment[]
	 */
	public function getByWorkflow( UUID $workflowId ): array {
		$res = $this->dbFactory->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( '*' )
			->from( 'flow_topic_attachment' )
			->where( [ 'fa_workflow_id' => $workflowId->getBinary() ] )
			->orderBy( 'fa_id' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$attachments = [];
		foreach ( $res as $row ) {
			$attachments[] = TopicAttachment::fromStorageRow( $row );
		}
		return $attachments;
	}

	/**
	 * Bind not-yet-associated attachments to the post that references them.
	 *
	 * Also moves the attachment to the topic workflow: uploads made while
	 * composing a brand-new topic are initially recorded against the board
	 * workflow, because the topic does not exist yet.
	 *
	 * @param UUID[] $ids
	 */
	public function associateWithPost( array $ids, UUID $postId, UUID $workflowId ): void {
		if ( !$ids ) {
			return;
		}
		$this->dbFactory->getDB( DB_PRIMARY )->newUpdateQueryBuilder()
			->update( 'flow_topic_attachment' )
			->set( [
				'fa_post_id' => $postId->getBinary(),
				'fa_workflow_id' => $workflowId->getBinary(),
			] )
			->where( [
				'fa_id' => array_map( static fn ( UUID $id ) => $id->getBinary(), $ids ),
				'fa_post_id' => null,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Permanently remove an attachment: the file and its metadata row.
	 */
	public function delete( TopicAttachment $attachment ): void {
		$this->backend->quickDelete( [
			'src' => $this->getStoragePath( $attachment ),
			'ignoreMissingSource' => true,
		] );
		$this->dbFactory->getDB( DB_PRIMARY )->newDeleteQueryBuilder()
			->deleteFrom( 'flow_topic_attachment' )
			->where( [ 'fa_id' => $attachment->getId()->getBinary() ] )
			->caller( __METHOD__ )
			->execute();
	}
}
