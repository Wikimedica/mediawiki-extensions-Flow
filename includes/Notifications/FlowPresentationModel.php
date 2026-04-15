<?php

namespace Flow\Notifications;

use Flow\Container;
use Flow\Model\UUID;
use Flow\UrlGenerator;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MWException;

abstract class FlowPresentationModel extends EchoEventPresentationModel {

	protected function hasTitle() {
		return (bool)$this->event->getTitle();
	}

	protected function hasValidTopicWorkflowId() {
		$topicWorkflowId = $this->event->getExtraParam( 'topic-workflow' );
		return $topicWorkflowId && $topicWorkflowId instanceof UUID;
	}

	protected function hasValidPostId() {
		$postId = $this->event->getExtraParam( 'post-id' );
		return $postId && $postId instanceof UUID;
	}

	public function getSecondaryLinks() {
		return [ $this->getAgentLink() ];
	}

	/**
	 * Get the notification target title — the board page by default, or the task article
	 * page if the board's subject is in the 'Tâches' category.
	 *
	 * @param string $fragment Optional URL fragment (without leading #)
	 * @return Title
	 */
	protected function getNotificationTarget( string $fragment = '' ): Title {
		$board = $this->event->getTitle();

		try {
			$subjectPage = $board->getOtherPage();
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $subjectPage );
			foreach ( $wikiPage->getCategories() as $category ) {
				if ( $category->getDBKey() === 'Tâches' ) {
					return $subjectPage->createFragmentTarget( $fragment );
				}
			}
		} catch ( MWException $e ) {
			// Board namespace has no associated subject page; fall through to board link.
		}

		return $board->createFragmentTarget( $fragment );
	}

	/**
	 * Return a full url to the board (or task article) with the post anchor and fromnotif flag.
	 *   https://<site>/wiki/<Board>?topic_showPostId=<$firstChronologicallyPostId>&fromnotif=1#flow-post-<$anchorPostID>
	 * @param UUID|null $firstChronologicallyPostId First unread post ID
	 * @param UUID|null $anchorPostId Post ID for anchor (i.e. to scroll to)
	 * @return string
	 */
	protected function getPostLinkUrl( $firstChronologicallyPostId = null, $anchorPostId = null ) {
		/** @var UUID $firstChronologicallyPostId */
		$firstChronologicallyPostId ??= $this->event->getExtraParam( 'post-id' );
		'@phan-var UUID $firstChronologicallyPostId';

		$anchorPostId ??= $firstChronologicallyPostId;
		$target = $this->getNotificationTarget( 'flow-post-' . $anchorPostId->getAlphadecimal() );

		return $target->getFullURL( [
			'topic_showPostId' => $firstChronologicallyPostId->getAlphadecimal(),
			'fromnotif' => 1,
		] );
	}

	/**
	 * Return a full url to the board (or task article) with the fromnotif flag.
	 *   https://<site>/wiki/<Board>?fromnotif=1
	 * @return string
	 */
	protected function getTopicLinkUrl() {
		return $this->getNotificationTarget()->getFullURL( [ 'fromnotif' => 1 ] );
	}

	/**
	 * Get the topic title Title
	 *
	 * @param string $fragment
	 * @return Title
	 */
	protected function getTopicTitleObj( $fragment = '' ) {
		/** @var UUID $workflowId */
		$workflowId = $this->event->getExtraParam( 'topic-workflow' );
		'@phan-var UUID $workflowId';

		return Title::makeTitleSafe(
			NS_TOPIC,
			$workflowId->getAlphadecimal(),
			$fragment
		);
	}

	/**
	 * Return a full url to a board sorted by newest topic
	 *   ?topiclist_sortby=newest
	 * @return array
	 */
	protected function getBoardLinkByNewestTopic() {
		return [
			'url' => $this->getBoardByNewestTopicUrl(),
			'label' => $this->msg( 'flow-notification-link-text-view-topics' )->text()
		];
	}

	protected function getBoardByNewestTopicUrl() {
		/** @var UrlGenerator $urlGenerator */
		$urlGenerator = Container::get( 'url_generator' );
		return $urlGenerator->boardLink( $this->event->getTitle(), 'newest' )->getFullURL();
	}

	protected function getViewTopicLink() {
		return [
			'url' => $this->getNotificationTarget()->getFullURL( [ 'fromnotif' => 1 ] ),
			'label' => $this->msg( 'flow-notification-link-text-view-topic' )->text(),
		];
	}

	protected function getBoardByNewestLink() {
		return $this->getBoardLink( 'newest' );
	}

	protected function getBoardLink( $sortBy = null ) {
		$query = $sortBy ? [ 'topiclist_sortby' => $sortBy ] : [];
		return $this->getPageLink(
			$this->event->getTitle(), '', true, $query
		);
	}

	protected function getContentSnippet() {
		return $this->event->getExtraParam( 'content' );
	}

	protected function getTopicTitle( $extraParamName = 'topic-title' ) {
		$topicTitle = $this->event->getExtraParam( $extraParamName, '' );
		return $this->truncateTopicTitle( $topicTitle );
	}

	protected function truncateTopicTitle( $topicTitle ) {
		return $this->language->embedBidi(
			$this->language->truncateForVisual(
				$topicTitle,
				self::SECTION_TITLE_RECOMMENDED_LENGTH,
				'...',
				false
			)
		);
	}

	protected function isUserTalkPage() {
		// Would like to do $this->event->getTitle()->equals( $this->user->getTalkPage() )
		// but $this->user is private in the parent class
		$username = $this->getViewingUserForGender();
		return $this->event->getTitle()->getNamespace() === NS_USER_TALK &&
			$this->event->getTitle()->getText() === $username;
	}

	/**
	 * Get a flow-specific watch/unwatch dynamic action link
	 *
	 * @param bool $isTopic Unwatching a topic. If set to false, the
	 *  action is unwatching a board
	 * @return array|null Array representing the dynamic action secondary link.
	 *  Returns null if either
	 *   * The notification came from the user's talk page, as that
	 *     page cannot be unwatched.
	 *   * The page is not currently watched.
	 */
	protected function getFlowUnwatchDynamicActionLink( $isTopic = false ) {
		$title = $isTopic ? $this->getTopicTitleObj() : $this->event->getTitle();
		$query = [ 'action' => 'unwatch' ];
		$link = $this->getWatchActionLink( $title );
		$type = $isTopic ? 'topic' : 'board';
		$stringPageTitle = $isTopic ? $this->getTopicTitle() : $this->getTruncatedTitleText( $title );

		if ( $this->isUserTalkPage() ||
			 !MediaWikiServices::getInstance()->getWatchlistManager()->isWatched( $this->getUser(), $title )
			) {
			return null;
		}

		$messageKeys = [
			'confirmation' => [
				// notification-dynamic-actions-flow-board-unwatch-confirmation
				// notification-dynamic-actions-flow-topic-unwatch-confirmation
				'title' => $this
					->msg( 'notification-dynamic-actions-flow-' . $type . '-unwatch-confirmation' )
					->params(
						$stringPageTitle,
						$title->getFullURL(),
						$this->getUser()->getName()
					)
					->parse(),
				// notification-dynamic-actions-flow-board-unwatch-confirmation-description
				// notification-dynamic-actions-flow-topic-unwatch-confirmation-description
				'description' => $this
					->msg( 'notification-dynamic-actions-flow-' . $type . '-unwatch-confirmation-description' )
					->params(
						$stringPageTitle,
						$title->getFullURL(),
						$this->getUser()->getName()
					)
					->parse(),
			],
		];

		// Override messages with flow-specific messages
		$link[ 'data' ][ 'messages' ] = array_replace( $link[ 'data' ][ 'messages' ], $messageKeys );

		// notification-dynamic-actions-flow-board-unwatch
		// notification-dynamic-actions-flow-topic-unwatch
		$link['label'] = $this
			->msg( 'notification-dynamic-actions-flow-' . $type . '-unwatch' )
			->params(
				$stringPageTitle,
				$title->getFullURL( $query ),
				$this->getUser()->getName()
			)
			->text();

		return $link;
	}
}
