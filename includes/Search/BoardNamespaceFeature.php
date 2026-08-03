<?php

namespace Flow\Search;

use CirrusSearch\Query\SimpleKeywordFeature;
use CirrusSearch\Search\SearchContext;
use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Elastica\Query\Terms;

/**
 * boardns:<comma separated namespace ids>
 *
 * Restricts results to pages in the given namespaces OR Flow topics whose
 * parent board lives in one of them (matched against the board_namespace
 * field indexed by BoardContentHandler::getDataForSearchIndex()). The Topic
 * namespace is added to the search's namespace list so those topics are not
 * excluded by the regular namespace filter.
 *
 * CirrusSearchWithTopics appends this keyword automatically when a search is
 * restricted to namespaces that do not include the Topic namespace, so that
 * namespace filtering transparently covers the discussions of the selected
 * namespaces.
 */
class BoardNamespaceFeature extends SimpleKeywordFeature {
	/**
	 * @inheritDoc
	 */
	protected function getKeywords() {
		return [ 'boardns' ];
	}

	/**
	 * @inheritDoc
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$namespaces = [];
		foreach ( explode( ',', $value ) as $namespace ) {
			if ( is_numeric( trim( $namespace ) ) ) {
				$namespaces[] = intval( trim( $namespace ) );
			}
		}

		if ( !$namespaces || $negated ) {
			return [ null, false ];
		}

		$contextNamespaces = $context->getNamespaces();
		if ( $contextNamespaces && !in_array( NS_TOPIC, $contextNamespaces ) ) {
			$contextNamespaces[] = NS_TOPIC;
			$context->setNamespaces( $contextNamespaces );
		}

		$topicsOfBoards = new BoolQuery();
		$topicsOfBoards->addMust( new Term( [ 'namespace' => NS_TOPIC ] ) );
		$topicsOfBoards->addMust( new Terms( 'board_namespace', $namespaces ) );

		$filter = new BoolQuery();
		$filter->addShould( new Terms( 'namespace', $namespaces ) );
		$filter->addShould( $topicsOfBoards );

		return [ $filter, false ];
	}
}
