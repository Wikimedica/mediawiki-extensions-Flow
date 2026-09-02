<?php

namespace Flow\Search;

use CirrusSearch\CirrusSearch;
use CirrusSearch\Search\CirrusSearchResultSet;
use HtmlArmor;
use MediaWiki\Status\Status;

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
		$appended = false;
		if (
			$this->namespaces
			&& !in_array( NS_TOPIC, $this->namespaces )
			&& !str_contains( $term, 'boardns:' )
		) {
			$term .= ' boardns:' . implode( ',', $this->namespaces );
			$appended = true;
		}

		$status = parent::doSearchText( $term );

		if ( $appended ) {
			$resultSet = $status instanceof Status ? $status->getValue() : $status;
			if (
				$resultSet instanceof CirrusSearchResultSet
				&& $resultSet->hasSuggestion()
			) {
				// The did-you-mean suggester echoes the appended keyword back
				// in its rewritten query; the user never typed it, so keep it
				// out of what they are shown (it is re-added on the next
				// search anyway, since suggestions preserve the namespace
				// filter).
				$strip = static fn ( string $text ) =>
					preg_replace( '/\s*boardns:[\d,]+/', '', $text );
				$snippet = $resultSet->getSuggestionSnippet();
				if ( $snippet instanceof HtmlArmor ) {
					$snippet = new HtmlArmor( $strip( HtmlArmor::getHtml( $snippet ) ) );
				} elseif ( is_string( $snippet ) ) {
					$snippet = $strip( $snippet );
				}
				$resultSet->setSuggestionQuery(
					$strip( $resultSet->getSuggestionQuery() ),
					$snippet
				);
			}
		}

		return $status;
	}
}
