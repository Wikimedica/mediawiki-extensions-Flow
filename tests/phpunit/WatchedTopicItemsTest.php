<?php

namespace Flow\Tests;

use Flow\Model\UUID;
use Flow\WatchedTopicItems;
use MediaWiki\User\User;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \Flow\WatchedTopicItems
 *
 * @group Flow
 */
class WatchedTopicItemsTest extends FlowTestCase {

	public static function provideDataGetWatchStatus() {
		// number of test cases
		$testCount = 10;
		$tests = [];
		while ( $testCount > 0 ) {
			$testCount--;
			// number of uuid per test case
			$uuidCount = 10;
			$uuids = $dbResult = $result = [];
			while ( $uuidCount > 0 ) {
				$uuidCount--;
				$uuid = UUID::create()->getAlphadecimal();
				$rand = rand( 0, 1 );
				// put in the query result
				if ( $rand ) {
					$dbResult[] = (object)[ 'wl_title' => $uuid ];
					$result[$uuid] = true;
				} else {
					$result[$uuid] = false;
				}
				$uuids[] = $uuid;
			}
			$dbResult = new \ArrayObject( $dbResult );
			$tests[] = [ $uuids, $dbResult->getIterator(), $result ];
		}

		// attach empty uuids array to query
		$uuids = $dbResult = $result = [];
		$emptyCount = 10;
		while ( $emptyCount > 0 ) {
			$emptyCount--;
			$uuid = UUID::create()->getAlphadecimal();
			$dbResult[] = (object)[ 'wl_title' => $uuid ];
		}
		$tests[] = [ $uuids, $dbResult, $result ];
		return $tests;
	}

	/**
	 * @dataProvider provideDataGetWatchStatus
	 */
	public function testGetWatchStatus( $uuids, $dbResult, array $result ) {
		// give it a fake user id
		$watchedTopicItems = new WatchedTopicItems( User::newFromId( 1 ), $this->mockDb( $dbResult ) );
		$res = $watchedTopicItems->getWatchStatus( $uuids );
		$this->assertSameSize( $res, $result );
		foreach ( $res as $key => $value ) {
			$this->assertArrayHasKey( $key, $result );
			$this->assertEquals( $value, $result[$key] );
		}

		// false values for all uuids for anon users
		$watchedTopicItems = new WatchedTopicItems( User::newFromId( 0 ), $this->mockDb( $dbResult ) );
		foreach ( $watchedTopicItems->getWatchStatus( $uuids ) as $value ) {
			$this->assertFalse( $value );
		}
	}

	protected function mockDb( $dbResult ) {
		$queryBuilder = $this->createMock( SelectQueryBuilder::class );
		$queryBuilder->method( $this->logicalOr( 'select', 'from', 'where', 'caller' ) )->willReturnSelf();
		$queryBuilder->method( 'fetchResultSet' )
			->willReturn( new FakeResultWrapper( $dbResult ) );
		$db = $this->createMock( IReadableDatabase::class );
		$db->method( 'newSelectQueryBuilder' )
			->willReturn( $queryBuilder );
		return $db;
	}
}
