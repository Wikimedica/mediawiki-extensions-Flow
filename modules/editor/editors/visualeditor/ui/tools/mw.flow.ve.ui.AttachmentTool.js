/* global ve */
( function () {
	'use strict';

	/**
	 * Tool in the toolbar's "Insert" menu that attaches a file to the post
	 * being written: it opens the file picker of the surrounding Flow editor
	 * widget, which uploads the file and inserts it into the content.
	 *
	 * @class
	 * @extends ve.ui.Tool
	 *
	 * @constructor
	 * @param {OO.ui.ToolGroup} toolGroup
	 * @param {Object} [config] Configuration options
	 */
	mw.flow.ve.ui.AttachmentTool = function MwFlowVeUiAttachmentTool() {
		// Parent constructor
		mw.flow.ve.ui.AttachmentTool.super.apply( this, arguments );
	};

	OO.inheritClass( mw.flow.ve.ui.AttachmentTool, ve.ui.Tool );

	mw.flow.ve.ui.AttachmentTool.static.name = 'flowAttachment';

	mw.flow.ve.ui.AttachmentTool.static.icon = 'attachment';

	mw.flow.ve.ui.AttachmentTool.static.title = OO.ui.deferMsg( 'flow-attachment-upload-button' );

	// No associated command: the tool delegates to the Flow editor widget
	mw.flow.ve.ui.AttachmentTool.static.commandName = null;

	mw.flow.ve.ui.AttachmentTool.static.autoAddToCatchall = true;

	mw.flow.ve.ui.AttachmentTool.prototype.onSelect = function () {
		const widget = this.toolbar.getSurface().$element
			.closest( '.flow-ui-editorWidget' )
			.data( 'flowEditorWidget' );

		if ( widget ) {
			widget.onAttachButtonClick();
		}
		this.setActive( false );
	};

	mw.flow.ve.ui.AttachmentTool.prototype.onUpdateState = function () {
		this.setDisabled( false );
	};

	/* Registration */

	ve.ui.toolFactory.register( mw.flow.ve.ui.AttachmentTool );
}() );
