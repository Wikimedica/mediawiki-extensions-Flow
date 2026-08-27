<?php

namespace Flow\Content;

use Flow\Actions\FlowAction;
use Flow\Container;
use Flow\Diff\FlowBoardContentDiffView;
use Flow\FlowActions;
use Flow\LinksTableUpdater;
use Flow\Model\UUID;
use Flow\View;
use Flow\WorkflowLoaderFactory;
use InvalidArgumentException;
use MediaWiki\Content\Content;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\WikiTextStructure;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Json\FormatJson;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Article;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use SearchEngine;

class BoardContentHandler extends ContentHandler {
	public function __construct( $modelId ) {
		if ( $modelId !== CONTENT_MODEL_FLOW_BOARD ) {
			throw new InvalidArgumentException( __CLASS__ . " initialised for invalid content model" );
		}

		parent::__construct( CONTENT_MODEL_FLOW_BOARD, [ CONTENT_FORMAT_JSON ] );
	}

	protected function getDiffEngineClass() {
		return FlowBoardContentDiffView::class;
	}

	public function isSupportedFormat( $format ) {
		// Necessary for backwards-compatability where
		// the format "json" was used
		if ( $format === 'json' ) {
			$format = CONTENT_FORMAT_JSON;
		}

		return parent::isSupportedFormat( $format );
	}

	/**
	 * Serializes a Content object of the type supported by this ContentHandler.
	 *
	 * @since 1.21
	 *
	 * @param Content $content The Content object to serialize
	 * @param string|null $format The desired serialization format
	 * @return string Serialized form of the content
	 */
	public function serializeContent( Content $content, $format = null ) {
		if ( !$content instanceof BoardContent ) {
			throw new InvalidArgumentException( "Expected a BoardContent object, got a " . get_class( $content ) );
		}

		$info = [];

		if ( $content->getWorkflowId() ) {
			$info['flow-workflow'] = $content->getWorkflowId()->getAlphadecimal();
		}

		return FormatJson::encode( $info );
	}

	/**
	 * Unserializes a Content object of the type supported by this ContentHandler.
	 *
	 * @since 1.21
	 *
	 * @param string $blob Serialized form of the content
	 * @param string|null $format The format used for serialization
	 *
	 * @return BoardContent The Content object created by deserializing $blob
	 */
	public function unserializeContent( $blob, $format = null ) {
		$info = FormatJson::decode( $blob, true );
		$uuid = null;

		if ( !$info ) {
			// Temporary: Fix T167198 and instead throw an exception, to
			// prevent corruption from software that does not understand
			// Flow/content models.

			return $this->makeEmptyContent();
		} elseif ( isset( $info['flow-workflow'] ) ) {
			$uuid = UUID::create( $info['flow-workflow'] );
		}

		return new BoardContent( CONTENT_MODEL_FLOW_BOARD, $uuid );
	}

	/**
	 * Creates an empty Content object of the type supported by this
	 * ContentHandler.
	 *
	 * @since 1.21
	 *
	 * @return BoardContent
	 */
	public function makeEmptyContent() {
		return new BoardContent;
	}

	/**
	 * Don't let people turn random pages into Flow ones. They either need to be:
	 * * in a Flow-enabled namespace already (where content model is flow-board by
	 *   default).  In such a namespace, non-existent pages are created as Flow.
	 * * explicitly allowed for a user, requiring special permissions
	 *
	 * @param Title $title
	 * @return bool
	 */
	public function canBeUsedOn( Title $title ) {
		/** @var \Flow\TalkpageManager $manager */
		$manager = Container::get( 'occupation_controller' );

		/** @var User $user */
		$user = Container::get( 'user' );

		return $manager->canBeUsedOn( $title, $user );
	}

	/**
	 * Returns overrides for action handlers.
	 * Classes listed here will be used instead of the default one when
	 * (and only when) $wgActions[$action] === true. This allows subclasses
	 * to override the default action handlers.
	 *
	 * @since 1.21
	 *
	 * @return array Associative array mapping action names to handler callables
	 */
	public function getActionOverrides() {
		/** @var FlowActions $actions */
		$actions = Container::get( 'flow_actions' );
		$output = [];

		foreach ( $actions->getActions() as $action ) {
			$actionData = $actions->getValue( $action );
			if ( !is_array( $actionData ) ) {
				continue;
			}

			if ( !isset( $actionData['handler-class'] ) ) {
				continue;
			}

			if ( $actionData['handler-class'] === FlowAction::class ) {
				$output[$action] = static function (
					Article $article,
					IContextSource $source
				) use ( $action ) {
					return new FlowAction( $article, $source, $action );
				};
			} else {
				$output[$action] = $actionData['handler-class'];
			}
		}

		// Flow has its own handling for action=edit
		$output['edit'] = \Flow\Actions\EditAction::class;

		return $output;
	}

	/**
	 * @inheritDoc
	 */
	protected function fillParserOutput(
		Content $content,
		ContentParseParams $cpoParams,
		ParserOutput &$output
	) {
		'@phan-var BoardContent $content';
		$parserOptions = $cpoParams->getParserOptions();
		$revId = $cpoParams->getRevId();
		$title = Title::castFromPageReference( $cpoParams->getPage() )
			?: Title::makeTitle( NS_MEDIAWIKI, 'BadTitle/Flow' );
		if ( $cpoParams->getGenerateHtml() ) {
			try {
				$user = MediaWikiServices::getInstance()
					->getUserFactory()
					->newFromUserIdentity( $parserOptions->getUserIdentity() );
				$this->generateHtml( $title, $user, $content, $output );
			} catch ( \Exception ) {
				// Workflow does not yet exist (may be in the process of being created)
				$output->setContentHolderText( '' );
			}
		}

		$output->updateCacheExpiry( 0 );

		if ( $revId === null ) {
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			$timestamp = $wikiPage->getTimestamp();
		} else {
			$timestamp = MediaWikiServices::getInstance()->getRevisionLookup()
				->getTimestampFromId( $revId );
		}

		$output->setRevisionTimestamp( $timestamp );

		/** @var LinksTableUpdater $updater */
		$updater = Container::get( 'reference.updater.links-tables' );
		$updater->mutateParserOutput( $title, $output );
	}

	/**
	 * Index the rendered board/topic HTML as the document text.
	 *
	 * The parent implementation takes the text from
	 * Content::getTextForSearchIndex(), which BoardContent hardcodes to '',
	 * leaving board and topic documents with no searchable text at all.
	 *
	 * @param WikiPage $page
	 * @param ParserOutput $output
	 * @param SearchEngine $engine
	 * @param RevisionRecord|null $revision
	 * @return array
	 */
	public function getDataForSearchIndex(
		WikiPage $page,
		ParserOutput $output,
		SearchEngine $engine,
		?RevisionRecord $revision = null
	) {
		$fields = parent::getDataForSearchIndex( $page, $output, $engine, $revision );

		$structure = new WikiTextStructure( $output );
		$fields['text'] = $structure->getMainText();

		// WikiTextStructure::headings() reads TOC data, which the board view
		// does not produce; extract topic titles from the rendered heading
		// elements instead (heading elements are excluded from the text field).
		$fields['heading'] = [];
		if ( preg_match_all( '!<h[1-6][^>]*>(.*?)</h[1-6]>!s', $output->getRawText(), $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				$heading = trim( Sanitizer::stripAllTags( $heading ) );
				if ( $heading !== '' ) {
					$fields['heading'][] = $heading;
				}
			}
		}
		// Boards have no wikitext source; index the extracted text instead.
		$fields['source_text'] = $fields['text'];
		$fields['text_bytes'] = strlen( $fields['text'] );
		$fields['opening_text'] = $structure->getOpeningText();
		$fields['auxiliary_text'] = $structure->getAuxiliaryText();

		// Let topics be found through their parent board: index the board's
		// namespace (queried by the boardns: keyword, see
		// BoardNamespaceFeature) and inherit its categories so incategory:
		// covers the board's discussions.
		$title = $page->getTitle();
		if ( $title->inNamespace( NS_TOPIC ) ) {
			$boardTitle = $this->getBoardTitle( $title );
			if ( $boardTitle ) {
				$fields['board_namespace'] = $boardTitle->getNamespace();
				$inherited = $this->getCategories( $boardTitle );
				// A board on a talk page holds the discussions of its subject
				// page, and that is where the categories are: inherit them so
				// incategory: on the subject's categories reaches the topics.
				if ( $boardTitle->isTalkPage() ) {
					$inherited = array_merge(
						$inherited,
						$this->getCategories( $boardTitle->getSubjectPage() )
					);
				}
				$fields['category'] = array_values( array_unique( array_merge(
					$fields['category'] ?? [],
					$inherited
				) ) );
			}
		} else {
			$fields['board_namespace'] = $title->getNamespace();
		}

		return $fields;
	}

	/**
	 * Resolve the board a topic belongs to.
	 *
	 * @param Title $topicTitle
	 * @return Title|null
	 */
	private function getBoardTitle( Title $topicTitle ) {
		try {
			$storage = Container::get( 'storage' );
			$found = $storage->find( 'TopicListEntry', [
				'topic_id' => UUID::create( strtolower( $topicTitle->getDBkey() ) ),
			] );
			if ( !$found ) {
				return null;
			}
			/** @var \Flow\Model\TopicListEntry $entry */
			$entry = reset( $found );
			$boardWorkflow = $storage->get( 'Workflow', $entry->getListId() );

			return $boardWorkflow ? $boardWorkflow->getArticleTitle() : null;
		} catch ( \Exception ) {
			return null;
		}
	}

	/**
	 * @param Title $title
	 * @return string[] The page's categories, in the text form the category
	 *  search index field uses.
	 */
	private function getCategories( Title $title ) {
		$categories = [];
		foreach ( $title->getParentCategories() as $category => $_ ) {
			$categoryTitle = Title::newFromText( $category );
			if ( $categoryTitle ) {
				$categories[] = $categoryTitle->getText();
			}
		}

		return $categories;
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @param BoardContent $content
	 * @param ParserOutput $output
	 */
	protected function generateHtml(
		Title $title,
		User $user,
		BoardContent $content,
		ParserOutput $output
	) {
		// Set up a derivative context (which inherits the current request)
		// to hold the output modules + text
		$childContext = new DerivativeContext( RequestContext::getMain() );
		$childContext->setOutput( new OutputPage( $childContext ) );
		$childContext->setRequest( new FauxRequest );
		$childContext->setUser( $user );

		// Create a View set up to output to our derivative context
		$view = new View(
			Container::get( 'url_generator' ),
			Container::get( 'lightncandy' ),
			$childContext->getOutput(),
			Container::get( 'flow_actions' )
		);

		$loader = $this->getWorkflowLoader( $title, $content );
		$view->show( $loader, 'view' );

		// Extract data from derivative context
		$output->setContentHolderText( $childContext->getOutput()->getHTML() );
		$output->addModules( $childContext->getOutput()->getModules() );
		$output->addModuleStyles( $childContext->getOutput()->getModuleStyles() );
	}

	/**
	 * @param Title $title
	 * @param BoardContent $content
	 * @return \Flow\WorkflowLoader
	 * @throws \Flow\Exception\CrossWikiException
	 * @throws \Flow\Exception\InvalidInputException
	 */
	protected function getWorkflowLoader( Title $title, BoardContent $content ) {
		/** @var WorkflowLoaderFactory $factory */
		$factory = Container::get( 'factory.loader.workflow' );
		return $factory->createWorkflowLoader( $title, $content->getWorkflowId() );
	}
}
