<?php

use Flow\Container;
use Flow\Exception\FlowException;
use Flow\Formatter\CheckUserQuery;
use Flow\Model\UUID;
use Flow\NotificationController;
use Flow\OccupationController;
use Flow\SpamFilter\AbuseFilter;
use Flow\TalkpageManager;

class FlowHooks {
	/**
	 * @var OccupationController Initialized during extension intialization
	 */
	protected static $occupationController;

	/**
	 * @var AbuseFilter Initialized during extension initialization
	 */
	protected static $abuseFilter;

	/**
	 * Initialized during extension initialization rather than
	 * in container so that non-flow pages don't load the container.
	 *
	 * @return OccupationController
	 */
	public static function getOccupationController() {
		if ( self::$occupationController === null ) {
			global $wgFlowOccupyNamespaces,
				$wgFlowOccupyPages;

			// NS_TOPIC is always occupied
			$namespaces = $wgFlowOccupyNamespaces;
			$namespaces[] = NS_TOPIC;

			self::$occupationController = new TalkpageManager(
				array_unique( $namespaces ),
				$wgFlowOccupyPages
			);
		}
		return self::$occupationController;
	}

	/**
	 * Initialized during extension initialization rather than
	 * in container so that non-flow pages don't load the container.
	 *
	 * @return AbuseFilter|null when disabled
	 */
	public static function getAbuseFilter() {
		if ( self::$abuseFilter === null ) {
			global $wgUser,
				$wgFlowAbuseFilterGroup,
				$wgFlowAbuseFilterEmergencyDisableThreshold,
				$wgFlowAbuseFilterEmergencyDisableCount,
				$wgFlowAbuseFilterEmergencyDisableAge;

			self::$abuseFilter = new AbuseFilter( $wgUser, $wgFlowAbuseFilterGroup );
			self::$abuseFilter->setup( array(
				'threshold' => $wgFlowAbuseFilterEmergencyDisableThreshold,
				'count' => $wgFlowAbuseFilterEmergencyDisableCount,
				'age' => $wgFlowAbuseFilterEmergencyDisableAge,
			) );
		}
		return self::$abuseFilter;
	}

	/**
	 * Initialize Flow extension with necessary data, this function is invoked
	 * from $wgExtensionFunctions
	 */
	public static function initFlowExtension() {
		// Depends on Mantle extension
		if ( !class_exists( 'MantleHooks' ) ) {
			throw new FlowException( 'Flow requires the Mantle MediaWiki extension.' );
		}
		// needed to determine if a page is occupied by flow
		self::getOccupationController();

		// necessary to render flow notifications
		if ( class_exists( 'EchoNotifier' ) ) {
			NotificationController::setup();
		}

		// necessary to provide flow options in abuse filter on-wiki pages
		global $wgFlowAbuseFilterGroup;
		if ( $wgFlowAbuseFilterGroup ) {
			self::getAbuseFilter();
		}
	}

	/**
	 * Hook: LoadExtensionSchemaUpdates
	 *
	 * @param $updater DatabaseUpdater object
	 * @return bool true in all cases
	 */
	public static function getSchemaUpdates( DatabaseUpdater $updater ) {
		$dir = __DIR__;
		$baseSQLFile = "$dir/flow.sql";
		$updater->addExtensionTable( 'flow_revision', $baseSQLFile );
		$updater->addExtensionField( 'flow_revision', 'rev_last_edit_id', "$dir/db_patches/patch-revision_last_editor.sql" );
		$updater->addExtensionField( 'flow_revision', 'rev_mod_reason', "$dir/db_patches/patch-moderation_reason.sql" );
		if ( $updater->getDB()->getType() === 'sqlite' ) {
			$updater->modifyExtensionField( 'flow_summary_revision', 'summary_workflow_id', "$dir/db_patches/patch-summary2header.sqlite.sql" );
			$updater->modifyExtensionField( 'flow_revision', 'rev_comment', "$dir/db_patches/patch-rev_change_type.sqlite.sql" );
			// sqlite ignores field types, this just substr's uuid's to 88 bits
			$updater->modifyExtensionField( 'flow_workflow', 'workflow_id', "$dir/db_patches/patch-88bit_uuids.sqlite.sql" );
			$updater->addExtensionField( 'flow_workflow', 'workflow_type', "$dir/db_patches/patch-add_workflow_type.sqlite" );
		} else {
			// sqlite doesn't support alter table change, it also considers all types the same so
			// this patch doesn't matter to it.
			$updater->modifyExtensionField( 'flow_subscription', 'subscription_user_id', "$dir/db_patches/patch-subscription_user_id.sql" );
			// renames columns, alternate patch is above for sqlite
			$updater->modifyExtensionField( 'flow_summary_revision', 'summary_workflow_id', "$dir/db_patches/patch-summary2header.sql" );
			// rename rev_change_type -> rev_comment, alternate patch is above for sqlite
			$updater->modifyExtensionField( 'flow_revision', 'rev_comment', "$dir/db_patches/patch-rev_change_type.sql" );
			// convert 128 bit uuid's into 88bit
			$updater->modifyExtensionField( 'flow_workflow', 'workflow_id', "$dir/db_patches/patch-88bit_uuids.sql" );
			$updater->addExtensionField( 'flow_workflow', 'workflow_type', "$dir/db_patches/patch-add_workflow_type.sql" );
		}

		$updater->addExtensionIndex( 'flow_workflow', 'flow_workflow_lookup', "$dir/db_patches/patch-workflow_lookup_idx.sql" );
		$updater->addExtensionIndex( 'flow_topic_list', 'flow_topic_list_topic_id', "$dir/db_patches/patch-topic_list_topic_id_idx.sql" );
		$updater->modifyExtensionField( 'flow_revision', 'rev_change_type', "$dir/db_patches/patch-rev_change_type_update.sql" );
		$updater->modifyExtensionField( 'recentchanges', 'rc_source', "$dir/db_patches/patch-rc_source.sql" );
		$updater->modifyExtensionField( 'flow_revision', 'rev_change_type', "$dir/db_patches/patch-censor_to_suppress.sql" );
		$updater->addExtensionField( 'flow_workflow', 'workflow_user_ip', "$dir/db_patches/patch-remove_usernames.sql" );
		$updater->addExtensionField( 'flow_workflow', 'workflow_user_wiki', "$dir/db_patches/patch-add-wiki.sql" );
		$updater->addExtensionIndex( 'flow_tree_revision', 'flow_tree_descendant_rev_id', "$dir/db_patches/patch-flow_tree_idx_fix.sql" );
		$updater->dropExtensionField( 'flow_tree_revision', 'tree_orig_create_time', "$dir/db_patches/patch-tree_orig_create_time.sql" );
		$updater->addExtensionIndex( 'flow_revision', 'flow_revision_user', "$dir/db_patches/patch-revision_user_idx.sql" );
		$updater->modifyExtensionField( 'flow_revision', 'rev_user_ip', "$dir/db_patches/patch-revision_user_ip.sql" );
		$updater->addExtensionField( 'flow_revision', 'rev_type_id', "$dir/db_patches/patch-rev_type_id.sql" );
		$updater->addExtensionTable( 'flow_ext_ref', "$dir/db_patches/patch-add-linkstables.sql" );
		$updater->dropExtensionTable( 'flow_definition', "$dir/db_patches/patch-drop_definition.sql" );

		require_once __DIR__.'/maintenance/FlowUpdateRecentChanges.php';
		$updater->addPostDatabaseUpdateMaintenance( 'FlowUpdateRecentChanges' );

		require_once __DIR__.'/maintenance/FlowSetUserIp.php';
		$updater->addPostDatabaseUpdateMaintenance( 'FlowSetUserIp' );

		/*
		 * Remove old *_user_text columns once the maintenance script that
		 * moves the necessary data has been run.
		 * This duplicates what is being done in FlowSetUserIp already, but that
		 * was not always the case, so that script may have already run without
		 * having executed this.
		 */
		if ( $updater->updateRowExists( 'FlowSetUserIp' ) ) {
			$updater->dropExtensionField( 'flow_workflow', 'workflow_user_text', "$dir/db_patches/patch-remove_usernames_2.sql" );
		}

		require_once __DIR__.'/maintenance/FlowUpdateUserWiki.php';
		$updater->addPostDatabaseUpdateMaintenance( 'FlowUpdateUserWiki' );

		require_once __DIR__.'/maintenance/FlowUpdateRevisionTypeId.php';
		$updater->addPostDatabaseUpdateMaintenance( 'FlowUpdateRevisionTypeId' );

		require_once __DIR__.'/maintenance/FlowPopulateLinksTables.php';
		$updater->addPostDatabaseUpdateMaintenance( 'FlowPopulateLinksTables' );

		return true;
	}

	/**
	 * Hook: UnitTestsList
	 *
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UnitTestsList
	 * @param &$files Array of unit test files
	 * @return bool true in all cases
	 */
	static function getUnitTests( &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/tests/*Test.php' ) );
		return true;
	}

	public static function onChangesListInitRows( \ChangesList $changesList, $rows ) {
		if ( !$changesList instanceof \OldChangesList ) {
			// We currently only handle the OldChangesList
			return;
		}

		set_error_handler( new Flow\RecoverableErrorHandler, -1 );
		try {
			Container::get( 'query.recentchanges' )->loadMetadataBatch( $rows );
		} catch ( Exception $e ) {
			\MWExceptionHandler::logException( $e );
		}
		restore_error_handler();
	}

	public static function onOldChangesListRecentChangesLine( \ChangesList &$changesList, &$s, \RecentChange $rc, &$classes = array() ) {
		$source = $rc->getAttribute( 'rc_source' );
		if ( $source === null ) {
			$rcType = (int) $rc->getAttribute( 'rc_type' );
			if ( $rcType !== RC_FLOW ) {
				return true;
			}
		} elseif ( $source !== Flow\Data\RecentChanges::SRC_FLOW ) {
			return true;
		}

		set_error_handler( new Flow\RecoverableErrorHandler, -1 );
		try {
			$query = Container::get( 'query.recentchanges' );
			$isWatchlist = $query->isWatchlist( $classes );
			// @todo: create hook to allow batch-loading this data
			$row = $query->getResult( $changesList, $rc, $isWatchlist );
			if ( $row === false ) {
				return false;
			}
			$line = Container::get( 'formatter.recentchanges' )->format( $row, $changesList );
		} catch ( Exception $e ) {
			wfDebugLog( 'Flow', __METHOD__ . ': Exception formatting rc ' . $rc->getAttribute( 'rc_id' ) . ' ' . $e );
			\MWExceptionHandler::logException( $e );
			restore_error_handler();
			return false;
		}
		restore_error_handler();

		if ( $line === false ) {
			return false;
		}

		$classes[] = 'flow-recentchanges-line';
		$s = $line;

		return true;
	}

	public static function onSpecialCheckUserGetLinksFromRow( CheckUser $checkUser, $row, &$links ) {
		if ( !$row->cuc_type == RC_FLOW ) {
			return true;
		}

		set_error_handler( new Flow\RecoverableErrorHandler, -1 );
		$replacement = null;
		try {
			/** @var CheckUserQuery $query */
			$query = Container::get( 'query.checkuser' );
			// @todo: create hook to allow batch-loading this data, instead of doing piecemeal like this
			$query->loadMetadataBatch( array( $row ) );
			$row = $query->getResult( $checkUser, $row );
			if ( $row !== false ) {
				$replacement = Container::get( 'formatter.checkuser' )->format( $row, $checkUser->getContext() );
			}
		} catch ( Exception $e ) {
			wfDebugLog( 'Flow', __METHOD__ . ': Exception formatting cu ' . $row->cuc_id . ' ' . $e );
			\MWExceptionHandler::logException( $e );
		}
		restore_error_handler();

		if ( $replacement === null ) {
			// some sort of failure, but this is a RC_FLOW so blank out hist/diff links
			// which aren't correct
			unset( $links['history'] );
			unset( $links['diff'] );
		} else {
			$links = $replacement;
		}

		return true;
	}

	/**
	 * Regular talk page "Create source" and "Add topic" links are quite useless
	 * in the context of Flow boards. Let's get rid of them.
	 *
	 * @param SkinTemplate $template
	 * @param array $links
	 * @return bool
	 */
	public static function onSkinTemplateNavigation( SkinTemplate &$template, &$links ) {
		global $wgFlowCoreActionWhitelist;

		$title = $template->getTitle();

		// if Flow is enabled on this talk page, overrule talk page red link
		if ( self::$occupationController->isTalkpageOccupied( $title ) ) {
			$skname = $template->getSkinName();

			$selected = $template->getRequest()->getVal( 'action' ) == 'history';
			$links['views'] = array( array(
				'class' => $selected ? 'selected' : '',
				'text' => wfMessageFallback( "$skname-view-history", "history_short" )->text(),
				'href' => $title->getLocalURL( 'action=history' ),
			) );

			// hide all ?action= links unless whitelisted
			foreach ( $links['actions'] as $action => $data ) {
				if ( !in_array( $action, $wgFlowCoreActionWhitelist ) ) {
					unset( $links['actions'][$action] );
				}
			}

			if ( isset( $links['namespaces']['topic_talk'] ) ) {
				// hide discussion page in Topic namespace(which is already discussion)
				unset( $links['namespaces']['topic_talk'] );
				// hide protection (topic protection is done via moderation)
				unset( $links['actions']['protect'] );
				// topic pages are also not movable
				unset( $links['actions']['move'] );
			}
		}

		return true;
	}

	/**
	 * Interact with the mobile skin's default modules on Flow enabled pages
	 *
	 * @param Skin $skin
	 * @param array $modules
	 * @return bool
	 */
	public static function onSkinMinervaDefaultModules( Skin $skin, array &$modules ) {
		// Disable toggling on occupied talk pages in mobile
		$title = $skin->getTitle();
		if ( self::$occupationController->isTalkpageOccupied( $title ) ) {
			$modules['toggling'] = array();
		}
		// Turn off default mobile talk overlay for these pages
		if ( $title->canTalk() ) {
			$talkPage = $title->getTalkPage();
			if ( self::$occupationController->isTalkpageOccupied( $talkPage ) ) {
				// TODO: Insert lightweight JavaScript that opens flow via ajax
				$modules['talk'] = array();
			}
		}

		return true;
	}

	/**
	 * When a (talk) page does not exist, one of the checks being performed is
	 * to see if the page had once existed but was removed. In doing so, the
	 * deletion & move log is checked.
	 *
	 * In theory, a Flow board could overtake a non-existing talk page. If that
	 * board is later removed, this will be run to see if a message can be
	 * displayed to inform the user if the page has been deleted/moved.
	 *
	 * Since, in Flow, we also write (topic, post, ...) deletion to the deletion
	 * log, we don't want those to appear, since they're not actually actions
	 * related to that talk page (rather: they were actions on the board)
	 *
	 * @param array &$conds Array of conditions
	 * @param array &$logTypes Array of log types
	 * @return bool
	 */
	public static function onMissingArticleConditions( array &$conds, array $logTypes ) {
		global $wgLogActionsHandlers;
		$actions = Container::get( 'flow_actions' );

		foreach ( $actions->getActions() as $action ) {
			foreach ( $logTypes as $logType ) {
				// Check if Flow actions are defined for the requested log types
				// and make sure they're ignored.
				if ( isset( $wgLogActionsHandlers["$logType/flow-$action"] ) ) {
					$conds[] = "log_action != " . wfGetDB( DB_SLAVE )->addQuotes( "flow-$action" );
				}
			}
		}

		return true;
	}

	/**
	 * Adds Flow entries to watchlists
	 * @param array &$types Type array to modify
	 * @return boolean true
	 */
	public static function onSpecialWatchlistGetNonRevisionTypes( &$types ) {
		$types[] = RC_FLOW;
		return true;
	}

	/**
	 * Make sure no user can register a flow-*-usertext username, to avoid
	 * confusion with a real user when we print e.g. "Suppressed" instead of a
	 * username. Additionally reserve the username used to add a revision on
	 * taking over a page.
	 *
	 * @param array $names
	 * @return bool
	 */
	public static function onUserGetReservedNames( &$names ) {
		$permissions = Flow\Model\AbstractRevision::$perms;
		foreach ( $permissions as $permission ) {
			$names[] = "msg:flow-$permission-usertext";
		}
		$names[] = 'msg:flow-system-usertext';

		// Reserve both the localized username and the English fallback for the
		// taking-over revision.
		$names[] = 'msg:flow-talk-username';
		$names[] = 'Flow talk page manager';

		return true;
	}

	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $wgFlowEditorList, $wgFlowDefaultLimit, $wgFlowMaxLimit;

		$vars['wgFlowEditorList'] = $wgFlowEditorList;
		$vars['wgFlowMaxTopicLength'] = Flow\Model\PostRevision::MAX_TOPIC_LENGTH;
		$vars['wgFlowPageSize'] = array(
			'expanded' => $wgFlowDefaultLimit,
			'collapsed-full' => min( $wgFlowDefaultLimit * 2, $wgFlowMaxLimit ),
			'collapsed-oneline' => min( $wgFlowDefaultLimit * 3, $wgFlowMaxLimit ),
		);

		return true;
	}

	/**
	 * Intercept contribution entries and format those belonging to Flow
	 *
	 * @param ContribsPager $pager Contributions object
	 * @param string &$ret The HTML line
	 * @param stdClass $row The data for this line
	 * @param array &$classes the classes to add to the surrounding <li>
	 * @return bool
	 */
	public static function onContributionsLineEnding( $pager, &$ret, $row, &$classes ) {
		if ( !$row instanceof Flow\Formatter\FormatterRow ) {
			return true;
		}

		set_error_handler( new Flow\RecoverableErrorHandler, -1 );
		try {
			$line = Container::get( 'formatter.contributions' )->format( $row, $pager );
		} catch ( Exception $e ) {
			MWExceptionHandler::logException( $e );
			$line = false;
		}
		restore_error_handler();

		if ( $line === false ) {
			return false;
		}

		$classes[] = 'mw-flow-contribution';
		$ret = $line;

		return true;
	}

	/**
	 * Adds Flow contributions to the Contributions special page
	 *
	 * @param $data array an array of results of all contribs queries, to be merged to form all contributions data
	 * @param ContribsPager $pager Object hooked into
	 * @param string $offset Index offset, inclusive
	 * @param int $limit Exact query limit
	 * @param bool $descending Query direction, false for ascending, true for descending
	 * @return bool
	 */
	public static function onContributionsQuery( &$data, $pager, $offset, $limit, $descending ) {
		global $wgFlowOccupyNamespaces, $wgFlowOccupyPages;

		// Flow has nothing to do with the tag filter, so ignore tag searches
		if ( $pager->tagFilter != false ) {
			return true;
		}

		// Ignore when looking in a specific namespace where there is no Flow
		if ( $pager->namespace !== '' ) {
			// Flow enabled on entire namespace(s)
			$namespaces = array_flip( $wgFlowOccupyNamespaces );

			// Flow enabled on specific pages - get those namespaces
			foreach ( $wgFlowOccupyPages as $page ) {
				$title = Title::newFromText( $page );
				$namespaces[$title->getNamespace()] = 1;
			}

			if ( !isset( $namespaces[$pager->namespace] ) ) {
				return true;
			}
		}

		set_error_handler( new Flow\RecoverableErrorHandler, -1 );
		try {
			$results = Container::get( 'query.contributions' )->getResults( $pager, $offset, $limit, $descending );
		} catch ( Exception $e ) {
			wfDebugLog( 'Flow', __METHOD__ . ': Failed contributions query' );
			\MWExceptionHandler::logException( $e );
			$results = false;
		}
		restore_error_handler();

		if ( $results === false ) {
			return false;
		}

		$data[] = $results;

		return true;
	}

	/**
	 * Adds lazy-load methods for AbstractRevision objects.
	 *
	 * @param string $method: Method to generate the variable
	 * @param AbuseFilterVariableHolder $vars
	 * @param array $parameters Parameters with data to compute the value
	 * @param mixed &$result Result of the computation
	 * @return bool
	 */
	public static function onAbuseFilterComputeVariable( $method, AbuseFilterVariableHolder $vars, $parameters, &$result ) {
		// fetch all lazy-load methods
		$methods = self::$abuseFilter->lazyLoadMethods();

		// method isn't known here
		if ( !isset( $methods[$method] ) ) {
			return true;
		}

		// fetch variable result from lazy-load method
		$result = $methods[$method]( $vars, $parameters );
		return false;
	}

	/**
	 * Abort notifications coming from RecentChange class, Flow has its
	 * own notifications through Echo.
	 *
	 * @param User $editor
	 * @param Title $title
	 * @return bool false to abort email notification
	 */
	public static function onAbortEmailNotification( $editor, $title ) {
		if ( self::$occupationController->isTalkpageOccupied( $title ) ) {
			return false;
		}

		return true;
	}

	public static function onInfoAction( IContextSource $ctx, &$pageinfo ) {
		if ( !self::$occupationController->isTalkpageOccupied( $ctx->getTitle() ) ) {
			return true;
		}

		// All of the info in this section is wrong for Flow pages,
		// so we'll just remove it.
		unset( $pageinfo['header-edits'] );

		// These keys are wrong on Flow pages, so we'll remove them
		static $badMessageKeys = array( 'pageinfo-length', 'pageinfo-content-model' );

		foreach ( $pageinfo['header-basic'] as $num => $val ) {
			if ( $val[0] instanceof Message && in_array( $val[0]->getKey(), $badMessageKeys ) ) {
				unset($pageinfo['header-basic'][$num]);
			}
		}
		return true;
	}

	/**
	 * Overwrite terms of use message if the overwrite exits
	 *
	 * @param string &$key
	 * @return bool
	 */
	public static function onMessageCacheGet( &$key ) {
		global $wgResourceModules;

		static $terms = array (
			'flow-terms-of-use-new-topic' => null,
			'flow-terms-of-use-reply' => null,
			'flow-terms-of-use-edit' => null,
			'flow-terms-of-use-summarize' => null,
			'flow-terms-of-use-close-topic' => null,
			'flow-terms-of-use-reopen-topic' => null
		);

		if ( !array_key_exists( $key, $terms ) ) {
			return true;
		}

		if ( $terms[$key] === null ) {
			$message = wfMessage( "wikimedia-$key" );
			if ( $message->exists() ) {
				$terms[$key] = "wikimedia-$key";
				$wgResourceModules['ext.flow.templating']['messages'][] = "wikimedia-$key";
			} else {
				$terms[$key] = false;
			}
		}

		if ( $terms[$key] ) {
			$key = $terms[$key];
		}
		return true;
	}

	/**
	 * @param RecentChange $rc
	 * @param array &$rcRow
	 * @return bool
	 */
	public static function onCheckUserInsertForRecentChange( RecentChange $rc, array &$rcRow ) {
		if ( $rc->getAttribute( 'rc_source' ) !== Flow\Data\RecentChanges::SRC_FLOW ) {
			return true;
		}

		$params = unserialize( $rc->getAttribute( 'rc_params' ) );
		$change = $params['flow-workflow-change'];

		// don't forget to increase the version number when data format changes
		$comment = CheckUserQuery::VERSION_PREFIX;
		$comment .= ',' . $change['action'];
		$comment .= ',' . $change['workflow'];
		$comment .= ',' . $change['revision'];
		if ( isset( $change['post'] ) ) {
			$comment .= ',' . $change['post'];
		}

		$rcRow['cuc_comment'] = $comment;

		return true;
	}

	public static function onIRCLineURL( &$url, &$query, RecentChange $rc ) {
		if ( $rc->getAttribute( 'rc_source' ) !== Flow\Data\RecentChanges::SRC_FLOW ) {
			return true;
		}

		set_error_handler( new Flow\RecoverableErrorHandler, -1 );
		$result = null;
		try {
			$result = Container::get( 'formatter.irclineurl' )->format( $rc );
		} catch ( Exception $e ) {
			wfDebugLog( 'Flow', __METHOD__ . ': Failed formatting rc ' . $rc->getAttribute( 'rc_id' ) );
			\MWExceptionHandler::logException( $e );
		}
		restore_error_handler();

		if ( $result !== null ) {
			$url = $result;
			$query = '';
		}

		return true;
	}

	public static function onWhatLinksHereProps( $row, $title, $target, &$props ) {
		$newProps = Flow\Container::get( 'reference.clarifier' )->getWhatLinksHereProps( $row, $title, $target );

		$props = array_merge( $props, $newProps );

		return true;
	}

	public static function onLinksUpdateConstructed( $linksUpdate ) {
		Flow\Container::get( 'reference.updater.links-tables' )
			->mutateLinksUpdate( $linksUpdate );

		return true;
	}

	/**
	 * Add topiclist sortby to preferences.
	 * @param $user User object
	 * @param &$preferences array Preferences object
	 * @return bool
	 */
	public static function onGetPreferences( $user, &$preferences ) {
		$preferences['flow-topiclist-sortby'] = array(
			'type' => 'api',
		);

		return true;
	}

	/**
	 * ResourceLoaderTestModules hook handler
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderTestModules
	 *
	 * @param array $testModules
	 * @param ResourceLoader $resourceLoader
	 * @return bool
	 */
	public static function onResourceLoaderTestModules( array &$testModules,
		ResourceLoader &$resourceLoader
	) {
		global $wgResourceModules;

		// find test files for every RL module
		foreach ( $wgResourceModules as $key => $module ) {
			if ( substr( $key, 0, 9 ) === 'ext.flow.' && isset( $module['scripts'] ) ) {
				$testFiles = array();

				$scripts = (array) $module['scripts'];
				foreach ( $scripts as $script ) {
					$testFile = 'tests/qunit/' . dirname( $script ) . '/test_' . basename( $script );
					// if a test file exists for a given JS file, add it
					if ( file_exists( __DIR__ . '/' . $testFile ) ) {
						$testFiles[] = $testFile;
					}
				}
				// if test files exist for given module, create a corresponding test module
				if ( count( $testFiles ) > 0 ) {
					$module = array(
						'remoteExtPath' => 'Flow',
						'dependencies' => array( $key ),
						'localBasePath' => __DIR__,
						'scripts' => $testFiles,
					);
					$testModules['qunit']["$key.tests"] = $module;
				}
			}
		}

		return true;
	}

	/**
	 * Don't (un)watch a non-existing flow topic
	 */
	public static function onWatchArticle( &$user, &$page, &$status ) {
		$title = $page->getTitle();
		if ( $title->getNamespace() == NS_TOPIC ) {
			// @todo - use !$title->exists()?
			$found = Container::get( 'storage' )->find(
				'PostRevision',
				array( 'rev_type_id' => strtolower( $title->getDBkey() ) ),
				array( 'sort' => 'rev_id', 'order' => 'DESC', 'limit' => 1 )
			);
			if ( !$found ) {
				return false;
			}
			$post = reset( $found );
			if ( !$post->isTopicTitle() ) {
				return false;
			}
		}
		return true;
	}

}
