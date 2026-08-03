<?php

namespace Flow\Search;

use CirrusSearch\CirrusSearch;

/**
 * CirrusSearch engine that makes namespace-restricted full text searches
 * transparently cover Flow discussions: when the requested namespaces do not
 * include the Topic namespace, the boardns: keyword (BoardNamespaceFeature)
 * is appended so topics whose board lives in one of the requested namespaces
 * are matched as well.
 *
 * Enable with $wgSearchType = \Flow\Search\CirrusSearchWithTopics::class.
 */
class CirrusSearchWithTopics extends CirrusSearch {
	/**
	 * @inheritDoc
	 */
	protected function doSearchText( $term ) {
		if (
			$this->namespaces
			&& !in_array( NS_TOPIC, $this->namespaces )
			&& !str_contains( $term, 'boardns:' )
		) {
			$term .= ' boardns:' . implode( ',', $this->namespaces );
		}

		return parent::doSearchText( $term );
	}
}
