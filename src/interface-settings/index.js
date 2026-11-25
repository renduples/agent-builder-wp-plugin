/**
 * Interface settings — React / @wordpress/components admin panel.
 *
 * UI mode, onboarding, appearance (font/accent), and emergency stop.
 * All fields save through agentic/v1/ui-settings (no PHP form).
 */
import { createRoot, useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	RadioControl,
	ToggleControl,
	SelectControl,
	BaseControl,
	Button,
	Spinner,
	Notice,
	Flex,
	FlexItem,
	ExternalLink,
} from '@wordpress/components';

const REST_PATH = 'agentic/v1/ui-settings';

const FONT_OPTIONS = [
	{ label: __( 'Theme default', 'agentstructor' ), value: '' },
	{ label: __( 'System UI', 'agentstructor' ), value: 'system-ui, sans-serif' },
	{ label: 'Arial / Helvetica', value: 'Arial, Helvetica, sans-serif' },
	{ label: 'Georgia', value: 'Georgia, serif' },
	{
		label: 'Times New Roman',
		value: '"Times New Roman", Times, serif',
	},
	{
		label: 'Courier New (monospace)',
		value: '"Courier New", Courier, monospace',
	},
	{ label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
];

function InterfaceSettings() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saved, setSaved ] = useState( false );
	const [ uiMode, setUiMode ] = useState( 'basic' );
	const [ showOnboarding, setShowOnboarding ] = useState( true );
	const [ disableAllAgents, setDisableAllAgents ] = useState( false );
	const [ globalFont, setGlobalFont ] = useState( '' );
	const [ globalAccent, setGlobalAccent ] = useState( '#8b5cf6' );
	const [ useThemeAccent, setUseThemeAccent ] = useState( true );
	const [ themesUrl, setThemesUrl ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: REST_PATH } )
			.then( ( data ) => {
				setUiMode( data.ui_mode === 'advanced' ? 'advanced' : 'basic' );
				setShowOnboarding( !! data.show_onboarding );
				setDisableAllAgents( !! data.disable_all_agents );
				setGlobalFont(
					typeof data.global_font === 'string' ? data.global_font : ''
				);
				const accent =
					typeof data.global_accent === 'string'
						? data.global_accent
						: '';
				setUseThemeAccent( ! accent );
				setGlobalAccent( accent || '#8b5cf6' );
				if ( data.themes_url ) {
					setThemesUrl( data.themes_url );
				}
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Could not load interface settings.',
							'agentstructor'
						)
				);
				setLoading( false );
			} );
	}, [] );

	const save = () => {
		setSaving( true );
		setSaved( false );
		setError( '' );
		apiFetch( {
			path: REST_PATH,
			method: 'POST',
			data: {
				ui_mode: uiMode,
				show_onboarding: showOnboarding,
				disable_all_agents: disableAllAgents,
				global_font: globalFont,
				global_accent: useThemeAccent ? '' : globalAccent,
				use_theme_accent: useThemeAccent,
			},
		} )
			.then( ( data ) => {
				setUiMode( data.ui_mode === 'advanced' ? 'advanced' : 'basic' );
				setShowOnboarding( !! data.show_onboarding );
				setDisableAllAgents( !! data.disable_all_agents );
				setGlobalFont(
					typeof data.global_font === 'string' ? data.global_font : ''
				);
				const accent =
					typeof data.global_accent === 'string'
						? data.global_accent
						: '';
				setUseThemeAccent( ! accent );
				setGlobalAccent( accent || '#8b5cf6' );
				setSaving( false );
				setSaved( true );
			} )
			.catch( ( err ) => {
				setError(
					err.message ||
						__(
							'Could not save interface settings.',
							'agentstructor'
						)
				);
				setSaving( false );
			} );
	};

	if ( loading ) {
		return (
			<Card>
				<CardBody>
					<Spinner />{ ' ' }
					{ __( 'Loading interface settings…', 'agentstructor' ) }
				</CardBody>
			</Card>
		);
	}

	return (
		<Card className="agentic-interface-settings">
			<CardHeader>
				<h2 style={ { margin: 0 } }>
					{ __( 'Interface', 'agentstructor' ) }
				</h2>
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice
						status="error"
						isDismissible={ false }
						className="agentic-mb-12"
					>
						{ error }
					</Notice>
				) }
				{ saved && ! error && (
					<Notice
						status="success"
						isDismissible
						onRemove={ () => setSaved( false ) }
						className="agentic-mb-12"
					>
						{ __( 'Interface settings saved.', 'agentstructor' ) }
					</Notice>
				) }

				<RadioControl
					label={ __( 'Interface mode', 'agentstructor' ) }
					help={ __(
						'Basic keeps the menu focused on everyday tasks. Advanced reveals developer tools such as Skills, APIs and Endpoints.',
						'agentstructor'
					) }
					selected={ uiMode }
					options={ [
						{
							label: __( 'Basic', 'agentstructor' ),
							value: 'basic',
						},
						{
							label: __( 'Advanced', 'agentstructor' ),
							value: 'advanced',
						},
					] }
					onChange={ ( value ) => setUiMode( value ) }
				/>

				<div style={ { marginTop: '20px' } }>
					<ToggleControl
						label={ __(
							'Show the Getting Started checklist on the dashboard',
							'agentstructor'
						) }
						help={ __(
							'Turn this off once you are set up to hide the onboarding checklist.',
							'agentstructor'
						) }
						checked={ showOnboarding }
						onChange={ ( value ) => setShowOnboarding( value ) }
						__nextHasNoMarginBottom
					/>
				</div>

				<hr
					style={ {
						margin: '28px 0 20px',
						border: 0,
						borderTop: '1px solid #e0e0e0',
					} }
				/>

				<h3
					style={ {
						margin: '0 0 4px',
						fontSize: '13px',
						fontWeight: 600,
					} }
				>
					{ __( 'Appearance', 'agentstructor' ) }
				</h3>
				<p
					style={ {
						margin: '0 0 16px',
						color: '#646970',
						fontSize: '12px',
					} }
				>
					{ __(
						'Global look and feel for chat surfaces.',
						'agentstructor'
					) }{ ' ' }
					{ themesUrl ? (
						<ExternalLink href={ themesUrl }>
							{ __( 'Fine-tune chat themes →', 'agentstructor' ) }
						</ExternalLink>
					) : null }
				</p>

				<SelectControl
					label={ __( 'Chat font', 'agentstructor' ) }
					help={ __(
						'Font used across the chat interface. Choose “Theme default” to inherit from the selected theme.',
						'agentstructor'
					) }
					value={ globalFont }
					options={ FONT_OPTIONS }
					onChange={ ( value ) => setGlobalFont( value ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<div style={ { marginTop: '20px' } }>
					<ToggleControl
						label={ __(
							'Use theme accent colour',
							'agentstructor'
						) }
						help={ __(
							'When off, the colour below overrides the chat theme accent on every chat surface.',
							'agentstructor'
						) }
						checked={ useThemeAccent }
						onChange={ ( value ) => setUseThemeAccent( value ) }
						__nextHasNoMarginBottom
					/>
				</div>

				{ ! useThemeAccent && (
					<div style={ { marginTop: '16px' } }>
						<BaseControl
							id="agentic-global-accent"
							label={ __( 'Accent colour', 'agentstructor' ) }
							help={ sprintf(
								/* translators: %s: hex colour value */
								__( 'Selected: %s', 'agentstructor' ),
								globalAccent
							) }
							__nextHasNoMarginBottom
						>
							<input
								id="agentic-global-accent"
								type="color"
								value={ globalAccent || '#8b5cf6' }
								onChange={ ( e ) =>
									setGlobalAccent( e.target.value )
								}
								style={ {
									width: '48px',
									height: '36px',
									padding: 0,
									border: '1px solid #c3c4c7',
									borderRadius: '4px',
									cursor: 'pointer',
									background: '#fff',
								} }
							/>
						</BaseControl>
					</div>
				) }

				<div
					style={ {
						marginTop: '28px',
						padding: '16px',
						border: disableAllAgents
							? '1px solid #d63638'
							: '1px solid #dcdcde',
						borderRadius: '8px',
						background: disableAllAgents ? '#fcf0f1' : '#f6f7f7',
					} }
				>
					<ToggleControl
						label={ __( 'Disable All Agents', 'agentstructor' ) }
						help={
							<span className="agentic-emergency-stop-hint">
								<strong className="agentic-emergency-stop-text">
									{ __( 'Emergency stop', 'agentstructor' ) }
								</strong>
								{ __(
									': deactivate and log agent states, cancels all jobs and disconnects providers.',
									'agentstructor'
								) }
							</span>
						}
						checked={ disableAllAgents }
						onChange={ ( value ) => {
							if ( value === disableAllAgents ) {
								return;
							}
							const msg = value
								? __(
										'EMERGENCY STOP: deactivate and log agent states, cancel all jobs, and disconnect providers. Continue?',
										'agentstructor'
								  )
								: __(
										'Turn off emergency stop?',
										'agentstructor'
								  );
							if (
								typeof window !== 'undefined' &&
								! window.confirm( msg )
							) {
								return;
							}
							setDisableAllAgents( value );
						} }
						__nextHasNoMarginBottom
					/>
					{ disableAllAgents && (
						<Notice
							status="error"
							isDismissible={ false }
							className="agentic-mt-12"
						>
							{ __(
								'Emergency stop is ACTIVE. Chat, jobs, and agent activation are blocked until you turn this off.',
								'agentstructor'
							) }
						</Notice>
					) }
				</div>
			</CardBody>
			<CardFooter>
				<Flex justify="flex-start">
					<FlexItem>
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ saving }
							disabled={ saving }
						>
							{ saving
								? __( 'Saving…', 'agentstructor' )
								: __( 'Save changes', 'agentstructor' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardFooter>
		</Card>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agentic-interface-settings-root' );
	if ( root ) {
		createRoot( root ).render( <InterfaceSettings /> );
	}
} );
