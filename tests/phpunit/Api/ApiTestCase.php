<?php

namespace Flow\Tests\Api;

use Flow\Container;
use Flow\Hooks;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;

/**
 * @group Flow
 * @group medium
 */
abstract class ApiTestCase extends \ApiTestCase {
	/** @inheritDoc */
	protected $tablesUsed = [
		'flow_ext_ref',
		'flow_revision',
		'flow_topic_list',
		'flow_tree_node',
		'flow_tree_revision',
		'flow_wiki_ref',
		'flow_workflow',
		'page',
		'revision',
		'ip_changes',
		'text',
	];

	protected function setUp(): void {
		parent::setUp();

		$namespaceContentModels = [
			NS_TALK => CONTENT_MODEL_FLOW_BOARD,
			NS_TOPIC => CONTENT_MODEL_FLOW_BOARD,
		];

		$this->overrideConfigValues( [
			MainConfigNames::NamespaceContentModels => $namespaceContentModels
		] );

		$this->clearHooks();
	}

	protected function doApiRequest(
		array $params,
		array $session = null,
		$appendModule = false,
		Authority $performer = null,
		$tokenType = null,
		$paramPrefix = null
	) {
		// reset flow state before each request
		Hooks::resetFlowExtension();
		return parent::doApiRequest( $params, $session, $appendModule, $performer, $tokenType, $paramPrefix );
	}

	/**
	 * Create a topic on a board using the default user
	 * @param string $topicTitle
	 * @return array
	 */
	protected function createTopic( $topicTitle = 'Hi there!' ) {
		$data = $this->doApiRequestWithToken( [
			'page' => 'Talk:Flow QA',
			'action' => 'flow',
			'submodule' => 'new-topic',
			'nttopic' => $topicTitle,
			'ntcontent' => '...',
		], null, null, 'csrf' );

		$this->assertTrue(
			isset( $data[0]['flow']['new-topic']['committed']['topiclist']['topic-id'] ),
			'Api response must contain new topic id'
		);

		return $data[0]['flow']['new-topic']['committed']['topiclist'];
	}

	protected function expectCacheInvalidate() {
		$mock = $this->mockCache();
		$mock->expects( $this->atLeastOnce() )->method( 'delete' );
		return $mock;
	}

	protected function mockCache() {
		global $wgFlowCacheTime;
		Container::reset();
		$container = Container::getContainer();
		$wanCache = $this->getServiceContainer()->getMainWANObjectCache();

		$mock = $this->getMockBuilder( \Flow\Data\FlowObjectCache::class )
			->setConstructorArgs( [ $wanCache, $container['db.factory'], $wgFlowCacheTime ] )
			->enableProxyingToOriginalMethods()
			->getMock();

		$container->extend( 'flowcache', static function () use ( $mock ) {
			return $mock;
		} );

		return $mock;
	}
}
