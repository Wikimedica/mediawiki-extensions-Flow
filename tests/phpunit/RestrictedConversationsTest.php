<?php

namespace Flow\Tests;

use Flow\Container;
use Flow\Data\Listener\NotificationListener;
use Flow\FlowActions;
use Flow\Model\AbstractRevision;
use Flow\Model\PostRevision;
use Flow\Notifications\Controller;
use Flow\Notifications\UserLocator;
use Flow\RevisionActionPermissions;
use MediaWiki\User\User;

/**
 * Tests for restricted (masked) conversations: private branches/topics only
 * visible to holders of the flow-restrict right until published.
 *
 * @covers \Flow\Model\AbstractRevision
 * @covers \Flow\Model\PostRevision
 * @covers \Flow\RevisionActionPermissions
 * @covers \Flow\Data\Listener\NotificationListener
 *
 * @group Database
 * @group Flow
 */
class RestrictedConversationsTest extends PostRevisionTestCase {
	/**
	 * @var FlowActions
	 */
	private $actions;

	/**
	 * @var PostRevision
	 */
	private $topic;

	/**
	 * @var PostRevision
	 */
	private $restrictedTopic;

	/**
	 * @var PostRevision
	 */
	private $post;

	/**
	 * @var PostRevision
	 */
	private $restrictedPost;

	/**
	 * @var User
	 */
	private $plainUser;

	/**
	 * @var User
	 */
	private $maskUser;

	protected function setUp(): void {
		parent::setUp();

		// Make sure local configuration does not interfere with the
		// permissions defined in extension.json (which grant flow-restrict
		// to sysops)
		$this->resetPermissions();

		$this->actions = Container::get( 'flow_actions' );
	}

	/**
	 * @return array
	 */
	public static function permissionsProvider() {
		return [
			// restricted content is invisible without the flow-restrict right
			[ 'plainUser', 'restrictedPost', 'view', false ],
			[ 'plainUser', 'restrictedPost', 'history', false ],
			[ 'plainUser', 'restrictedPost', 'reply', false ],
			[ 'plainUser', 'restrictedPost', 'edit-post', false ],
			[ 'plainUser', 'restrictedPost', 'restore-post', false ],
			[ 'plainUser', 'restrictedTopic', 'view', false ],
			[ 'plainUser', 'restrictedTopic', 'view-topic-title', false ],
			[ 'plainUser', 'restrictedTopic', 'reply', false ],
			[ 'plainUser', 'restrictedTopic', 'restore-topic', false ],

			// masking requires the flow-restrict right
			[ 'plainUser', 'post', 'restrict-post', false ],
			[ 'plainUser', 'topic', 'restrict-topic', false ],

			// right holders see, participate in, and publish masked content
			[ 'maskUser', 'restrictedPost', 'view', true ],
			[ 'maskUser', 'restrictedPost', 'history', true ],
			[ 'maskUser', 'restrictedPost', 'reply', true ],
			[ 'maskUser', 'restrictedPost', 'edit-post', true ],
			[ 'maskUser', 'restrictedPost', 'restore-post', true ],
			[ 'maskUser', 'restrictedTopic', 'view', true ],
			[ 'maskUser', 'restrictedTopic', 'view-topic-title', true ],
			[ 'maskUser', 'restrictedTopic', 'restore-topic', true ],
			[ 'maskUser', 'post', 'restrict-post', true ],
			[ 'maskUser', 'topic', 'restrict-topic', true ],

			// masked content cannot be re-masked or otherwise moderated
			// without being published first
			[ 'maskUser', 'restrictedPost', 'restrict-post', false ],
			[ 'maskUser', 'restrictedPost', 'hide-post', false ],
			[ 'maskUser', 'restrictedPost', 'delete-post', false ],
			[ 'maskUser', 'restrictedTopic', 'delete-topic', false ],
		];
	}

	/**
	 * @dataProvider permissionsProvider
	 */
	public function testPermissions( $userGetterName, $revisionGetterName, $action, $expected ) {
		$user = $this->$userGetterName();
		$revision = $this->$revisionGetterName();

		$permissions = new RevisionActionPermissions( $this->actions, $user );
		$this->assertEquals( $expected, $permissions->isRevisionAllowed( $revision, $action ) );
	}

	public function testRestrictedIsValidModerationState() {
		$revision = $this->topic();
		$this->assertTrue( $revision->isValidModerationState( AbstractRevision::MODERATED_RESTRICTED ) );
		$this->assertContains( 'restrict-post', AbstractRevision::getModerationChangeTypes() );
		$this->assertContains( 'restrict-topic', AbstractRevision::getModerationChangeTypes() );
	}

	public function testMarkRestricted() {
		$user = $this->maskUser();
		$revision = $this->generateObject();

		$this->assertFalse( $revision->isRestricted() );
		$revision->markRestricted( $user );

		$this->assertTrue( $revision->isRestricted() );
		$this->assertTrue( $revision->isModerated() );
		$this->assertSame(
			AbstractRevision::MODERATED_RESTRICTED,
			$revision->getModerationState()
		);
		$this->assertEquals( $user->getId(), $revision->getModeratedByUserId() );
		$this->assertNotNull( $revision->getModerationTimestamp() );
	}

	public function testReplyToRestrictedPostIsBornRestricted() {
		// avoid wikitext -> html conversion (Parsoid) during setContent
		$this->overrideConfigValue( 'FlowContentFormat', 'wikitext' );

		$workflow = $this->generateWorkflow();
		$reply = $this->restrictedPost()->reply(
			$workflow,
			$this->maskUser(),
			'réponse dans une branche masquée',
			'wikitext'
		);

		$this->assertTrue( $reply->isRestricted() );
		$this->assertNotNull( $reply->getModerationTimestamp() );
	}

	public function testReplyToRestrictedTopicTitleIsNotBornRestricted() {
		$this->overrideConfigValue( 'FlowContentFormat', 'wikitext' );

		// Posts inside a fully-restricted topic are governed by the root's
		// state so that publishing the topic is a single restore action;
		// they must not carry their own restricted state.
		$workflow = $this->generateWorkflow();
		$reply = $this->restrictedTopic()->reply(
			$workflow,
			$this->maskUser(),
			'réponse dans un sujet masqué',
			'wikitext'
		);

		$this->assertFalse( $reply->isRestricted() );
	}

	public function testRecentChangesInsertSkipsRestrictedContent() {
		$rcInsert = $this->actions->getValue( 'reply', 'rc_insert' );
		$this->assertIsCallable( $rcInsert );

		// a born-restricted reply must not reach recentchanges
		$this->assertFalse( $rcInsert( $this->restrictedPost() ) );
		// nor may anything in a restricted topic (root is its own state here)
		$this->assertFalse( $rcInsert( $this->restrictedTopic() ) );
		// a normal topic title is unaffected
		$this->assertTrue( $rcInsert( $this->topic() ) );
	}

	public function testMaskedReplyNotifiesWithMaskedFlag() {
		$controller = $this->createMock( Controller::class );
		$controller->expects( $this->once() )
			->method( 'notifyPostChange' )
			->with(
				'flow-post-reply',
				$this->callback( static function ( $params ) {
					// the event must carry the masked flag so recipients are
					// filtered down to users allowed to view the content
					return !empty( $params['extra-data']['masked'] );
				} )
			);
		$controller->expects( $this->never() )->method( 'notifyNewTopic' );

		$listener = new NotificationListener( $controller );

		// a reply born restricted (masked branch) notifies eligible users
		$listener->onAfterInsert(
			$this->restrictedReply(),
			[ 'rev_change_type' => 'reply' ],
			[
				'workflow' => $this->generateWorkflow(),
				'topic-title' => $this->topic(),
			]
		);

		// a topic born restricted (masked conversation) stays silent
		$listener->onAfterInsert(
			$this->restrictedTopic(),
			[ 'rev_change_type' => 'new-post' ],
			[]
		);
	}

	public function testFilterMaskViewersKeepsOnlyRightHolders() {
		$filtered = UserLocator::filterMaskViewers( [
			$this->maskUser(),
			$this->plainUser(),
			// also accepts plain user ids (as used for mentions)
			$this->plainUser()->getId(),
		] );

		$this->assertCount( 1, $filtered );
		$this->assertSame( $this->maskUser(), reset( $filtered ) );
	}

	protected function plainUser() {
		if ( !$this->plainUser ) {
			$this->plainUser = $this->getTestUser( [ 'autoconfirmed' ] )->getUser();
		}

		return $this->plainUser;
	}

	protected function maskUser() {
		if ( !$this->maskUser ) {
			// sysops hold flow-restrict per extension.json
			$this->maskUser = $this->getTestSysop()->getUser();
		}

		return $this->maskUser;
	}

	protected function topic() {
		if ( !$this->topic ) {
			$this->topic = $this->generateObject();
		}

		return $this->topic;
	}

	protected function restrictedTopic() {
		if ( !$this->restrictedTopic ) {
			$this->restrictedTopic = $this->generateObject( [
				'rev_change_type' => 'restrict-topic',
				'rev_mod_state' => AbstractRevision::MODERATED_RESTRICTED,
			] );
		}

		return $this->restrictedTopic;
	}

	protected function post() {
		if ( !$this->post ) {
			$this->post = $this->generateObject( [
				'tree_parent_id' => $this->topic()->getPostId()->getBinary(),
				'rev_change_type' => 'reply',
			], [], 1 );
		}

		return $this->post;
	}

	protected function restrictedPost() {
		if ( !$this->restrictedPost ) {
			$this->restrictedPost = $this->generateObject( [
				'tree_parent_id' => $this->topic()->getPostId()->getBinary(),
				'rev_change_type' => 'restrict-post',
				'rev_mod_state' => AbstractRevision::MODERATED_RESTRICTED,
			], [], 1 );
		}

		return $this->restrictedPost;
	}

	/**
	 * A reply born restricted (as created through the masked-conversation
	 * checkbox), as opposed to a post restricted after the fact.
	 *
	 * @return PostRevision
	 */
	protected function restrictedReply() {
		return $this->generateObject( [
			'tree_parent_id' => $this->restrictedPost()->getPostId()->getBinary(),
			'rev_change_type' => 'reply',
			'rev_mod_state' => AbstractRevision::MODERATED_RESTRICTED,
		], [], 2 );
	}
}
