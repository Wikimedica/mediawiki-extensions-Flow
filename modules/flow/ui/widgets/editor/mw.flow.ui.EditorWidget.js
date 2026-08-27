/* global ve */
( function () {
	/**
	 * Flow editor widget
	 *
	 * @class
	 * @extends OO.ui.Widget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @param {string} [config.placeholder] Placeholder text to use for the editor when empty
	 * @param {string} [config.termsKey='edit'] terms-of-use message key for the footer message
	 * @param {string} [config.saveMsgKey='flow-newtopic-save'] i18n message key for the save button
	 * @param {string} [config.cancelMsgKey='flow-cancel'] i18n message key for the cancel button
	 * @param {boolean} [config.autoFocus=true] Automatically focus after switching editors
	 * @param {boolean} [config.confirmLeave=true] Pop up a confirmation dialog if the user attempts
	 *  to navigate away when there are changes in the editor.
	 * @param {Function} [config.leaveCallback] Function to call when the user attempts to navigate away.
	 *  If this function returns false, a confirmation dialog will be popped up.
	 * @param {boolean} [config.saveable=true] Initial state of whether editor is saveable
	 */
	mw.flow.ui.EditorWidget = function mwFlowUiEditorWidget( config ) {
		const widget = this;

		config = config || {};

		// Parent constructor
		mw.flow.ui.EditorWidget.super.call( this, config );

		// Mixin constructors
		OO.ui.mixin.PendingElement.call( this, config );

		this.useVE = this.constructor.static.isVisualEditorSupported();

		this.placeholder = config.placeholder || '';
		this.confirmLeave = !!config.confirmLeave || config.confirmLeave === undefined;
		this.leaveCallback = config.leaveCallback;
		this.id = config.id;

		this.loadPromise = null;

		this.error = new OO.ui.LabelWidget( {
			classes: [ 'flow-ui-editorWidget-error flow-errors flow-errorbox mw-message-box mw-message-box-error' ]
		} );
		this.error.toggle( false );

		this.editorControlsWidget = new mw.flow.ui.EditorControlsWidget( {
			termsKey: config.termsKey || 'edit',
			saveMsgKey: config.saveMsgKey || 'flow-newtopic-save',
			cancelMsgKey: config.cancelMsgKey || 'flow-cancel',
			saveable: this.saveable
		} );

		this.wikitextHelpLabel = new OO.ui.LabelWidget( {
			classes: [ 'flow-ui-editorWidget-wikitextHelpLabel' ],
			label: $( '<span>' ).append(
				mw.message( 'flow-wikitext-editor-help-and-preview' ).params( [
					// Link to help page
					$( '<span>' )
						.html( mw.message( 'flow-wikitext-editor-help-uses-wikitext' ).parse() )
						.find( 'a' )
						.attr( 'target', '_blank' )
						.end(),
					// Preview link
					$( '<a>' )
						.attr( 'href', '#' )
						.addClass( 'flow-ui-editorWidget-label-preview' )
						.text( mw.message( 'flow-wikitext-editor-help-preview-the-result' ).text() )
				] ).parse() )
				.find( '.flow-ui-editorWidget-label-preview' )
				.on( 'click', this.onPreviewLinkClick.bind( this ) )
				.end()
		} );
		this.wikitextHelpLabel.toggle( false );

		this.$editorWrapper = $( '<div>' )
			.addClass( 'flow-ui-editorWidget-editor' )
			.append( this.wikitextHelpLabel.$element );
		this.setPendingElement( this.$editorWrapper );
		if ( !this.useVE ) {
			this.input = new OO.ui.MultilineTextInputWidget( {
				autosize: true,
				maxRows: 999,
				placeholder: this.placeholder,
				// The following classes can be used here:
				// * mw-editfont-default
				// * mw-editfont-monospace
				// * mw-editfont-sans-serif
				// * mw-editfont-serif
				classes: [ 'flow-ui-editorWidget-input', 'mw-editfont-' + mw.user.options.get( 'editfont' ) ]
			} );
			this.input.toggle( false );
			this.input.connect( this, {
				change: [ 'emit', 'change' ],
				enter: 'onTargetSubmit'
			} );
			this.$editorWrapper.append( this.input.$element );
			// VE focus listeners are bound in #onTargetSurfaceReady
			this.$element
				.on( 'focusin', this.onEditorFocusIn.bind( this ) )
				.on( 'focusout', this.onEditorFocusOut.bind( this ) );
		}

		this.toggleAutoFocus( config.autoFocus === undefined ? true : !!config.autoFocus );
		this.toggleSaveable( config.saveable !== undefined ? config.saveable : true );

		// Events
		this.editorControlsWidget.connect( this, {
			cancel: 'onEditorControlsWidgetCancel',
			save: 'onEditorControlsWidgetSave',
			attach: 'onAttachButtonClick'
		} );

		// File attachments: registered users can attach files to their post
		// with the paperclip button, drag & drop, or paste.
		if ( !mw.user.isAnon() ) {
			this.setupAttachmentUpload();
		}

		this.$element.on( 'keydown', ( e ) => {
			if ( e.which === OO.ui.Keys.ESCAPE ) {
				widget.onEditorControlsWidgetCancel();
				e.preventDefault();
				e.stopPropagation();
			}
		} );

		this.$element
			.append(
				this.$editorWrapper,
				this.error.$element,
				this.editorControlsWidget.$element
			)
			.addClass( 'flow-ui-editorWidget' );
	};

	/* Events */

	/**
	 * @event saveContent
	 * @param {string} content Content to save
	 * @param {string} format Format of content ('html' or 'wikitext')
	 */

	/**
	 * @event cancel
	 * The user clicked the cancel button.
	 */

	/**
	 * @event change
	 * The contents of the editor changed.
	 */

	/* Initialization */

	OO.inheritClass( mw.flow.ui.EditorWidget, OO.ui.Widget );
	OO.mixinClass( mw.flow.ui.EditorWidget, OO.ui.mixin.PendingElement );

	/* Static methods */

	mw.flow.ui.EditorWidget.static.isVisualEditorSupported = function () {
		/* global VisualEditorSupportCheck:false */
		return !!(
			//!OO.ui.isMobile() &&
			mw.loader.getState( 'ext.visualEditor.core' ) &&
			mw.user.options.get( 'flow-visualeditor' ) &&
			window.VisualEditorSupportCheck && VisualEditorSupportCheck()
		);
	};

	/**
	 * Convert links to Special:FlowAttachment that point at an image file
	 * into <img> elements, so the image is displayed while editing. Links to
	 * non-image attachments are left untouched.
	 *
	 * @param {string} html HTML content
	 * @return {string} HTML content with attachment image links upgraded
	 */
	mw.flow.ui.EditorWidget.static.upgradeAttachmentLinks = function ( html ) {
		if ( html.indexOf( 'FlowAttachment/' ) === -1 ) {
			return html;
		}
		// Stored content may be a full document with an <html>/<head> wrapper
		// (e.g. a <base> tag); preserve it in that case
		const hasDocWrapper = /<html[\s>]|<head[\s>]|<body[\s>]/i.test( html );
		const doc = new DOMParser().parseFromString( html, 'text/html' );
		doc.querySelectorAll( 'a[href*="FlowAttachment/"]' ).forEach( ( link ) => {
			const href = link.getAttribute( 'href' );
			// The URL may carry the display size as a ?w=&h= query
			if ( !/\.(png|jpe?g|gif|webp)(\?[^#]*)?$/i.test( href ) ) {
				return;
			}
			const img = doc.createElement( 'img' );
			img.setAttribute( 'src', href );
			img.setAttribute( 'alt', link.textContent );
			link.replaceWith( img );
		} );
		return hasDocWrapper ? doc.documentElement.outerHTML : doc.body.innerHTML;
	};

	/**
	 * Preload the VisualEditor modules so that loading the editor later will be faster.
	 *
	 * @return {jQuery.Promise} Promise that resolves when the VisualEditor modules have been loaded
	 */
	mw.flow.ui.EditorWidget.static.preload = function () {
		let conf, modules;
		if ( !this.preloadPromise ) {
			if ( this.isVisualEditorSupported() ) {
				conf = mw.config.get( 'wgVisualEditorConfig' );
				modules = [ OO.ui.isMobile() ? 'ext.flow.mobileVisualEditor' : 'ext.flow.visualEditor' ].concat(
					conf.pluginModules.filter( mw.loader.getState )
				);
				this.preloadPromise =
					mw.loader.using( conf.preloadModules )
						// If these fail, we still want to continue loading, so convert failure to success
						.catch( () => $.Deferred().resolve() )
						.then( () => mw.loader.using( modules ) );
			} else {
				this.preloadPromise = $.Deferred().resolve().promise();
			}
		}
		return this.preloadPromise;
	};

	/**
	 * Load the VisualEditor code and create this.target.
	 *
	 * Calling this method externally can be useful to preload VisualEditor, but is not functionally
	 * necessary. #activate calls this method as well.
	 *
	 * It's safe to call this method multiple times, or to call it when loading is already
	 * complete: the same promise will be returned every time.
	 *
	 * @return {jQuery.Promise} Promise resolved when this.target has been created.
	 */
	mw.flow.ui.EditorWidget.prototype.load = function () {
		const widget = this;
		if ( !this.useVE ) {
			return $.Deferred().resolve().promise();
		}
		if ( !this.loadPromise ) {
			this.loadPromise = this.constructor.static.preload()
				.then( () => {
					widget.target = ve.init.mw.targetFactory.create( 'flow', { id: widget.id } );
					widget.target.connect( widget, {
						surfaceReady: 'onTargetSurfaceReady',
						switchMode: 'onTargetSwitchMode',
						cancel: 'onEditorControlsWidgetCancel',
						submit: 'onTargetSubmit'
					} );
					widget.$editorWrapper.prepend( widget.target.$element );
				} );
		}
		return this.loadPromise;
	};

	/**
	 * Activate the editor.
	 *
	 * @param {Object} [content] Content to preload into the editor
	 * @param {string} content.content Content
	 * @param {string} content.format Format of content ('html' or 'wikitext')
	 * @return {jQuery.Promise}
	 */
	mw.flow.ui.EditorWidget.prototype.activate = function ( content ) {
		const widget = this;
		if ( !this.useVE ) {
			// FIXME doesn't work with HTML, figure out if that can even ever be passed in
			this.originalContent = content && content.content || '';
			this.input.setValue( this.originalContent );
			this.input.toggle( true );
			this.maybeAutoFocus();
			return $.Deferred().resolve().promise();
		}

		this.pushPending();
		this.error.toggle( false );
		return this.load()
			.then( this.createSurface.bind( this, content ) )
			.then( () => {
				widget.bindBeforeUnloadHandler();
				widget.maybeAutoFocus();
				widget.wikitextHelpLabel.toggle( widget.target.getDefaultMode() === 'source' );
			}, ( error ) => {
				widget.error.setLabel( $( '<span>' ).text( error || mw.msg( 'flow-error-default' ) ) );
				widget.error.toggle( true );
			} )
			.always( () => {
				widget.popPending();
			} );
	};

	/**
	 * Create a VE surface with the provided content in it.
	 *
	 * @private
	 * @param {Object} content Content to put into the surface
	 * @param {string} content.content Content
	 * @param {string} content.format Format of content ('html' or 'wikitext')
	 * @return {jQuery.Promise} Promise which resolves when the surface is ready
	 */
	mw.flow.ui.EditorWidget.prototype.createSurface = function ( content ) {
		let contentToLoad,
			contentFormat,
			deferred = $.Deferred();

		if ( content ) {
			contentToLoad = content.content;
			contentFormat = content.format;
		} else {
			contentToLoad = '';
			contentFormat = this.getPreferredFormat();
		}
		if ( contentFormat === 'html' && contentToLoad ) {
			// Posts saved before inline attachment images existed store the
			// image as a link; upgrade it so it displays while editing
			contentToLoad = this.constructor.static.upgradeAttachmentLinks( contentToLoad );
		}
		this.target.setDefaultMode( contentFormat === 'html' ? 'visual' : 'source' );
		this.target.loadContent( contentToLoad );
		this.target.once( 'surfaceReady', () => {
			deferred.resolve();
		} );
		return deferred.promise();
	};

	/**
	 * If autofocus is enabled, focus the editor and move the cursor to the end.
	 *
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.maybeAutoFocus = function () {
		if ( this.autoFocus ) {
			this.focus();
			this.moveCursorToEnd();
		}
	};

	/**
	 * Toggle whether the editor is automatically focused after activating and switching.
	 *
	 * @param {boolean} [autoFocus] Whether to focus automatically; if unset, flips current value
	 */
	mw.flow.ui.EditorWidget.prototype.toggleAutoFocus = function ( autoFocus ) {
		this.autoFocus = autoFocus === undefined ? !this.autoFocus : !!autoFocus;
	};

	/**
	 * Toggle whether the editor is saveable,
	 *
	 * @param {boolean} [saveable] Whether the editor is saveable
	 */
	mw.flow.ui.EditorWidget.prototype.toggleSaveable = function ( saveable ) {
		this.saveable = saveable === undefined ? !this.saveable : !!saveable;

		// Disabled state depends on saveable state
		this.updateDisabled();
		// Update controls widget
		this.editorControlsWidget.toggleSaveable( this.saveable );
	};

	/**
	 * Check whether the editor is saveable.
	 *
	 * @return {boolean} Whether the user can save their content
	 */
	mw.flow.ui.EditorWidget.prototype.isSaveable = function () {
		return this.saveable;
	};

	/**
	 * Respond to focusin event.
	 *
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.onEditorFocusIn = function () {
		this.$element.addClass( 'flow-ui-editorWidget-focused' );
	};

	/**
	 * Respond to focusout event.
	 *
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.onEditorFocusOut = function () {
		this.$element.removeClass( 'flow-ui-editorWidget-focused' );
	};

	mw.flow.ui.EditorWidget.prototype.onPreviewLinkClick = function () {
		this.target.switchMode();
		return false;
	};

	/**
	 * Set up event listeners when a new surface is created. This happens every time we
	 * switch modes.
	 *
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.onTargetSurfaceReady = function () {
		const surface = this.target.getSurface();

		surface.setPlaceholder( this.placeholder );
		surface.getModel().connect( this, { documentUpdate: 'onSurfaceDocumentUpdate' } );
		surface.getView().connect( this, {
			focus: 'onEditorFocusIn',
			blur: 'onEditorFocusOut'
		} );
	};

	/**
	 * Every time the editor content changes, update the user's mode preference if necessary,
	 * and emit 'change'.
	 *
	 * @private
	 * @fires change
	 */
	mw.flow.ui.EditorWidget.prototype.onSurfaceDocumentUpdate = function () {
		// Update the user's preferred editor
		const currentEditor = this.target.getDefaultMode() === 'source' ? 'wikitext' : 'visualeditor';
		if ( mw.user.options.get( 'flow-editor' ) !== currentEditor ) {
			if ( !mw.user.isAnon() ) {
				new mw.Api().saveOption( 'flow-editor', currentEditor );
			}
			// Ensure we also see that preference in the current page
			mw.user.options.set( 'flow-editor', currentEditor );
		}

		this.emit( 'change' );
	};

	/**
	 * Respond to cancel event. Verify with the user that they want to cancel if
	 * there is changed data in the editor.
	 *
	 * @private
	 * @fires cancel
	 */
	mw.flow.ui.EditorWidget.prototype.onEditorControlsWidgetCancel = function () {
		const widget = this;

		if ( this.hasBeenChanged() ) {
			mw.flow.ui.windowManager.openWindow( 'cancelconfirm' ).closed.then( ( data ) => {
				if ( data && data.action === 'discard' ) {
					// Remove content
					widget.clearContent();
					widget.unbindBeforeUnloadHandler();
					widget.emit( 'cancel' );
				}
			} );
		} else {
			this.unbindBeforeUnloadHandler();
			this.emit( 'cancel' );
		}
	};

	/**
	 * Wire up the paperclip button, the hidden file input, and capture-phase
	 * drop/paste listeners. Capture phase is used so that files are handled
	 * here in every editor mode, before VisualEditor's own data transfer
	 * handlers (which would route images to the wiki-upload media dialog).
	 *
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.setupAttachmentUpload = function () {
		const element = this.$element[ 0 ];

		// With VisualEditor, the attach button lives in the toolbar's
		// "Insert" menu (mw.flow.ve.ui.AttachmentTool), which finds this
		// widget through the DOM; the controls-row paperclip is only for the
		// plain textarea editor.
		this.$element.data( 'flowEditorWidget', this );
		this.editorControlsWidget.attachButton.toggle( !this.useVE );

		this.$fileInput = $( '<input>' )
			.attr( { type: 'file', multiple: 'multiple' } )
			.addClass( 'flow-ui-editorWidget-fileInput' )
			.css( 'display', 'none' )
			.on( 'change', this.onFileInputChange.bind( this ) );
		this.$element.append( this.$fileInput );

		element.addEventListener( 'dragover', this.onEditorDragOver.bind( this ), true );
		element.addEventListener( 'drop', this.onEditorDrop.bind( this ), true );
		element.addEventListener( 'paste', this.onEditorPaste.bind( this ), true );
	};

	/**
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.onAttachButtonClick = function () {
		this.$fileInput.trigger( 'click' );
	};

	/**
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.onFileInputChange = function () {
		const files = this.$fileInput[ 0 ].files;
		if ( files && files.length ) {
			this.uploadAttachments( Array.prototype.slice.call( files ) );
		}
		this.$fileInput.val( '' );
	};

	/**
	 * @private
	 * @param {DragEvent} e
	 */
	mw.flow.ui.EditorWidget.prototype.onEditorDragOver = function ( e ) {
		const types = e.dataTransfer && e.dataTransfer.types;
		if ( types && Array.prototype.indexOf.call( types, 'Files' ) !== -1 ) {
			e.preventDefault();
			e.stopPropagation();
			e.dataTransfer.dropEffect = 'copy';
		}
	};

	/**
	 * @private
	 * @param {DragEvent} e
	 */
	mw.flow.ui.EditorWidget.prototype.onEditorDrop = function ( e ) {
		const files = e.dataTransfer && e.dataTransfer.files;
		if ( files && files.length ) {
			e.preventDefault();
			e.stopPropagation();
			this.uploadAttachments( Array.prototype.slice.call( files ) );
		}
	};

	/**
	 * @private
	 * @param {ClipboardEvent} e
	 */
	mw.flow.ui.EditorWidget.prototype.onEditorPaste = function ( e ) {
		const files = e.clipboardData && e.clipboardData.files;
		if ( files && files.length ) {
			e.preventDefault();
			e.stopPropagation();
			this.uploadAttachments( Array.prototype.slice.call( files ) );
		}
	};

	/**
	 * Upload files one after the other, inserting a link into the content
	 * for each successful upload.
	 *
	 * @private
	 * @param {File[]} files
	 */
	mw.flow.ui.EditorWidget.prototype.uploadAttachments = function ( files ) {
		const widget = this;

		this.error.toggle( false );
		// Note: do NOT pushPending() here — pending disables the editor, and
		// a disabled VE surface is read-only, which would silently reject the
		// insertion of the uploaded attachment's link.
		this.editorControlsWidget.attachButton.setDisabled( true );

		files.reduce(
			( promise, file ) => promise.then( () => widget.uploadAttachment( file ).then(
				( attachment ) => {
					widget.insertAttachment( attachment );
				},
				( errorMsg ) => {
					widget.error.setLabel(
						errorMsg ?
							mw.msg( 'flow-attachment-upload-error', errorMsg ) :
							mw.msg( 'flow-attachment-upload-error-generic' )
					);
					widget.error.toggle( true );
				}
			) ),
			$.Deferred().resolve().promise()
		).always( () => {
			widget.editorControlsWidget.attachButton.setDisabled( false );
		} );
	};

	/**
	 * Upload a single file through the flow-attachment-upload API.
	 *
	 * @private
	 * @param {File} file
	 * @return {jQuery.Promise} Resolves with the attachment data returned by
	 *  the API ({id, name, url, isImage, ...}), rejects with an error message.
	 */
	mw.flow.ui.EditorWidget.prototype.uploadAttachment = function ( file ) {
		return new mw.Api().getToken( 'csrf' )
			.then( ( token ) => {
				const data = new FormData();
				data.append( 'action', 'flow-attachment-upload' );
				data.append( 'format', 'json' );
				data.append( 'formatversion', '2' );
				data.append( 'errorformat', 'plaintext' );
				data.append( 'page', mw.config.get( 'wgPageName' ) );
				data.append( 'name', file.name || 'file' );
				data.append( 'file', file );
				data.append( 'token', token );
				return $.ajax( {
					url: mw.util.wikiScript( 'api' ),
					method: 'POST',
					data: data,
					processData: false,
					contentType: false
				} );
			} )
			.then( ( response ) => {
				if ( !response || response.errors || !response[ 'flow-attachment-upload' ] ) {
					const error = response && response.errors && response.errors[ 0 ];
					return $.Deferred().reject( error && ( error.text || error.code ) ).promise();
				}
				return response[ 'flow-attachment-upload' ];
			}, () => $.Deferred().reject().promise() );
	};

	/**
	 * Insert a link to an uploaded attachment at the cursor. The link target
	 * is Special:FlowAttachment; the server upgrades it at render time to an
	 * inline image or an attachment card.
	 *
	 * @private
	 * @param {Object} attachment Attachment data from the API
	 */
	mw.flow.ui.EditorWidget.prototype.insertAttachment = function ( attachment ) {
		const wikitext = '[' + attachment.url + ' ' + attachment.name + ']';

		if ( !this.useVE ) {
			this.input.insertContent( ' ' + wikitext + ' ' );
			return;
		}

		const surface = this.target && this.target.getSurface();
		if ( !surface ) {
			return;
		}

		// A drop or paste can arrive while the editor was never focused, in
		// which case there is no selection to insert at: go to the end.
		if ( surface.getModel().getSelection().isNull() ) {
			surface.getView().selectLastSelectableContentOffset();
		}

		if ( surface.getMode() === 'source' ) {
			surface.getModel().getFragment()
				.insertContent( ' ' + wikitext + ' ' )
				.collapseToEnd()
				.select();
		} else if ( attachment.isImage ) {
			// Insert the actual image so it displays while editing. It is
			// stored as a plain <img> and upgraded at render time by the
			// server-side AttachmentFixer.
			surface.getModel().getFragment()
				.insertContent( [
					{
						type: 'flowAttachmentImage',
						attributes: {
							src: attachment.url,
							alt: attachment.name
						}
					},
					{ type: '/flowAttachmentImage' },
					' '
				] )
				.collapseToEnd()
				.select();
		} else {
			// Insert the file name as text pre-annotated with an external
			// link to the attachment, followed by a plain space
			const surfaceModel = surface.getModel();
			const annotation = ve.dm.annotationFactory.createFromElement( {
				type: 'link/mwExternal',
				attributes: { href: attachment.url }
			} );
			const hash = surfaceModel.getDocument().getStore().hash( annotation );
			const content = attachment.name.split( '' ).map( ( char ) => [ char, [ hash ] ] );
			content.push( ' ' );
			surfaceModel.getFragment()
				.insertContent( content )
				.collapseToEnd()
				.select();
		}
	};

	/**
	 * Get the content of the editor.
	 *
	 * @return {Object}
	 * @return {string} return.content Content of the editor
	 * @return {string} return.format 'html' or 'wikitext'
	 */
	mw.flow.ui.EditorWidget.prototype.getContent = function () {
		let dom, content, format;

		if ( !this.useVE ) {
			return {
				content: this.input.getValue(),
				format: 'wikitext'
			};
		}

		// If we haven't fully loaded yet, just return nothing.
		if ( !this.target || !this.target.getSurface() ) {
			return '';
		}

		dom = this.target.getSurface().getDom();
		if ( typeof dom === 'string' ) {
			content = dom;
			format = 'wikitext';
		} else {
			// Document content will include html, head & body nodes; get only content inside body node
			content = ve.properInnerHtml( dom.body );
			format = 'html';
		}
		return { content: content, format: format };
	};

	/**
	 * Check whether the editor is empty. Also returns true if the editor hasn't been loaded yet.
	 *
	 * @return {boolean} Editor is empty
	 */
	mw.flow.ui.EditorWidget.prototype.isEmpty = function () {
		if ( !this.useVE ) {
			return this.input.getValue().length === 0;
		}

		if ( !this.target || !this.target.getSurface() ) {
			return true;
		}
		return !this.target.getSurface().getModel().getDocument().data.hasContent();
	};

	/**
	 * Check if there are any changes made to the data in the editor.
	 *
	 * @return {boolean} The original content has changed
	 */
	mw.flow.ui.EditorWidget.prototype.hasBeenChanged = function () {
		if ( !this.useVE ) {
			return this.input.getValue() !== this.originalContent;
		}

		return this.target && this.target.getSurface() &&
			this.target.getSurface().getModel().hasBeenModified();
	};

	/**
	 * Get the format the user prefers.
	 *
	 * @return {string} 'html' or 'wikitext'
	 */
	mw.flow.ui.EditorWidget.prototype.getPreferredFormat = function () {
		const vePref = mw.user.options.get( 'visualeditor-tabs' );
		// If VE isn't available, we don't have much of a choice
		if ( !this.useVE ) {
			return 'wikitext';
		}
		// If the user has their editor preference set to "always VE" or "always source", respect that
		if ( vePref === 'prefer-wt' ) {
			return 'wikitext';
		}
		if ( vePref === 'prefer-ve' ) {
			return 'html';
		}
		// Otherwise, use the last-used editor
		return mw.user.options.get( 'flow-editor' ) === 'visualeditor' ? 'html' : 'wikitext';
	};

	/**
	 * Make this widget pending while switching editor modes, and refocus the editor when
	 * the switch is complete.
	 *
	 * @private
	 * @param {jQuery.Promise} promise Promise resolved/rejected when switch is completed/aborted
	 * @param {string} newMode 'visual' or 'source'
	 * @fires switch
	 */
	mw.flow.ui.EditorWidget.prototype.onTargetSwitchMode = function ( promise, newMode ) {
		const widget = this;
		this.pushPending();
		this.error.toggle( false );
		promise
			.done( () => {
				widget.maybeAutoFocus();
				widget.wikitextHelpLabel.toggle( newMode === 'source' );
			} )
			.fail( ( error ) => {
				widget.error.setLabel( $( '<span>' ).text( error || mw.msg( 'flow-error-default' ) ) );
				widget.error.toggle( true );
			} )
			.always( () => {
				widget.popPending();
			} );
	};

	/**
	 * Handle submit events from the editor
	 */
	mw.flow.ui.EditorWidget.prototype.onTargetSubmit = function () {
		if ( !this.editorControlsWidget.saveButton.isDisabled() ) {
			this.onEditorControlsWidgetSave();
		}
	};

	/**
	 * Relay the save event, adding the content.
	 *
	 * @private
	 * @fires saveContent
	 */
	mw.flow.ui.EditorWidget.prototype.onEditorControlsWidgetSave = function () {
		const content = this.getContent();
		this.unbindBeforeUnloadHandler();
		this.emit(
			'saveContent',
			content.content,
			content.format
		);
	};

	/**
	 * Bind the beforeunload handler, if needed and if not already bound.
	 *
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.bindBeforeUnloadHandler = function () {
		if ( !this.beforeUnloadHandler && ( this.confirmLeave || this.leaveCallback ) ) {
			this.beforeUnloadHandler = this.onBeforeUnload.bind( this );
			$( window ).on( 'beforeunload', this.beforeUnloadHandler );
		}
	};

	/**
	 * Unbind the beforeunload handler if it is bound.
	 *
	 * @private
	 */
	mw.flow.ui.EditorWidget.prototype.unbindBeforeUnloadHandler = function () {
		if ( this.beforeUnloadHandler ) {
			$( window ).off( 'beforeunload', this.beforeUnloadHandler );
			this.beforeUnloadHandler = null;
		}
	};

	/**
	 * Respond to beforeunload event.
	 *
	 * @private
	 * @return {string|undefined}
	 */
	mw.flow.ui.EditorWidget.prototype.onBeforeUnload = function () {
		if ( this.leaveCallback && this.leaveCallback() === false ) {
			return mw.msg( 'flow-cancel-warning' );
		}
		if ( this.confirmLeave && !this.isEmpty() ) {
			return mw.msg( 'flow-cancel-warning' );
		}
	};

	mw.flow.ui.EditorWidget.prototype.isDisabled = function () {
		// Auto-disable when pending or not saveable
		return this.isPending() ||
			!this.isSaveable() ||
			// Parent method
			mw.flow.ui.EditorWidget.super.prototype.isDisabled.apply( this, arguments );
	};

	mw.flow.ui.EditorWidget.prototype.setDisabled = function () {
		// Parent method
		mw.flow.ui.EditorWidget.super.prototype.setDisabled.apply( this, arguments );

		if ( this.editorControlsWidget ) {
			this.editorControlsWidget.setDisabled( this.isDisabled() );
		}

		if ( this.target ) {
			this.target.setDisabled( this.isDisabled() );
		}
	};

	mw.flow.ui.EditorWidget.prototype.pushPending = function () {
		// Parent method
		OO.ui.mixin.PendingElement.prototype.pushPending.apply( this, arguments );

		// Disabled state depends on pending state
		this.updateDisabled();
	};

	mw.flow.ui.EditorWidget.prototype.popPending = function () {
		// Parent method
		OO.ui.mixin.PendingElement.prototype.popPending.apply( this, arguments );

		// Disabled state depends on pending state
		this.updateDisabled();
	};

	/**
	 * Focus the editor
	 */
	mw.flow.ui.EditorWidget.prototype.focus = function () {
		if ( !this.useVE ) {
			this.input.focus();
			return;
		}

		if ( this.target && this.target.getSurface() ) {
			this.target.getSurface().getView().focus();
		}
	};

	/**
	 * Move the cursor to the end of the editor.
	 */
	mw.flow.ui.EditorWidget.prototype.moveCursorToEnd = function () {
		if ( !this.useVE ) {
			this.input.moveCursorToEnd();
			return;
		}

		if ( this.target && this.target.getSurface() ) {
			this.target.getSurface().getView().selectLastSelectableContentOffset();
		}
	};

	/**
	 * Remove all content from the editor.
	 *
	 */
	mw.flow.ui.EditorWidget.prototype.clearContent = function () {
		if ( !this.useVE ) {
			this.input.setValue( '' );
			return;
		}

		if ( this.target ) {
			this.target.clearDocState();
			this.target.clearSurfaces();
		}
	};

	/**
	 * Destroy the widget.
	 *
	 * @return {jQuery.Promise} Promise which resolves when the widget is destroyed
	 */
	mw.flow.ui.EditorWidget.prototype.destroy = function () {
		if ( this.target ) {
			// clearDocState is called by #destroy
			this.target.destroy();
			// TODO: We should be able to just return target.destroy()
			return this.target.teardownPromise;
		}
		return $.Deferred().resolve().promise();
	};
}() );
