<?php

namespace Flow\Tests\Attachment;

use Flow\Attachment\AttachmentStore;
use Flow\Attachment\TopicAttachment;
use Flow\Container;
use Flow\Model\UUID;
use Flow\Tests\FlowTestCase;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\FileBackend\MemoryFileBackend;

/**
 * @covers \Flow\Attachment\AttachmentStore
 *
 * @group Flow
 * @group Database
 */
class AttachmentStoreTest extends FlowTestCase {

	private function newStore(): AttachmentStore {
		return new AttachmentStore(
			Container::get( 'db.factory' ),
			new MemoryFileBackend( [
				'name' => 'flow-attachments-test',
				'wikiId' => 'testwiki',
			] )
		);
	}

	private function newAttachment( ?UUID $workflowId = null ): TopicAttachment {
		return TopicAttachment::create(
			$workflowId ?? UUID::create(),
			new UserIdentityValue( 42, 'TestUser' ),
			'report.pdf',
			11,
			'application/pdf',
			sha1( 'file content' )
		);
	}

	private function makeTempFile(): string {
		$path = $this->getNewTempFile();
		file_put_contents( $path, 'file content' );
		return $path;
	}

	public function testInsertAndGetById() {
		$store = $this->newStore();
		$attachment = $this->newAttachment();

		$status = $store->insert( $attachment, $this->makeTempFile() );
		$this->assertStatusOK( $status );

		$this->assertTrue(
			$store->getBackend()->fileExists( [ 'src' => $store->getStoragePath( $attachment ) ] ),
			'file stored in the backend'
		);

		$loaded = $store->getById( $attachment->getId() );
		$this->assertNotNull( $loaded );
		$this->assertSame( 'report.pdf', $loaded->getName() );
		$this->assertSame( 'application/pdf', $loaded->getMime() );
		$this->assertNull( $loaded->getPostId() );
	}

	public function testGetByIdMissing() {
		$this->assertNull( $this->newStore()->getById( UUID::create() ) );
	}

	public function testGetByWorkflow() {
		$store = $this->newStore();
		$workflowId = UUID::create();

		$first = $this->newAttachment( $workflowId );
		$second = $this->newAttachment( $workflowId );
		$other = $this->newAttachment();
		foreach ( [ $first, $second, $other ] as $attachment ) {
			$store->insert( $attachment, $this->makeTempFile() );
		}

		$found = $store->getByWorkflow( $workflowId );
		$this->assertCount( 2, $found );
		// Ordered by id, which is timestamped: oldest first
		$this->assertTrue( $first->getId()->equals( $found[0]->getId() ) );
		$this->assertTrue( $second->getId()->equals( $found[1]->getId() ) );
	}

	public function testAssociateWithPost() {
		$store = $this->newStore();
		$boardWorkflowId = UUID::create();
		$topicWorkflowId = UUID::create();
		$postId = UUID::create();

		// Simulates an upload made while composing a new topic: initially
		// recorded against the board workflow
		$attachment = $this->newAttachment( $boardWorkflowId );
		$store->insert( $attachment, $this->makeTempFile() );

		$store->associateWithPost( [ $attachment->getId() ], $postId, $topicWorkflowId );

		$loaded = $store->getById( $attachment->getId() );
		$this->assertTrue( $postId->equals( $loaded->getPostId() ) );
		$this->assertTrue( $topicWorkflowId->equals( $loaded->getWorkflowId() ), 're-homed to the topic workflow' );

		// A second post referencing the same attachment must not steal it
		$store->associateWithPost( [ $attachment->getId() ], UUID::create(), $topicWorkflowId );
		$this->assertTrue( $postId->equals( $store->getById( $attachment->getId() )->getPostId() ) );
	}

	public function testAssociateWithPostEmptyList() {
		// Must not throw or issue a query
		$this->newStore()->associateWithPost( [], UUID::create(), UUID::create() );
		$this->assertTrue( true );
	}

	public function testDeleteOrphansBefore() {
		$store = $this->newStore();

		$orphan = $this->newAttachment();
		$store->insert( $orphan, $this->makeTempFile() );

		$bound = $this->newAttachment();
		$store->insert( $bound, $this->makeTempFile() );
		$store->associateWithPost( [ $bound->getId() ], UUID::create(), $bound->getWorkflowId() );

		// A cutoff in the past deletes nothing
		$this->assertSame( 0, $store->deleteOrphansBefore( wfTimestamp( TS_MW, time() - 3600 ) ) );
		$this->assertNotNull( $store->getById( $orphan->getId() ) );

		// A cutoff in the future deletes the orphan but not the bound one
		$this->assertSame( 1, $store->deleteOrphansBefore( wfTimestamp( TS_MW, time() + 3600 ) ) );
		$this->assertNull( $store->getById( $orphan->getId() ) );
		$this->assertNotNull( $store->getById( $bound->getId() ) );
		$this->assertFalse(
			$store->getBackend()->fileExists( [ 'src' => $store->getStoragePath( $orphan ) ] )
		);
	}

	public function testDelete() {
		$store = $this->newStore();
		$attachment = $this->newAttachment();
		$store->insert( $attachment, $this->makeTempFile() );

		$store->delete( $attachment );

		$this->assertNull( $store->getById( $attachment->getId() ) );
		$this->assertFalse(
			$store->getBackend()->fileExists( [ 'src' => $store->getStoragePath( $attachment ) ] )
		);
	}
}
