/*!
 *
 */

( function ( $, mw ) {
	/**
	 *
	 * @example <div class="flow-component" data-flow-component="boardHistory" data-flow-id="rqx495tvz888x5ur">...</div>
	 * @param {jQuery} $container
	 * @extends FlowBoardAndHistoryComponentBase
	 * @constructor
	 */
	function FlowBoardHistoryComponent( $container ) {
		this.bindNodeHandlers( FlowBoardHistoryComponent.UI.events );
	}
	OO.initClass( FlowBoardHistoryComponent );

	FlowBoardHistoryComponent.UI = {
		events: {
			apiHandlers: {}
		}
	};

	mw.flow.registerComponent( 'boardHistory', FlowBoardHistoryComponent, 'boardAndHistoryBase' );

	//
	// API handlers
	//

	/**
	 * After submit of a moderation form, process the response.
	 *
	 * @param {Object} info (status:done|fail, $target: jQuery)
	 * @param {Object} data
	 * @param {jqXHR} jqxhr
	 */
	function flowBoardHistoryModerationCallback( info, data, jqxhr ) {
		if ( info.status !== 'done' ) {
			// Error will be displayed by default, nothing else to wrap up
			return;
		}

		var flowBoardHistory = mw.flow.getPrototypeMethod( 'boardHistory', 'getInstanceByElement' )( $( this ) );

		// Clear the form so we can refresh without the confirmation dialog
		flowBoardHistory.emitWithReturn( 'cancelForm', $( this ).closest( 'form' ) );

		// @todo implement dynamic updating of the history page instead of this
		window.location.reload();
	}

	FlowBoardHistoryComponent.UI.events.apiHandlers.moderateTopic = flowBoardHistoryModerationCallback;
	FlowBoardHistoryComponent.UI.events.apiHandlers.moderatePost = flowBoardHistoryModerationCallback;
}( jQuery, mediaWiki ) );