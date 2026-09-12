// WP_ADMIN_USER=... WP_ADMIN_PASS=... node bin/screenshot-admin.js
// Required: WP_ADMIN_USER, WP_ADMIN_PASS. Optional: WP_BASE_URL (default https://experiment.test).
// Optional: SCREEN=slug,slug to capture a subset (e.g. SCREEN=safety-center,approvals-risk-gate).

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

const USAGE =
	'Usage: WP_ADMIN_USER=... WP_ADMIN_PASS=... node bin/screenshot-admin.js\n' +
	'Required env vars: WP_ADMIN_USER, WP_ADMIN_PASS. Optional: WP_BASE_URL, SCREEN=slug,slug.';

const BASE_URL = ( process.env.WP_BASE_URL || 'https://experiment.test' ).replace( /\/+$/, '' );
const USER = process.env.WP_ADMIN_USER || '';
const PASS = process.env.WP_ADMIN_PASS || '';
const VIEWPORT_WIDTH = 1440;
const VIEWPORT_HEIGHT = 900;
const OUT_DIR = path.join( __dirname, '..', 'screenshots', 'baseline' );

// Pages registered in includes/class-admin-menu-handler.php (top-level, sub-menu,
// and hidden pages). Usage & Costs is Pro-only; we still visit it if present.
const SCREENS = [
	{ slug: 'dashboard', page: 'agent-builder' },
	{ slug: 'chat', page: 'agentic-chat' },
	{ slug: 'agents', page: 'agentic-agents' },
	{ slug: 'publish', page: 'agentic-deployment' },
	{ slug: 'run-task', page: 'agentic-run-task' },
	{ slug: 'agent-wizard', page: 'agentic-agent-wizard' },
	{ slug: 'knowledge-wizard', page: 'agentic-knowledge-wizard' },
	{ slug: 'deploy-wizard', page: 'agentic-deploy-wizard' },
	{ slug: 'knowledge', page: 'agentic-train-data' },
	{ slug: 'tools', page: 'agentic-tools' },
	{ slug: 'skills', page: 'agentic-skills' },
	{ slug: 'approvals', page: 'agentic-approvals' },
	{
		slug: 'approvals-risk-gate',
		page: 'agentic-approvals',
		clip: '.agentic-react-approvals-prefs',
		wait: '.agentic-react-approvals-prefs',
	},
	{
		slug: 'safety-center',
		page: 'agentic-safety-center',
		modes: [ 'basic', 'advanced' ],
		wait: '.agentic-safety-overview',
	},
	{ slug: 'passport', page: 'agentic-agent-ready' },
	{ slug: 'logs', page: 'agentic-audit-log' },
	{ slug: 'settings', page: 'agentic-settings' },
	{ slug: 'settings-providers', page: 'agentic-settings', query: 'tab=providers' },
	{ slug: 'setup', page: 'agentic-setup' },
	{ slug: 'signup', page: 'agentic-signup' },
	{ slug: 'usage-costs', page: 'agentic-costs' },
];

const REACT_ROOTS = [
	'#agentic-dashboard-app-root',
	'#agentic-admin-pages-root',
	'#agentic-settings-app-root',
	'#agentic-agent-wizard-root',
	'#agentic-knowledge-wizard-root',
	'#agentic-deploy-wizard-root',
];

function adminUrl( screen ) {
	let url = `${ BASE_URL }/wp-admin/admin.php?page=${ encodeURIComponent( screen.page ) }`;
	if ( screen.query ) {
		url += `&${ screen.query }`;
	}
	return url;
}

async function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

async function waitForScreen( page ) {
	await page.waitForSelector( '#wpbody-content, #error-page, .login', { timeout: 30000 } );

	for ( const sel of REACT_ROOTS ) {
		const handle = await page.$( sel );
		if ( ! handle ) {
			continue;
		}
		try {
			await page.waitForFunction(
				( s ) => {
					const n = document.querySelector( s );
					return n && n.childElementCount > 0;
				},
				sel,
				{ timeout: 20000 }
			);
		} catch ( _err ) {
			// Screen may still be useful even if the React island is empty.
		}
		break;
	}

	try {
		await page.waitForFunction(
			() => document.querySelectorAll( '.components-spinner' ).length === 0,
			{ timeout: 8000 }
		);
	} catch ( _err ) {
		// Spinner may remain on live-updating views; still screenshot.
	}

	await sleep( 600 );
}

async function login( page ) {
	await page.goto( `${ BASE_URL }/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 45000 } );
	await page.waitForSelector( '#user_login', { timeout: 15000 } );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	await page.waitForSelector( '#login_error, #wpadminbar', { timeout: 30000 } );

	const loginError = await page.$( '#login_error' );
	if ( loginError || page.url().includes( 'wp-login.php' ) ) {
		const msg = loginError ? ( await loginError.innerText() ).trim() : 'still on wp-login.php';
		throw new Error( `WordPress login failed (${ msg }). Check WP_ADMIN_USER / WP_ADMIN_PASS.` );
	}
}

function destPath( slug, mode ) {
	const name = ! mode || mode === 'basic' ? `${ slug }.png` : `${ slug }-${ mode }.png`;
	return path.join( OUT_DIR, name );
}

async function capture( page, screen, dest ) {
	if ( screen.clip ) {
		const loc = page.locator( screen.clip ).first();
		await loc.waitFor( { timeout: 20000 } );
		await loc.screenshot( { path: dest } );
		return;
	}
	await page.screenshot( { path: dest, fullPage: true } );
}

async function setScreenMode( page, mode ) {
	const label = mode === 'advanced' ? 'Advanced' : 'Basic';
	// Scope to the in-page toggle. A site-wide switch (#agentic-global-mode-switch)
	// reuses the same button classes and would steal the click.
	const toggle = page.locator(
		'.agentic-react-admin__actions .agentic-screen-mode-toggle'
	);
	if ( ( await toggle.count() ) === 0 ) {
		return false;
	}
	const btn = toggle.locator( 'button', {
		hasText: new RegExp( `^${ label }$` ),
	} );
	const already = await btn.evaluate( ( el ) =>
		el.classList.contains( 'button-primary' )
	);
	if ( already ) {
		return true;
	}
	await btn.click();
	await page.waitForFunction(
		( wanted ) => {
			const buttons = Array.from(
				document.querySelectorAll(
					'.agentic-react-admin__actions .agentic-screen-mode-toggle button'
				)
			);
			const match = buttons.find(
				( b ) => ( b.textContent || '' ).trim() === wanted
			);
			return match && match.classList.contains( 'button-primary' );
		},
		label,
		{ timeout: 15000 }
	);
	await waitForScreen( page );
	if ( mode === 'advanced' ) {
		await page
			.getByText( "Advanced view expands every agent's tool list" )
			.waitFor( { timeout: 15000 } );
	} else {
		await page.waitForFunction(
			() =>
				! document.body.innerText.includes(
					"Advanced view expands every agent's tool list"
				),
			null,
			{ timeout: 15000 }
		);
	}
	return true;
}

async function screenshotScreen( page, screen ) {
	const modes = Array.isArray( screen.modes ) && screen.modes.length
		? screen.modes
		: [ null ];
	try {
		await page.goto( adminUrl( screen ), {
			waitUntil: 'domcontentloaded',
			timeout: 45000,
		} );
		await waitForScreen( page );
		if ( screen.wait ) {
			await page.waitForSelector( screen.wait, { timeout: 20000 } );
		}
		for ( const mode of modes ) {
			const dest = destPath( screen.slug, mode );
			if ( mode ) {
				const switched = await setScreenMode( page, mode );
				if ( ! switched ) {
					console.log(
						`warn  ${ screen.slug }  (no ${ mode } toggle) — capturing current view`
					);
				}
			}
			await capture( page, screen, dest );
			console.log(
				`ok  ${ path.basename( dest, '.png' ) }  →  ${ path.relative( process.cwd(), dest ) }`
			);
		}
		if ( modes.includes( 'basic' ) ) {
			await setScreenMode( page, 'basic' );
		}
	} catch ( err ) {
		// Don't overwrite a mode that already saved successfully.
		const dest = destPath( screen.slug, modes[ modes.length - 1 ] );
		try {
			await page.screenshot( { path: dest, fullPage: true } );
			console.log(
				`warn  ${ screen.slug }  (${ err.message }) — saved full page as ${ path.basename( dest ) }`
			);
		} catch ( shotErr ) {
			console.log(
				`skip  ${ screen.slug }  (${ err.message }; screenshot: ${ shotErr.message })`
			);
		}
	}
}

async function main() {
	if ( ! USER || ! PASS ) {
		console.error( USAGE );
		process.exit( 1 );
	}

	fs.mkdirSync( OUT_DIR, { recursive: true } );

	// Bundled Chromium is not published for every distro (e.g. Ubuntu 26.04).
	// Prefer system Google Chrome, then PLAYWRIGHT_CHROME_PATH, then bundled Chromium.
	const launchOptions = { headless: true };
	if ( process.env.PLAYWRIGHT_CHROME_PATH ) {
		launchOptions.executablePath = process.env.PLAYWRIGHT_CHROME_PATH;
	} else {
		launchOptions.channel = 'chrome';
	}
	let browser;
	try {
		browser = await chromium.launch( launchOptions );
	} catch ( launchErr ) {
		browser = await chromium.launch( { headless: true } );
	}
	const context = await browser.newContext( {
		ignoreHTTPSErrors: true,
		viewport: { width: VIEWPORT_WIDTH, height: VIEWPORT_HEIGHT },
	} );
	const page = await context.newPage();

	const only = ( process.env.SCREEN || '' )
		.split( ',' )
		.map( ( s ) => s.trim() )
		.filter( Boolean );
	const screens = only.length
		? SCREENS.filter( ( s ) => only.includes( s.slug ) )
		: SCREENS;
	if ( only.length && screens.length !== only.length ) {
		const known = new Set( SCREENS.map( ( s ) => s.slug ) );
		const missing = only.filter( ( s ) => ! known.has( s ) );
		throw new Error( `Unknown SCREEN slug(s): ${ missing.join( ', ' ) }` );
	}

	try {
		await login( page );
		for ( const screen of screens ) {
			await screenshotScreen( page, screen );
		}
	} finally {
		await browser.close();
	}
}

main().catch( ( err ) => {
	console.error( err.message || err );
	process.exit( 1 );
} );
