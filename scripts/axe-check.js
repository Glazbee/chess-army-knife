/**
 * Checks the HTML files in a folder with axe-core, in jsdom, and fails if any rule is broken.
 *
 * The files are the plugin's block markup, written out by tests/integration/A11yFixturesTest.php (set
 * CAK_A11Y_DIR to the folder). jsdom has no layout engine, so rules that need to see the page drawn
 * are left out: colour contrast (the plugin's colours have their own unit tests, and text takes the
 * theme's colour) and target size. Everything else, which is names, roles, labels, headings, ARIA,
 * tables and lists, is checked at WCAG 2 A, AA and AAA and 2.1 and 2.2 AA, plus axe's best practices.
 *
 * Usage: node scripts/axe-check.js <folder>
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM } = require( 'jsdom' );
const axeSource = require( 'axe-core' ).source;

const folder = process.argv[ 2 ];
if ( ! folder || ! fs.existsSync( folder ) ) {
	console.error( 'Give the folder of HTML files to check.' );
	process.exit( 2 );
}

const files = fs
	.readdirSync( folder )
	.filter( ( name ) => name.endsWith( '.html' ) )
	.sort();
if ( ! files.length ) {
	console.error( 'No HTML files were found in ' + folder );
	process.exit( 2 );
}

const SKIPPED_RULES = [
	'color-contrast',
	'color-contrast-enhanced',
	'target-size',
	'scrollable-region-focusable',
];

async function check( file ) {
	const html = fs.readFileSync( path.join( folder, file ), 'utf8' );
	const dom = new JSDOM( html, {
		runScripts: 'outside-only',
		pretendToBeVisual: true,
	} );
	dom.window.eval( axeSource );

	const rules = {};
	SKIPPED_RULES.forEach( ( id ) => {
		rules[ id ] = { enabled: false };
	} );

	const results = await dom.window.axe.run( dom.window.document, {
		runOnly: {
			type: 'tag',
			values: [
				'wcag2a',
				'wcag2aa',
				'wcag2aaa',
				'wcag21a',
				'wcag21aa',
				'wcag22aa',
				'best-practice',
			],
		},
		rules,
	} );
	dom.window.close();
	return results.violations;
}

( async () => {
	let failed = 0;
	for ( const file of files ) {
		const violations = await check( file );
		if ( ! violations.length ) {
			console.log( 'ok    ' + file );
			continue;
		}
		failed += violations.length;
		console.log( 'FAIL  ' + file );
		violations.forEach( ( violation ) => {
			console.log(
				'      ' +
					violation.id +
					' (' +
					violation.impact +
					'): ' +
					violation.help
			);
			violation.nodes.slice( 0, 3 ).forEach( ( node ) => {
				console.log( '        ' + node.html.slice( 0, 160 ) );
			} );
		} );
	}

	console.log(
		failed
			? '\n' + failed + ' accessibility problem(s) found.'
			: '\nNo accessibility problems found in ' + files.length + ' files.'
	);
	process.exit( failed ? 1 : 0 );
} )();
