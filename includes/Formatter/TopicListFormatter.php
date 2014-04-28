<?php

namespace Flow\Formatter;

use Flow\Data\PagerPage;
use Flow\Model\UUID;
use Flow\Model\Workflow;
use Flow\UrlGenerator;
use IContextSource;

class TopicListFormatter {

	public function __construct( UrlGenerator $urlGenerator, RevisionFormatter $serializer ) {
		$this->urlGenerator = $urlGenerator;
		$this->serializer = $serializer;
	}

	public function buildEmptyResult( Workflow $workflow ) {
		return array(
			'type' => 'topiclist',
			'roots' => array(),
			'posts' => array(),
			'revisions' => array(),
			'links' => array(),
			'actions' => $this->buildApiActions( $workflow ),
		);
	}

	public function formatApi(
		Workflow $listWorkflow,
		array $workflows,
		array $found,
		PagerPage $page,
		IContextSource $ctx
	) {
		$section = new \ProfileSection( __METHOD__ );
		$res = $this->buildResult( $workflows, $found, $ctx ) +
			$this->buildEmptyResult( $listWorkflow );
		$res['links']['pagination'] = $this->buildPaginationLinks(
			$listWorkflow,
			$page->getPagingLinksOptions()
		);

		return $res;
	}

	protected function buildPaginationLinks( Workflow $workflow, array $links ) {
		$res = array();
		$title = $workflow->getArticleTitle();
		foreach ( $links as $key => $options ) {
			// prefix all options with topiclist_
			$realOptions = array();
			foreach ( $options as $k => $v ) {
				$realOptions["topiclist_$k"] = $v;
			}
			$res[$key] = array(
				'url' => $this->urlGenerator->buildUrl( $title, 'view', $realOptions ),
				'title' => $key, // @todo i18n
			);
		}

		return $res;
	}

	protected function buildResult( array $workflows, array $found, IContextSource $ctx ) {
		$revisions = $posts = $replies = array();
		foreach( $found as $formatterRow ) {
			$serialized = $this->serializer->formatApi( $formatterRow, $ctx );
			if ( !$serialized ) {
				continue;
			}
			$revisions[$serialized['revisionId']] = $serialized;
			$posts[$serialized['postId']][] = $serialized['revisionId'];
			$replies[$serialized['replyToId']][] = $serialized['postId'];
		}

		foreach ( $revisions as $i => $serialized ) {
			$alpha = $serialized['postId'];
			$revisions[$i]['replies'] = isset( $replies[$alpha] ) ? $replies[$alpha] : array();
		}

		if ( $workflows ) {
			$orig = $workflows;
			$workflows = array();
			$list = array();
			foreach ( $orig as $workflow ) {
				$list[] = $alpha = $workflow->getId()->getAlphadecimal();
				$workflows[$alpha] = $workflow;
			}

			foreach ( $list as $alpha ) {
				// Metadata that requires everything to be serialied first
				$metadata = $this->generateTopicMetadata( $posts, $revisions, $workflows, $alpha );
				foreach ( $posts[$alpha] as $revId ) {
					$revisions[$revId] += $metadata;
				}
			}
		}

		return array(
			'workflowId' => $workflow->getId()->getAlphadecimal(),
			'roots' => $list,
			'posts' => $posts,
			'revisions' => $revisions,
			'links' => array(
				'search' => array(
					'url' => '',
					'title' => '',
				),
				'pagination' => array(
					'load_more' => array(
						'url' => '',
						'title' => '',
					),
				),
			),
		);
	}

	protected function buildApiActions( Workflow $workflow ) {
		return array(
			'newtopic' => array(
				'url' => $this->urlGenerator->buildUrl(
					$workflow->getArticleTitle(),
					'new-topic', // ???
					array(
						'workflow' => $workflow->getId()->getAlphadecimal(),
					)
				),
				'title' => wfMessage( 'flow-newtopic-start-placeholder' ),
			),
		);
	}

	protected function generateTopicMetadata( array $posts, array $revisions, array $workflows, $postAlphaId ) {
		$replies = -1;
		$authors = array();
		$stack = new \SplStack;
		$stack->push( $revisions[$posts[$postAlphaId][0]] );
		do {
			$data = $stack->pop();
			$replies++;
			$authors[] = $data['author']['wiki'] . "\t" . $data['author']['name'];
			foreach ( $data['replies'] as $postId ) {
				$stack->push( $revisions[$posts[$postId][0]] );
			}
		} while( !$stack->isEmpty() );

		$workflow = isset( $workflows[$postAlphaId] ) ? $workflows[$postAlphaId] : null;

		return array(
			'reply_count' => $replies,
			'author_count' => count( array_unique( $authors ) ),
			// ms timestamp
			'last_updated' => $workflow ? $workflow->getLastModifiedObj()->getTimestamp() * 1000 : null,
		);
	}
}
