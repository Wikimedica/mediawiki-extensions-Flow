<?php

namespace Flow\Data\Storage;

use Flow\Data\ObjectStorage;
use Flow\Exception\DataModelException;
use Flow\Model\UUID;
use Flow\Repository\TreeRepository;

/**
 * Query-only storage implementation provides history of all post revisions in a topic.
 */
class PostRevisionTopicHistoryStorage implements ObjectStorage {

	/**
	 * @var ObjectStorage
	 */
	protected $postRevisionStorage;

	/**
	 * @var TreeRepository
	 */
	protected $treeRepository;

	/**
	 * @param ObjectStorage $postRevisionStorage
	 * @param TreeRepository $treeRepo
	 */
	public function __construct( ObjectStorage $postRevisionStorage, TreeRepository $treeRepo ) {
		$this->postRevisionStorage = $postRevisionStorage;
		$this->treeRepository = $treeRepo;
	}

	public function find( array $attributes, array $options = array() ) {
		$multi = $this->findMulti( array( $attributes ), $options );
		if ( $multi ) {
			return reset( $multi );
		}
		return null;
	}

	/**
	 * This is called with queries for 'topic_root_id': a "virtual" column that we'll
	 *   interpret.  Based on these root ids (=topic id), we'll fetch all post revisions inside
	 *   that topic.
	 * @param array $queries
	 * @param array $options
	 * @return array
	 */
	public function findMulti( array $queries, array $options = array() ) {
		foreach ( $queries as $idx => $query ) {
			if ( isset( $query['topic_root_id'] ) ) {
				$queries[$idx] = $this->findDescendantQuery( $query );
			}
		}

		return $this->postRevisionStorage->findMulti( $queries, $options );
	}

	/**
	 * All queries are for roots (guaranteed in findMulti), so anything that falls
	 * through and has to be queried from storage will actually need to be doing a
	 * special condition either joining against flow_tree_node or first collecting the
	 * subtree node lists and then doing a big IN condition
	 *
	 * This isn't a hot path (should be pre-populated into index) but we still don't want
	 * horrible performance
	 *
	 * @param array $queries
	 * @return array
	 * @throws \Flow\Exception\InvalidInputException
	 */
	protected function findDescendantQuery( array $query ) {
		$roots = array( UUID::create( $query['topic_root_id'] ) );
		$nodeList = $this->treeRepository->fetchSubtreeNodeList( $roots );
		if ( $nodeList === false ) {
			// We can't return the existing $retval, that false data would be cached.
			return array();
		}

		/** @var UUID $topicRootId */
		$topicRootId = UUID::create( $query['topic_root_id'] );
		$nodes = $nodeList[$topicRootId->getAlphadecimal()];
		return array(
			'rev_type_id' => UUID::convertUUIDs( $nodes ),
		);
	}

	public function getPrimaryKeyColumns() {
		return array( 'topic_root_id' );
	}

	public function insert( array $row ) {
		throw new DataModelException( __CLASS__ . ' does not support insert action', 'process-data' );
	}

	public function update( array $old, array $new ) {
		throw new DataModelException( __CLASS__ . ' does not support update action', 'process-data' );
	}

	public function remove( array $row ) {
		throw new DataModelException( __CLASS__ . ' does not support remove action', 'process-data' );
	}

	public function validate( array $row ) {
		return true;
	}
}
