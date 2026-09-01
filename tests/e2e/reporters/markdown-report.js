/**
 * A Playwright reporter that writes one human-readable Markdown summary of the
 * whole run — a per-spec pass/skip/fail table, every skip's stated reason, and
 * for each failure the spec's own purpose (its header docblock) alongside the
 * error — so a finished run can be understood without opening the trace.
 *
 * Appended to `playwright.config.js`'s reporter list, never replacing it. The
 * file lands outside the repo (see `REPORT_DIR`): it is a local artefact of a
 * run against a live Laposta account, not something to track or ship.
 *
 * Every filesystem touch is wrapped: a reporter that throws would fail an
 * otherwise-green run, which is the opposite of the point.
 */

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

/**
 * Where the reports are written. `~/Documents/wynko-reports/e2e/` holds the
 * always-overwritten `last-run.md` plus one timestamped copy per run.
 *
 * @type {string}
 */
const REPORT_DIR = path.join(
	os.homedir(),
	'Documents',
	'wynko-reports',
	'e2e'
);

/**
 * Turns a millisecond duration into a short `1m 4s` / `820ms` string.
 *
 * @param {number} ms Duration in milliseconds.
 * @return {string} Human-readable duration.
 */
function humanDuration( ms ) {
	if ( ms < 1000 ) {
		return `${ Math.round( ms ) }ms`;
	}
	const seconds = ms / 1000;
	if ( seconds < 60 ) {
		return `${ seconds.toFixed( 1 ) }s`;
	}
	const minutes = Math.floor( seconds / 60 );
	return `${ minutes }m ${ Math.round( seconds - minutes * 60 ) }s`;
}

/**
 * Reads the first block comment of a spec file and flattens it to one line,
 * so the report can say what a failing spec was actually checking.
 *
 * @param {string} file Absolute path to the spec file.
 * @return {string} The docblock prose, or '' when it cannot be read.
 */
function specPurpose( file ) {
	try {
		const source = fs.readFileSync( file, 'utf8' );
		const match = source.match( /\/\*\*([\s\S]*?)\*\// );
		if ( ! match ) {
			return '';
		}
		return match[ 1 ]
			.split( '\n' )
			.map( ( line ) => line.replace( /^\s*\*?/, '' ).trim() )
			.filter( Boolean )
			.join( ' ' )
			.trim();
	} catch ( error ) {
		return '';
	}
}

/**
 * Collapses a Playwright error to its first meaningful line plus the first
 * stack frame that points back into this repo.
 *
 * @param {import('@playwright/test/reporter').TestError} error The error.
 * @return {string} A one- or two-line summary.
 */
function summariseError( error ) {
	if ( ! error ) {
		return 'Unknown error.';
	}
	const message = ( error.message || String( error ) )
		// eslint-disable-next-line no-control-regex
		.replace( /\[[0-9;]*m/g, '' )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.slice( 0, 4 )
		.join( ' ' );
	const frame = ( error.stack || '' )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.find(
			( line ) =>
				line.startsWith( 'at ' ) && line.includes( '/tests/e2e/' )
		);
	return frame ? `${ message }\n  ${ frame }` : message;
}

/**
 * The reporter itself. Playwright constructs one instance per run and calls
 * `onBegin` once, `onTestEnd` per test result, and `onEnd` once at the close.
 */
class MarkdownReport {
	/**
	 * Sets up the per-run accumulators.
	 */
	constructor() {
		/** @type {Array<{file:string,title:string,status:string,duration:number,retries:number,skipReason:string,error:string}>} */
		this.rows = [];
		this.startedAt = new Date();
	}

	/**
	 * Records the config for the header.
	 *
	 * @param {import('@playwright/test').FullConfig} config The resolved config.
	 * @return {void}
	 */
	onBegin( config ) {
		this.config = config;
	}

	/**
	 * Captures one test's outcome.
	 *
	 * @param {import('@playwright/test/reporter').TestCase}   test   The test.
	 * @param {import('@playwright/test/reporter').TestResult} result Its result.
	 * @return {void}
	 */
	onTestEnd( test, result ) {
		const skipAnnotations = test.annotations
			.filter(
				( annotation ) =>
					annotation.type === 'skip' || annotation.type === 'fixme'
			)
			.map( ( annotation ) => annotation.description )
			.filter( Boolean );

		this.rows.push( {
			file: path.relative(
				path.join( __dirname, '..' ),
				test.location.file
			),
			title: test.title,
			status: result.status,
			duration: result.duration,
			retries: result.retry,
			skipReason: skipAnnotations.join( '; ' ),
			error:
				result.status === 'failed' || result.status === 'timedOut'
					? summariseError( result.error )
					: '',
			specFile: test.location.file,
		} );
	}

	/**
	 * Writes the report. Playwright ignores the return value of a custom
	 * reporter's `onEnd`, so a failure here is swallowed on purpose.
	 *
	 * @param {import('@playwright/test/reporter').FullResult} result The run result.
	 * @return {void}
	 */
	onEnd( result ) {
		try {
			const markdown = this.render( result );
			fs.mkdirSync( REPORT_DIR, { recursive: true } );

			const stamp = this.startedAt
				.toISOString()
				.replace( /[:T]/g, '-' )
				.replace( /\..*$/, '' );

			fs.writeFileSync(
				path.join( REPORT_DIR, 'last-run.md' ),
				markdown
			);
			fs.writeFileSync(
				path.join( REPORT_DIR, `${ stamp }.md` ),
				markdown
			);

			// eslint-disable-next-line no-console
			console.log(
				`\nMarkdown report: ${ path.join( REPORT_DIR, 'last-run.md' ) }`
			);
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error(
				`markdown-report: could not write report — ${ error.message }`
			);
		}
	}

	/**
	 * Builds the Markdown document.
	 *
	 * @param {import('@playwright/test/reporter').FullResult} result The run result.
	 * @return {string} The full document.
	 */
	render( result ) {
		// A retried test contributes several rows; the last one is its verdict.
		const finalByKey = new Map();
		for ( const row of this.rows ) {
			finalByKey.set( `${ row.file } :: ${ row.title }`, row );
		}
		const finals = [ ...finalByKey.values() ];

		const counts = { passed: 0, failed: 0, skipped: 0, flaky: 0 };
		for ( const row of finals ) {
			if ( row.status === 'passed' ) {
				counts[ row.retries > 0 ? 'flaky' : 'passed' ]++;
			} else if ( row.status === 'skipped' ) {
				counts.skipped++;
			} else {
				counts.failed++;
			}
		}

		const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8889';
		const lines = [];

		lines.push( '# Wynko e2e — run report' );
		lines.push( '' );
		lines.push( `- **Started:** ${ this.startedAt.toISOString() }` );
		lines.push(
			`- **Duration:** ${ humanDuration(
				Date.now() - this.startedAt.getTime()
			) }`
		);
		lines.push( `- **Target:** ${ baseUrl }` );
		lines.push( `- **Overall:** ${ result.status }` );
		lines.push(
			`- **Totals:** ${ counts.passed } passed · ${ counts.failed } failed · ${ counts.skipped } skipped · ${ counts.flaky } flaky (${ finals.length } tests)`
		);
		lines.push( '' );

		lines.push( '## Results' );
		lines.push( '' );
		lines.push( '| Spec | Test | Result | Time |' );
		lines.push( '| --- | --- | --- | --- |' );
		const symbol = {
			passed: '✅ pass',
			failed: '❌ fail',
			timedOut: '❌ timeout',
			skipped: '⏭️ skip',
			interrupted: '⚠️ interrupted',
		};
		for ( const row of finals ) {
			const label =
				row.status === 'passed' && row.retries > 0
					? '⚠️ flaky'
					: symbol[ row.status ] || row.status;
			lines.push(
				`| \`${ row.file }\` | ${
					row.title
				} | ${ label } | ${ humanDuration( row.duration ) } |`
			);
		}
		lines.push( '' );

		const skips = finals.filter( ( row ) => row.status === 'skipped' );
		if ( skips.length ) {
			lines.push( '## Skips' );
			lines.push( '' );
			for ( const row of skips ) {
				lines.push(
					`- **${ row.title }** (\`${ row.file }\`) — ${
						row.skipReason || 'no reason recorded'
					}`
				);
			}
			lines.push( '' );
		}

		const failures = finals.filter(
			( row ) => row.status === 'failed' || row.status === 'timedOut'
		);
		if ( failures.length ) {
			lines.push( '## Failures' );
			lines.push( '' );
			for ( const row of failures ) {
				const purpose = specPurpose( row.specFile );
				lines.push( `### ${ row.title }` );
				lines.push( '' );
				lines.push( `- **Spec:** \`${ row.file }\`` );
				if ( purpose ) {
					lines.push( `- **What it checks:** ${ purpose }` );
				}
				if ( row.retries > 0 ) {
					lines.push( `- **Retries:** ${ row.retries }` );
				}
				lines.push( '' );
				lines.push( '```' );
				lines.push( row.error );
				lines.push( '```' );
				lines.push( '' );
			}
		}

		return lines.join( '\n' ) + '\n';
	}
}

module.exports = MarkdownReport;
