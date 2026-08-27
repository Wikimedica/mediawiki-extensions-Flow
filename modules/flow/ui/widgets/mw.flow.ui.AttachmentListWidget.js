( function () {
	/**
	 * The "Attached files" panel of a topic. It is not shown on its own:
	 * the "Attached files" entry of the topic's context menu toggles it.
	 * When shown, it fetches the topic's attachments from the
	 * flow-attachment-list API and lists them as download links.
	 *
	 * Only created for registered users: attachments are never served to
	 * anonymous visitors.
	 *
	 * @class
	 * @extends OO.ui.Widget
	 *
	 * @constructor
	 * @param {string} topicId Alphadecimal id of the topic workflow
	 * @param {Object} [config] Configuration options
	 */
	mw.flow.ui.AttachmentListWidget = function mwFlowUiAttachmentListWidget( topicId, config ) {
		config = config || {};

		// Parent constructor
		mw.flow.ui.AttachmentListWidget.super.call( this, config );

		this.topicId = topicId;
		this.expanded = false;
		this.pending = false;

		this.$heading = $( '<div>' )
			.addClass( 'flow-ui-attachmentListWidget-heading' )
			.text( mw.msg( 'flow-attachments-heading' ) );

		this.$list = $( '<div>' )
			.addClass( 'flow-ui-attachmentListWidget-list' );

		this.$element
			.addClass( 'flow-ui-attachmentListWidget' )
			.append( this.$heading, this.$list )
			.hide();
	};

	/* Initialization */

	OO.inheritClass( mw.flow.ui.AttachmentListWidget, OO.ui.Widget );

	/* Methods */

	/**
	 * Toggle the panel. Every time it is shown, the list is re-fetched so it
	 * reflects recent replies.
	 */
	mw.flow.ui.AttachmentListWidget.prototype.togglePanel = function () {
		const widget = this;

		if ( this.expanded ) {
			this.expanded = false;
			this.$element.hide();
			return;
		}
		if ( this.pending ) {
			return;
		}

		this.pending = true;
		new mw.Api().get( {
			action: 'flow-attachment-list',
			formatversion: 2,
			page: 'Topic:' + this.topicId
		} ).then( ( response ) => {
			const result = response[ 'flow-attachment-list' ];
			widget.render( ( result && result.attachments ) || [] );
			widget.expanded = true;
			widget.$element.show();
		} ).always( () => {
			widget.pending = false;
		} );
	};

	/**
	 * Render the attachment list.
	 *
	 * @private
	 * @param {Object[]} attachments Attachment data from the API
	 */
	mw.flow.ui.AttachmentListWidget.prototype.render = function ( attachments ) {
		this.$list.empty();

		if ( !attachments.length ) {
			this.$list.append(
				$( '<div>' )
					.addClass( 'flow-ui-attachmentListWidget-empty' )
					.text( mw.msg( 'flow-attachments-none' ) )
			);
			return;
		}

		const $ul = $( '<ul>' );
		attachments.forEach( ( attachment ) => {
			$ul.append(
				$( '<li>' ).append(
					$( '<a>' )
						.attr( {
							href: attachment.url,
							target: attachment.isImage ? '_blank' : null,
							download: attachment.isImage ? null : attachment.name
						} )
						.text( attachment.name ),
					$( '<span>' )
						.addClass( 'flow-ui-attachmentListWidget-size' )
						.text( ' (' + this.constructor.static.formatSize( attachment.size ) + ')' )
				)
			);
		} );
		this.$list.append( $ul );
	};

	/**
	 * Human-readable file size.
	 *
	 * @param {number} bytes
	 * @return {string}
	 */
	mw.flow.ui.AttachmentListWidget.static.formatSize = function ( bytes ) {
		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i === 0 ? bytes : bytes.toFixed( 1 ) ) + ' ' + units[ i ];
	};
}() );
