'use strict';

const { action, assert, utils } = require( 'api-testing' );

describe( 'Flow conversion utilities API', () => {

	let alice;
	let title;

	before( async () => {
		alice = await action.alice();
		title = utils.title( 'Flow' );
	} );

	it( 'will convert from wikitext to HTML', async () => {
		const input = '== Foobar ==';
		const result = await alice.action(
			'flow-parsoid-utils',
			{ title, from: 'wikitext', to: 'html', content: input }
		);

		assert.nestedProperty( result, 'flow-parsoid-utils' );
		const output = result[ 'flow-parsoid-utils' ];

		assert.equal( output.format, 'html' );
		assert.match( output.content, /<h2.*Foobar.*<\/h2>/s );

		// Check that the output was generated by parsoid with the correct parameters.
		// These assertions will fail when Flow is falling back to the legacy wikitext parser.
		assert.match( output.content, /<\/body>$/ );
		assert.match( output.content, /<\/section>/ );
		assert.match( output.content, / id="Foobar"/ );
		assert.match( output.content, / id="mw/ );
		assert.match( output.content, / data-mw-section-id=/ );
	} );
} );
