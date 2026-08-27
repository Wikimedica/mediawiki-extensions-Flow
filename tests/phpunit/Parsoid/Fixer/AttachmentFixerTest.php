<?php

namespace Flow\Tests\Parsoid\Fixer;

use Flow\Attachment\AttachmentStore;
use Flow\Attachment\TopicAttachment;
use Flow\Model\UUID;
use Flow\Parsoid\ContentFixer;
use Flow\Parsoid\Fixer\AttachmentFixer;
use Flow\Tests\FlowTestCase;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;

/**
 * @covers \Flow\Parsoid\Fixer\AttachmentFixer
 *
 * @group Flow
 */
class AttachmentFixerTest extends FlowTestCase {

	protected function setUp(): void {
		parent::setUp();
		// The fixer renders for the requesting user: registered viewers see
		// the full attachment, anonymous viewers a placeholder
		\MediaWiki\Context\RequestContext::getMain()->setUser(
			\MediaWiki\User\User::newFromAnyId( 42, 'FixerTestUser', null )
		);
	}

	private function newAttachment( string $name, string $mime ): TopicAttachment {
		return TopicAttachment::create(
			UUID::create(),
			new UserIdentityValue( 42, 'TestUser' ),
			$name,
			2048,
			$mime,
			sha1( 'x' )
		);
	}

	private function newFixer( ?TopicAttachment $attachment ): AttachmentFixer {
		$store = $this->createMock( AttachmentStore::class );
		$store->method( 'getById' )->willReturnCallback(
			static fn ( UUID $id ) => (
				$attachment && $id->equals( $attachment->getId() ) ? $attachment : null
			)
		);
		return new AttachmentFixer( $store );
	}

	private function apply( AttachmentFixer $fixer, string $html ): string {
		return ( new ContentFixer( $fixer ) )->apply( $html, Title::newMainPage() );
	}

	public function testNonImageBecomesCard() {
		$attachment = $this->newAttachment( 'report.pdf', 'application/pdf' );
		$html = '<p>see <a rel="mw:ExtLink" href="' . htmlspecialchars( $attachment->getUrl() ) .
			'">report.pdf</a></p>';

		$result = $this->apply( $this->newFixer( $attachment ), $html );

		$this->assertStringContainsString( 'flow-attachment-card', $result );
		$this->assertStringContainsString( 'flow-attachment-card-name', $result );
		$this->assertStringContainsString( 'report.pdf', $result );
		$this->assertStringContainsString( 'download=', $result );
		$this->assertStringNotContainsString( '<img', $result );
	}

	public function testImageBecomesInlineImg() {
		$attachment = $this->newAttachment( 'photo.png', 'image/png' );
		$html = '<p><a rel="mw:ExtLink" href="' . htmlspecialchars( $attachment->getUrl() ) .
			'">photo.png</a></p>';

		$result = $this->apply( $this->newFixer( $attachment ), $html );

		$this->assertStringContainsString( 'flow-attachment-image', $result );
		$this->assertStringContainsString( '<img', $result );
		$this->assertStringContainsString( 'alt="photo.png"', $result );
	}

	public function testSvgIsNotInlined() {
		$attachment = $this->newAttachment( 'drawing.svg', 'image/svg+xml' );
		$html = '<p><a href="' . htmlspecialchars( $attachment->getUrl() ) . '">drawing.svg</a></p>';

		$result = $this->apply( $this->newFixer( $attachment ), $html );

		$this->assertStringContainsString( 'flow-attachment-card', $result );
		$this->assertStringNotContainsString( '<img', $result );
	}

	public function testMissingAttachmentIsMarked() {
		$html = '<p><a href="http://example.org/wiki/Special:FlowAttachment/' .
			UUID::create()->getAlphadecimal() . '/gone.png">gone.png</a></p>';

		$result = $this->apply( $this->newFixer( null ), $html );

		$this->assertStringContainsString( 'flow-attachment-missing', $result );
		$this->assertStringContainsString( 'gone.png', $result );
	}

	public function testStoredImgIsUpgraded() {
		$attachment = $this->newAttachment( 'photo.png', 'image/png' );
		$html = '<p><img src="' . htmlspecialchars( $attachment->getUrl() ) .
			'" alt="photo.png"></p>';

		$result = $this->apply( $this->newFixer( $attachment ), $html );

		$this->assertStringContainsString( 'flow-attachment-image', $result );
		$this->assertStringContainsString( '<img', $result );
	}

	public function testImgSizeIsPreserved() {
		$attachment = $this->newAttachment( 'photo.png', 'image/png' );
		$html = '<p><img src="' . htmlspecialchars( $attachment->getUrl() ) .
			'" alt="photo.png" width="240" height="180"></p>';

		$result = $this->apply( $this->newFixer( $attachment ), $html );

		$this->assertStringContainsString( 'width="240"', $result );
		$this->assertStringContainsString( 'height="180"', $result );
	}

	public function testLinkWithSizeQueryRendersSizedImage() {
		// After the wikitext storage round-trip, a resized image is stored as
		// a bare link whose URL carries the size as a query string
		$attachment = $this->newAttachment( 'photo.png', 'image/png' );
		$url = $attachment->getUrl() . '?w=240&h=180';
		$html = '<p><a rel="mw:ExtLink" class="external free" href="' .
			htmlspecialchars( $url ) . '">' . htmlspecialchars( $url ) . '</a></p>';

		$result = $this->apply( $this->newFixer( $attachment ), $html );

		$this->assertStringContainsString( '<img', $result );
		$this->assertStringContainsString( 'width="240"', $result );
		$this->assertStringContainsString( 'height="180"', $result );
	}

	public function testMissingImgIsReplacedWithoutBrokenImage() {
		$html = '<p><img src="http://example.org/wiki/Special:FlowAttachment/' .
			UUID::create()->getAlphadecimal() . '/gone.png" alt="gone.png"></p>';

		$result = $this->apply( $this->newFixer( null ), $html );

		$this->assertStringContainsString( 'flow-attachment-missing', $result );
		$this->assertStringNotContainsString( '<img', $result );
	}

	public function testAnonymousViewerSeesPlaceholderOnly() {
		\MediaWiki\Context\RequestContext::getMain()->setUser(
			\MediaWiki\User\User::newFromId( 0 )
		);
		$attachment = $this->newAttachment( 'secret-report.pdf', 'application/pdf' );
		$html = '<p><a href="' . htmlspecialchars( $attachment->getUrl() ) .
			'">secret-report.pdf</a> and <img src="' .
			htmlspecialchars( $attachment->getUrl() ) . '" alt="secret-report.pdf"></p>';

		$result = $this->apply( $this->newFixer( $attachment ), $html );

		$this->assertStringContainsString( 'flow-attachment-placeholder', $result );
		// Neither the file name, its URL, nor an <img> may leak
		$this->assertStringNotContainsString( 'secret-report.pdf', $result );
		$this->assertStringNotContainsString( 'FlowAttachment', $result );
		$this->assertStringNotContainsString( '<img', $result );
		$this->assertStringNotContainsString( '<a', $result );
	}

	public function testUnrelatedLinksUntouched() {
		$html = '<p><a rel="mw:ExtLink" href="http://example.org/some/page">a link</a></p>';

		$result = $this->apply( $this->newFixer( null ), $html );

		$this->assertStringNotContainsString( 'flow-attachment', $result );
		$this->assertStringContainsString( 'a link', $result );
	}
}
