/*!
 * Initializes Flow boards that were server-rendered and embedded in a wiki page.
 *
 * When the parser encounters {{Discussion_gestion:SomePage}}, Hooks::onFlowEmbedTag()
 * renders the board HTML server-side and wraps it in:
 *   <div class="flow-embedded-board" data-flow-page="Discussion_gestion:SomePage">
 *     ...rendered board HTML...
 *   </div>
 *
 * flow-initialize.js skips .flow-component elements inside .flow-embedded-board.
 * This module picks them up and runs the standard Flow initialization sequence
 * using the correct pageTitle from the data attribute.
 */
( function () {
	'use strict';

	/**
	 * Run the Flow initialization sequence for one embedded board.
	 *
	 * @param {jQuery} $container .flow-embedded-board element (already contains board HTML)
	 */
	function initEmbeddedBoard( $container ) {
		// eslint-disable-next-line no-jquery/no-global-selector
		const $component = $container.find( '.flow-component' );
		// eslint-disable-next-line no-jquery/no-global-selector
		const $board = $container.find( '.flow-board' );
		const page = $container.data( 'flow-page' );

		if ( !$component.length || !page ) {
			return;
		}

		const pageTitle = mw.Title.newFromText( page );
		const initializer = new mw.flow.Initializer( {
			pageTitle: pageTitle,
			$component: $component,
			$board: $board
		} );

		if ( !initializer.setComponentDom( $component ) ) {
			initializer.finishLoading();
			return;
		}

		initializer.initOldComponent();

		if ( initializer.setBoardDom( $board ) ) {
			const flowBoard = mw.flow.getPrototypeMethod( 'component', 'getInstanceByElement' )( $board );
			initializer.setBoardObject( flowBoard );

			initializer.initDataModel( {
				pageTitle: pageTitle,
				tocPostsLimit: 50,
				renderedTopics: $board.find( '.flow-topic' ).length,
				boardId: $component.data( 'flow-id' ),
				defaultSort: $board.data( 'flow-sortby' )
			} );

			// mw.flow.system is a global read by NewTopicWidget (and others) during
			// widget construction.  Mirror what flow-initialize.js does.
			mw.flow.system = initializer.getDataModelSystem();

			initializer.initializeWidgets();
			// Pass null to force a live API fetch (instead of using wgFlowData
			// which belongs to a different page)
			initializer.populateDataModel( null );
		}

		initializer.finishLoading();
	}

	mw.hook( 'wikipage.content' ).add( function () {
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.flow-embedded-board[data-flow-page]' ).each( function () {
			initEmbeddedBoard( $( this ) );
		} );
	} );

}() );
