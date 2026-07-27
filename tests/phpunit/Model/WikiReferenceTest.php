<?php

namespace Flow\Tests\Model;

use Flow\Exception\InvalidReferenceException;
use Flow\Model\UUID;
use Flow\Model\WikiReference;
use Flow\Tests\FlowTestCase;

/**
 * @covers \Flow\Model\WikiReference
 *
 * @group Flow
 */
class WikiReferenceTest extends FlowTestCase {

	private function getStorageRow( array $overrides = [] ): array {
		return $overrides + [
			'ref_id' => UUID::create()->getBinary(),
			'ref_src_wiki' => 'unittest',
			'ref_src_workflow_id' => UUID::create()->getBinary(),
			'ref_src_object_type' => 'post',
			'ref_src_object_id' => UUID::create()->getBinary(),
			'ref_src_namespace' => NS_TALK,
			'ref_src_title' => 'Some_topic',
			'ref_target_namespace' => NS_MAIN,
			'ref_target_title' => 'Some_target',
			'ref_type' => 'link',
		];
	}

	public function testFromStorageRowLoadsValidRow() {
		$reference = WikiReference::fromStorageRow( $this->getStorageRow() );
		$this->assertSame( 'Some_target', $reference->getTitle()->getPrefixedDBkey() );
	}

	public function testFromStorageRowThrowsOnUnparseableTargetTitle() {
		$this->expectException( InvalidReferenceException::class );
		WikiReference::fromStorageRow( $this->getStorageRow( [
			// e.g. a namespace that has since been unregistered
			'ref_target_namespace' => 99887766,
		] ) );
	}

	public function testFromStorageRowThrowsOnUnparseableSourceTitle() {
		$this->expectException( InvalidReferenceException::class );
		WikiReference::fromStorageRow( $this->getStorageRow( [
			'ref_src_namespace' => 99887766,
		] ) );
	}

	public function testExceptionPreservesDetailsForDebugLogging() {
		try {
			WikiReference::fromStorageRow( $this->getStorageRow( [
				'ref_target_namespace' => 99887766,
				'ref_target_title' => 'Retired_page',
			] ) );
			$this->fail( 'Expected InvalidReferenceException' );
		} catch ( InvalidReferenceException $e ) {
			$this->assertStringContainsString( '99887766:Retired_page', $e->getDebugMessage() );
		}
	}
}
