<?php

namespace Flow\Tests\Attachment;

use Flow\Attachment\TopicAttachment;
use Flow\Model\UUID;
use Flow\Tests\FlowTestCase;
use MediaWiki\User\UserIdentityValue;

/**
 * @covers \Flow\Attachment\TopicAttachment
 *
 * @group Flow
 */
class TopicAttachmentTest extends FlowTestCase {

	private function newAttachment( string $name = 'report.pdf', string $mime = 'application/pdf' ): TopicAttachment {
		return TopicAttachment::create(
			UUID::create(),
			new UserIdentityValue( 42, 'TestUser' ),
			$name,
			1234,
			$mime,
			sha1( 'contents' )
		);
	}

	public function testCreateAndAccessors() {
		$workflowId = UUID::create();
		$attachment = TopicAttachment::create(
			$workflowId,
			new UserIdentityValue( 42, 'TestUser' ),
			'report.pdf',
			1234,
			'application/pdf',
			'abc123'
		);

		$this->assertInstanceOf( UUID::class, $attachment->getId() );
		$this->assertTrue( $workflowId->equals( $attachment->getWorkflowId() ) );
		$this->assertNull( $attachment->getPostId() );
		$this->assertSame( 42, $attachment->getUserId() );
		$this->assertSame( 'report.pdf', $attachment->getName() );
		$this->assertSame( 1234, $attachment->getSize() );
		$this->assertSame( 'application/pdf', $attachment->getMime() );
		$this->assertSame( 'abc123', $attachment->getSha1() );
		$this->assertSame( 'pdf', $attachment->getExtension() );
	}

	public function testStorageRowRoundTrip() {
		$attachment = $this->newAttachment();

		// Binary UUIDs are wrapped in Blob objects for the DB layer; a fetched
		// row would contain the raw string
		$row = (object)array_map(
			static fn ( $value ) => $value instanceof \Wikimedia\Rdbms\Blob ? $value->fetch() : $value,
			$attachment->toStorageRow()
		);
		$restored = TopicAttachment::fromStorageRow( $row );

		$this->assertTrue( $attachment->getId()->equals( $restored->getId() ) );
		$this->assertTrue( $attachment->getWorkflowId()->equals( $restored->getWorkflowId() ) );
		$this->assertNull( $restored->getPostId() );
		$this->assertSame( $attachment->getUserId(), $restored->getUserId() );
		$this->assertSame( $attachment->getName(), $restored->getName() );
		$this->assertSame( $attachment->getSize(), $restored->getSize() );
		$this->assertSame( $attachment->getMime(), $restored->getMime() );
		$this->assertSame( $attachment->getSha1(), $restored->getSha1() );
	}

	public static function provideInlineImageMimes() {
		return [
			[ 'image/png', true ],
			[ 'image/jpeg', true ],
			[ 'image/gif', true ],
			[ 'image/webp', true ],
			// SVG can contain scripts: never inline
			[ 'image/svg+xml', false ],
			[ 'application/pdf', false ],
			[ 'text/plain', false ],
		];
	}

	/**
	 * @dataProvider provideInlineImageMimes
	 */
	public function testIsInlineImage( string $mime, bool $expected ) {
		$this->assertSame( $expected, $this->newAttachment( 'f.bin', $mime )->isInlineImage() );
	}

	public function testGetTitleAndUrl() {
		$attachment = $this->newAttachment();
		$title = $attachment->getTitle();

		$this->assertTrue( $title->isSpecialPage() );
		$this->assertStringContainsString(
			'FlowAttachment/' . $attachment->getId()->getAlphadecimal() . '/report.pdf',
			$title->getDBkey() . '/'
		);
		$this->assertStringContainsString( $attachment->getId()->getAlphadecimal(), $attachment->getUrl() );
	}

	public function testToApiArray() {
		$attachment = $this->newAttachment( 'photo.png', 'image/png' );
		$data = $attachment->toApiArray();

		$this->assertSame( $attachment->getId()->getAlphadecimal(), $data['id'] );
		$this->assertSame( 'photo.png', $data['name'] );
		$this->assertSame( 1234, $data['size'] );
		$this->assertTrue( $data['isImage'] );
		$this->assertNull( $data['postId'] );
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'timestamp', $data );
	}
}
