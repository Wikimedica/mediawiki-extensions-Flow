/* global ve */
( function () {
	'use strict';

	mw.flow.ve.ce = mw.flow.ve.ce || {};

	/**
	 * ContentEditable node for a Flow attachment image: renders the actual
	 * image in the editor, selectable, deletable and resizable as a single
	 * unit.
	 *
	 * @class
	 * @extends ve.ce.LeafNode
	 * @mixes ve.ce.ImageNode
	 *
	 * @constructor
	 * @param {mw.flow.ve.dm.AttachmentImageNode} model Model to observe
	 * @param {Object} [config] Configuration options
	 */
	mw.flow.ve.ce.AttachmentImageNode = function MwFlowVeCeAttachmentImageNode( model, config ) {
		config = ve.extendObject( {
			minDimensions: { width: 1, height: 1 }
		}, config );

		// Parent constructor
		mw.flow.ve.ce.AttachmentImageNode.super.call( this, model, config );

		// Mixin constructor (handles focus, resize handles and attribute sync)
		ve.ce.ImageNode.call( this, this.$element, null, config );

		this.$element
			.addClass( 'flow-ve-attachmentImage' )
			.prop( {
				alt: this.model.getAttribute( 'alt' ) || '',
				src: this.getResolvedAttribute( 'src' )
			} )
			.css( {
				width: this.model.getAttribute( 'width' ),
				height: this.model.getAttribute( 'height' )
			} );
	};

	OO.inheritClass( mw.flow.ve.ce.AttachmentImageNode, ve.ce.LeafNode );

	OO.mixinClass( mw.flow.ve.ce.AttachmentImageNode, ve.ce.ImageNode );

	mw.flow.ve.ce.AttachmentImageNode.static.name = 'flowAttachmentImage';

	mw.flow.ve.ce.AttachmentImageNode.static.tagName = 'img';

	/* Registration */

	ve.ce.nodeFactory.register( mw.flow.ve.ce.AttachmentImageNode );
}() );
