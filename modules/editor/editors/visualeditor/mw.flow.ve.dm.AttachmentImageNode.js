/* global ve */
( function () {
	'use strict';

	mw.flow.ve.dm = mw.flow.ve.dm || {};

	/**
	 * DataModel node for an image attached to a Flow topic: a plain <img>
	 * whose src points at Special:FlowAttachment. Displayed as the actual
	 * image while editing (focusable and resizable); stored as-is and
	 * upgraded at render time by the server-side AttachmentFixer.
	 *
	 * @class
	 * @extends ve.dm.LeafNode
	 * @mixes ve.dm.ImageNode
	 *
	 * @constructor
	 */
	mw.flow.ve.dm.AttachmentImageNode = function MwFlowVeDmAttachmentImageNode() {
		// Parent constructor
		mw.flow.ve.dm.AttachmentImageNode.super.apply( this, arguments );

		// Mixin constructor
		ve.dm.ImageNode.call( this );
	};

	OO.inheritClass( mw.flow.ve.dm.AttachmentImageNode, ve.dm.LeafNode );

	OO.mixinClass( mw.flow.ve.dm.AttachmentImageNode, ve.dm.ImageNode );

	mw.flow.ve.dm.AttachmentImageNode.static.name = 'flowAttachmentImage';

	mw.flow.ve.dm.AttachmentImageNode.static.isContent = true;

	mw.flow.ve.dm.AttachmentImageNode.static.matchTagNames = [ 'img' ];

	mw.flow.ve.dm.AttachmentImageNode.static.matchFunction = function ( domElement ) {
		return ( domElement.getAttribute( 'src' ) || '' ).indexOf( 'FlowAttachment/' ) !== -1;
	};

	mw.flow.ve.dm.AttachmentImageNode.static.toDataElement = function ( domElements ) {
		const domElement = domElements[ 0 ],
			src = domElement.getAttribute( 'src' ) || '';
		let width = domElement.getAttribute( 'width' ),
			height = domElement.getAttribute( 'height' );

		// The size is carried in the URL query (?w=&h=), because that is the
		// only thing that survives Flow's HTML→wikitext→HTML storage
		// round-trip; explicit attributes take precedence when present.
		if ( width === null || width === '' ) {
			const match = src.match( /[?&]w=(\d+)/ );
			width = match ? match[ 1 ] : null;
		}
		if ( height === null || height === '' ) {
			const match = src.match( /[?&]h=(\d+)/ );
			height = match ? match[ 1 ] : null;
		}

		return {
			type: this.name,
			attributes: {
				src: src,
				alt: domElement.getAttribute( 'alt' ),
				width: width !== null && width !== '' ? +width : null,
				height: height !== null && height !== '' ? +height : null
			}
		};
	};

	mw.flow.ve.dm.AttachmentImageNode.static.toDomElements = function ( dataElement, doc ) {
		const domElement = doc.createElement( 'img' ),
			attributes = dataElement.attributes,
			base = ( attributes.src || '' ).split( '?' )[ 0 ];

		// Encode the chosen size into the URL query so it survives the
		// wikitext round-trip on save
		let src = base;
		if ( attributes.width !== null && attributes.width !== undefined ) {
			src += '?w=' + attributes.width;
			if ( attributes.height !== null && attributes.height !== undefined ) {
				src += '&h=' + attributes.height;
			}
		}

		domElement.setAttribute( 'src', src );
		ve.setDomAttributes( domElement, attributes, [ 'alt', 'width', 'height' ] );
		return [ domElement ];
	};

	/* Registration */

	ve.dm.modelRegistry.register( mw.flow.ve.dm.AttachmentImageNode );
}() );
