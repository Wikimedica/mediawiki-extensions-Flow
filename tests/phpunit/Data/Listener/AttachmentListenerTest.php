<?php

namespace Flow\Tests\Data\Listener;

use Flow\Attachment\AttachmentStore;
use Flow\Data\Listener\AttachmentListener;
use Flow\Model\PostRevision;
use Flow\Model\PostSummary;
use Flow\Model\UUID;
use Flow\Model\Workflow;
use Flow\Tests\FlowTestCase;

/**
 * @covers \Flow\Data\Listener\AttachmentListener
 *
 * @group Flow
 */
class AttachmentListenerTest extends FlowTestCase {

	private function mockWorkflow( UUID $id ): Workflow {
		$workflow = $this->createMock( Workflow::class );
		$workflow->method( 'getId' )->willReturn( $id );
		return $workflow;
	}

	public function testAssociatesAttachmentsReferencedByPost() {
		$attachmentId = UUID::create();
		$otherId = UUID::create();
		$postId = UUID::create();
		$workflowId = UUID::create();

		$content = '<p>voir <a href="http://example.org/wiki/Sp%C3%A9cial:FlowAttachment/' .
			$attachmentId->getAlphadecimal() . '/doc.pdf">doc</a> et ' .
			'[http://example.org/wiki/Special:FlowAttachment/' . $otherId->getAlphadecimal() . '/x.png photo]</p>';

		$revision = $this->createMock( PostRevision::class );
		$revision->method( 'getPostId' )->willReturn( $postId );
		$revision->method( 'getContentRaw' )->willReturn( $content );

		$store = $this->createMock( AttachmentStore::class );
		$store->expects( $this->once() )
			->method( 'associateWithPost' )
			->with(
				$this->callback( function ( array $ids ) use ( $attachmentId, $otherId ) {
					$alnums = array_map( static fn ( UUID $id ) => $id->getAlphadecimal(), $ids );
					sort( $alnums );
					$expected = [ $attachmentId->getAlphadecimal(), $otherId->getAlphadecimal() ];
					sort( $expected );
					return $alnums === $expected;
				} ),
				$postId,
				$workflowId
			);

		( new AttachmentListener( $store ) )->onAfterInsert(
			$revision, [], [ 'workflow' => $this->mockWorkflow( $workflowId ) ]
		);
	}

	public function testAssociatesSummaryAttachmentsWithRootPost() {
		$attachmentId = UUID::create();
		$rootPostId = UUID::create();
		$workflowId = UUID::create();

		$summary = $this->createMock( PostSummary::class );
		$summary->method( 'getSummaryTargetId' )->willReturn( $rootPostId );
		$summary->method( 'getContentRaw' )->willReturn(
			'<p><a href="http://example.org/wiki/Special:FlowAttachment/' .
			$attachmentId->getAlphadecimal() . '/doc.pdf">doc</a></p>'
		);

		$store = $this->createMock( AttachmentStore::class );
		$store->expects( $this->once() )
			->method( 'associateWithPost' )
			->with( $this->anything(), $rootPostId, $workflowId );

		( new AttachmentListener( $store ) )->onAfterInsert(
			$summary, [], [ 'workflow' => $this->mockWorkflow( $workflowId ) ]
		);
	}

	public function testIgnoresContentWithoutAttachments() {
		$revision = $this->createMock( PostRevision::class );
		$revision->method( 'getPostId' )->willReturn( UUID::create() );
		$revision->method( 'getContentRaw' )->willReturn( '<p>no links here</p>' );

		$store = $this->createMock( AttachmentStore::class );
		$store->expects( $this->never() )->method( 'associateWithPost' );

		( new AttachmentListener( $store ) )->onAfterInsert(
			$revision, [], [ 'workflow' => $this->mockWorkflow( UUID::create() ) ]
		);
	}

	public function testIgnoresMissingWorkflowMetadata() {
		$revision = $this->createMock( PostRevision::class );
		$revision->method( 'getContentRaw' )->willReturn( 'irrelevant' );

		$store = $this->createMock( AttachmentStore::class );
		$store->expects( $this->never() )->method( 'associateWithPost' );

		( new AttachmentListener( $store ) )->onAfterInsert( $revision, [], [] );
	}
}
