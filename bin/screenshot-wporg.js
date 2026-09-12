// WP_ADMIN_USER=... WP_ADMIN_PASS=... node bin/screenshot-wporg.js
// Recapture .wordpress-org/screenshot-N.png from the live experiment.test admin
// in readme.txt Screenshots order. Viewport 1440×900; most shots are full-page,
// Safety Center and Tools (Advanced) are viewport-height so they stay reviewable.

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

const USAGE =
	'Usage: WP_ADMIN_USER=... WP_ADMIN_PASS=... node bin/screenshot-wporg.js\n' +
	'Required env vars: WP_ADMIN_USER, WP_ADMIN_PASS. Optional: WP_BASE_URL, SCREEN=N,N.';

const BASE_URL = ( process.env.WP_BASE_URL || 'https://experiment.test' ).replace( /\/+$/, '' );
const USER = process.env.WP_ADMIN_USER || '';
const PASS = process.env.WP_ADMIN_PASS || '';
const VIEWPORT_WIDTH = 1440;
const VIEWPORT_HEIGHT = 900;
const OUT_DIR = path.join( __dirname, '..', '.wordpress-org' );
const APPLE_PAY_NOTICE = '.stripe-apple-pay-message';

const REACT_ROOTS = [
	'#agentic-dashboard-app-root',
	'#agentic-admin-pages-root',
	'#agentic-settings-app-root',
	'#agentic-agent-wizard-root',
	'#agentic-knowledge-wizard-root',
	'#agentic-deploy-wizard-root',
];

// readme.txt == Screenshots == order.
const SHOTS = [
	{
		n: 1,
		page: 'agent-builder',
		mode: 'advanced',
		fullPage: true,
	},
	{
		n: 2,
		page: 'agentic-chat',
		fullPage: true,
		chat: true,
	},
	{
		n: 3,
		page: 'agentic-agents',
		mode: 'advanced',
		fullPage: true,
	},
	{
		n: 4,
		page: 'agentic-approvals',
		mode: 'advanced',
		fullPage: true,
	},
	{
		n: 5,
		page: 'agentic-tools',
		mode: 'advanced',
		fullPage: false,
		wait: '.agentic-react-table',
	},
	{
		n: 6,
		page: 'agentic-approvals',
		clip: '.agentic-react-approvals-prefs',
		wait: '.agentic-react-approvals-prefs',
	},
	{
		n: 7,
		page: 'agentic-agent-ready',
		mode: 'advanced',
		fullPage: true,
	},
	{
		n: 8,
		page: 'agentic-audit-log',
		mode: 'advanced',
		fullPage: false,
	},
	{
		n: 9,
		page: 'agentic-safety-center',
		mode: 'basic',
		fullPage: false,
		wait: '.agentic-safety-overview',
	},
	{
		n: 10,
		page: 'agentic-signup',
		fullPage: true,
		wait: '#agentic-signup-ui-mode',
	},
	{
		n: 11,
		page: 'agentic-settings',
		query: 'tab=providers',
		fullPage: true,
	},
];

function adminUrl( shot ) {
	let url = `${ BASE_URL }/wp-admin/admin.php?page=${ encodeURIComponent( shot.page ) }`;
	if ( shot.query ) {
		url += `&${ shot.query }`;
	}
	return url;
}

function destPath( n ) {
	return path.join( OUT_DIR, `screenshot-${ n }.png` );
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

async function hideApplePayNotice( page ) {
	const visible = await page.locator( APPLE_PAY_NOTICE ).count();
	if ( ! visible ) {
		return false;
	}
	await page.addStyleTag( {
		content: `${ APPLE_PAY_NOTICE } { display: none !important; }`,
	} );
	return true;
}

async function setScreenMode( page, mode ) {
	const label = mode === 'advanced' ? 'Advanced' : 'Basic';
	const toggle = page.locator(
		'.agentic-react-admin__actions .agentic-screen-mode-toggle'
	);
	if ( ( await toggle.count() ) === 0 ) {
		return false;
	}
	const btn = toggle.locator( 'button', {
		hasText: new RegExp( `^${ label }$` ),
	} );
	if ( ( await btn.count() ) === 0 ) {
		return false;
	}
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
	return true;
}

async function sendChatAndWait( page ) {
	const input = page.locator( '.agentic-chat-input' ).first();
	await input.waitFor( { timeout: 20000 } );

	const agentSelect = page.locator( '#agentic-agent-select' );
	if ( ( await agentSelect.count() ) > 0 ) {
		const current = await agentSelect.inputValue();
		if ( current !== 'wordpress-assistant' ) {
			await agentSelect.selectOption( 'wordpress-assistant' );
			await sleep( 800 );
		}
	}

	const newChat = page.locator( '.agentic-new-chat-btn' );
	if ( ( await newChat.count() ) > 0 ) {
		await newChat.click();
		await sleep( 400 );
	}

	const prompt =
		'Using your site overview tool, what is this WordPress site called? Answer in one short sentence.';
	await input.fill( prompt );
	await page.locator( '.agentic-send-btn' ).click();

	try {
		await page.waitForSelector(
			'.agentic-reasoning-card, .agentic-tools-used, .agentic-tool-tag, .agentic-message-error',
			{ timeout: 90000 }
		);
	} catch ( _err ) {
		await page.waitForSelector( '.agentic-message-agent, .agentic-message.agent', {
			timeout: 15000,
		} ).catch( () => {} );
	}

	const errored = ( await page.locator( '.agentic-message-error' ).count() ) > 0;
	if ( errored ) {
		console.log( 'warn  chat turn returned an error — reloading welcome state' );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await waitForScreen( page );
		await page.locator( '.agentic-chat-input' ).first().waitFor( { timeout: 20000 } );
		await sleep( 600 );
		return false;
	}

	const card = page.locator( '.agentic-reasoning-card' ).first();
	if ( ( await card.count() ) > 0 ) {
		await page.evaluate( () => {
			const messages = document.querySelector( '#agentic-messages' );
			const details = document.querySelector( '.agentic-reasoning-card' );
			if ( details ) {
				details.open = true;
			}
			if ( messages && details ) {
				const parent = details.closest( '.agentic-message' ) || details;
				messages.scrollTop = Math.max( 0, parent.offsetTop - messages.offsetTop - 16 );
			}
		} );
		await page.getByText( /Tools used/i ).waitFor( { timeout: 5000 } ).catch( () => {} );
	}

	await sleep( 800 );
	const toolsVisible = ( await page.locator(
		'.agentic-reasoning-card, .agentic-tools-used, .agentic-tool-tag'
	).count() ) > 0;
	return toolsVisible;
}

async function captureShot( page, shot ) {
	const dest = destPath( shot.n );
	await page.goto( adminUrl( shot ), {
		waitUntil: 'domcontentloaded',
		timeout: 45000,
	} );
	await waitForScreen( page );
	if ( shot.wait ) {
		await page.waitForSelector( shot.wait, { timeout: 20000 } );
	}
	if ( shot.mode ) {
		const switched = await setScreenMode( page, shot.mode );
		if ( ! switched ) {
			console.log(
				`warn  screenshot-${ shot.n }  (no ${ shot.mode } toggle) — capturing current view`
			);
		}
		if ( shot.wait ) {
			await page.waitForSelector( shot.wait, { timeout: 20000 } ).catch( () => {} );
		}
	}

	const noticeWasVisible = await hideApplePayNotice( page );
	if ( noticeWasVisible ) {
		console.log( `warn  screenshot-${ shot.n }  Apple Pay notice still in DOM — hid via CSS` );
	}

	let chatHadTools = null;
	if ( shot.chat ) {
		chatHadTools = await sendChatAndWait( page );
		console.log(
			chatHadTools
				? `ok    screenshot-${ shot.n }  chat captured with tool traces`
				: `warn  screenshot-${ shot.n }  chat captured but no tool-trace UI appeared`
		);
	}

	if ( shot.clip ) {
		const loc = page.locator( shot.clip ).first();
		await loc.waitFor( { timeout: 20000 } );
		await loc.screenshot( { path: dest } );
	} else {
		await page.screenshot( { path: dest, fullPage: !! shot.fullPage } );
	}

	console.log(
		`ok    screenshot-${ shot.n }.png  →  ${ path.relative( process.cwd(), dest ) }`
	);
	return { noticeWasVisible, chatHadTools };
}

async function main() {
	if ( ! USER || ! PASS ) {
		console.error( USAGE );
		process.exit( 1 );
	}

	fs.mkdirSync( OUT_DIR, { recursive: true } );

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
		.filter( Boolean )
		.map( ( s ) => parseInt( s, 10 ) )
		.filter( ( n ) => Number.isFinite( n ) );
	const shots = only.length
		? SHOTS.filter( ( s ) => only.includes( s.n ) )
		: SHOTS;
	if ( only.length && shots.length !== only.length ) {
		const known = new Set( SHOTS.map( ( s ) => s.n ) );
		const missing = only.filter( ( n ) => ! known.has( n ) );
		throw new Error( `Unknown SCREEN number(s): ${ missing.join( ', ' ) }` );
	}

	const summary = { noticeCssHides: 0, chatHadTools: null };
	try {
		await login( page );
		await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=agent-builder`, {
			waitUntil: 'domcontentloaded',
			timeout: 45000,
		} );
		await waitForScreen( page );
		const noticeOnLoad = await page.locator( APPLE_PAY_NOTICE ).count();
		console.log(
			noticeOnLoad
				? 'warn  Apple Pay notice still visible after option update'
				: 'ok    Apple Pay notice absent on dashboard'
		);
		for ( const shot of shots ) {
			const result = await captureShot( page, shot );
			if ( result.noticeWasVisible ) {
				summary.noticeCssHides += 1;
			}
			if ( result.chatHadTools !== null ) {
				summary.chatHadTools = result.chatHadTools;
			}
		}
	} finally {
		await browser.close();
	}

	console.log(
		`done  noticeCssHides=${ summary.noticeCssHides } chatHadTools=${ summary.chatHadTools }`
	);
}

main().catch( ( err ) => {
	console.error( err.message || err );
	process.exit( 1 );
} );
