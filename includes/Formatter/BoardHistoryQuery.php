<?php

namespace Flow\Formatter;

use Flow\Data\Utils\SortRevisionsByRevisionId;
use Flow\Exception\FlowException;
use Flow\Model\UUID;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

class BoardHistoryQuery extends HistoryQuery {
	/**
	 * @param UUID $boardWorkflowId
	 * @param int $limit
	 * @param UUID|null $offset
	 * @param string $direction 'rev' or 'fwd'
	 * @return FormatterRow[]
	 */
	public function getResults( UUID $boardWorkflowId, $limit = 50, ?UUID $offset = null, $direction = 'fwd' ) {
		$options = $this->getOptions( $direction, $limit, $offset );

		$headerHistory = $this->getHeaderResults( $boardWorkflowId, $options ) ?: [];
		$topicListHistory = $this->getTopicListResults( $boardWorkflowId, $options ) ?: [];
		$topicSummaryHistory = $this->getTopicSummaryResults( $boardWorkflowId, $options ) ?: [];
		$departureHistory = $this->getDepartureResults( $boardWorkflowId ) ?: [];

		$history = array_merge( $headerHistory, $topicListHistory, $topicSummaryHistory, $departureHistory );
		usort( $history, new SortRevisionsByRevisionId( $options['order'] ) );

		if ( isset( $options['limit'] ) ) {
			$history = array_splice( $history, 0, $options['limit'] );
		}

		// We can't use ORDER BY ... DESC in SQL, because that will give us the
		// largest entries that are > some offset.  We want the smallest entries
		// that are > that offset, but ordered in descending order.  Core solves
		// this problem in IndexPager->getBody by iterating in descending order.
		if ( $direction === 'rev' ) {
			$history = array_reverse( $history );
		}

		// fetch any necessary metadata
		$this->loadMetadataBatch( $history );
		// build rows with the extra metadata
		$results = [];
		foreach ( $history as $revision ) {
			try {
				$result = $this->buildResult( $revision, 'rev_id' );
			} catch ( FlowException $e ) {
				$result = false;
				MWExceptionHandler::logException( $e );
			}
			if ( $result ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	protected function getHeaderResults( UUID $boardWorkflowId, $options ) {
		return $this->storage->find(
			'Header',
			[
				'rev_type_id' => $boardWorkflowId,
			],
			$options
		);
	}

	protected function getTopicListResults( UUID $boardWorkflowId, $options ) {
		// Can't use 'PostRevision' because we need to get only the ones with the right board ID.
		return $this->doInternalQueries(
			'PostRevisionBoardHistoryEntry',
			[
				'topic_list_id' => $boardWorkflowId,
			],
			$options,
			self::POST_OVERFETCH_FACTOR
		);
	}

	protected function getTopicSummaryResults( UUID $boardWorkflowId, $options ) {
		return $this->storage->find(
			'PostSummaryBoardHistoryEntry',
			[
				'topic_list_id' => $boardWorkflowId,
			],
			$options
		);
	}

	/**
	 * Find move-topic revisions where this board is the source (i.e. topics that
	 * were moved *away* from this board). These are identified by the JSON-encoded
	 * src_id stored in rev_mod_reason during commitMoveTopic().
	 *
	 * @param UUID $boardWorkflowId
	 * @return array PostRevision objects
	 */
	protected function getDepartureResults( UUID $boardWorkflowId ) {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		// Search for move-topic revisions where rev_mod_reason JSON contains this board's ID
		// as the source. The JSON field is: {"src":"...","src_id":"<alphadecimal>","reason":"..."}
		$boardId = $boardWorkflowId->getAlphadecimal();
		$res = $dbr->newSelectQueryBuilder()
			->select( 'rev_id' )
			->from( 'flow_revision' )
			->where( [
				'rev_type'        => 'post',
				'rev_change_type' => 'move-topic',
			] )
			->andWhere( $dbr->expr( 'rev_mod_reason', IExpression::LIKE,
				new LikeValue( $dbr->anyString(), '"src_id":"' . $boardId . '"', $dbr->anyString() )
			) )
			->caller( __METHOD__ )
			->fetchResultSet();

		$revIds = [];
		foreach ( $res as $row ) {
			$revIds[] = UUID::create( $row->rev_id );
		}

		if ( !$revIds ) {
			return [];
		}

		$revisions = $this->storage->getMulti( 'PostRevision', $revIds );
		return array_values( $revisions ?: [] );
	}
}
