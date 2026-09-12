/**
 * Publish Wizard — guided "get your agent in front of people" flow.
 *
 * A short React island (@wordpress/element + components) that walks a user
 * through picking one agent and one surface to publish it on (chat widget,
 * admin bar, Ask AI launcher, or Gutenberg block), a minimal per-surface
 * config, then save. Saving goes through agentic/v1/deploy-wizard/save,
 * which drives the exact same storage the classic Publish tabs use — this
 * is a friendlier front door to it, not a separate deployment system.
 */
import { createRoot, useState, useEffect, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardBody,
	CardHeader,
	CardFooter,
	Button,
	SelectControl,
	CheckboxControl,
	Notice,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const CFG = ( typeof window !== 'undefined' && window.agenticDeployWizard ) || {};

const SURFACES = [
	{
		key: 'chat_widget',
		label: __( 'Chat widget', 'agent-builder' ),
		hint: __( 'A floating chat bubble visitors can open on your site.', 'agent-builder' ),
	},
	{
		key: 'admin_bar',
		label: __( 'Admin bar', 'agent-builder' ),
		hint: __( 'A quick-chat menu in the WordPress toolbar for logged-in admins.', 'agent-builder' ),
	},
	{
		key: 'ask_ai',
		label: __( 'Ask AI launcher', 'agent-builder' ),
		hint: __( 'A contextual "Ask AI" prompt on admin screens like Plugins or Media.', 'agent-builder' ),
	},
	{
		key: 'gutenberg_block',
		label: __( 'Gutenberg block', 'agent-builder' ),
		hint: __( 'Makes this agent insertable as a block in any post or page.', 'agent-builder' ),
	},
];

const STEPS = [
	{ key: 'pick', label: __( 'Agent & surface', 'agent-builder' ) },
	{ key: 'configure', label: __( 'Configure', 'agent-builder' ) },
];

function Stepper( { current } ) {
	return (
		<ol
			className="agentic-wizard-steps"
			aria-label={ __( 'Wizard steps', 'agent-builder' ) }
		>
			{ STEPS.map( ( stepItem, index ) => (
				<li
					key={ stepItem.key }
					className={
						'agentic-wizard-step' +
						( index === current ? ' is-active' : '' ) +
						( index < current ? ' is-done' : '' )
					}
					aria-current={ index === current ? 'step' : undefined }
				>
					<span className="agentic-wizard-step__num">
						{ index + 1 }
					</span>
					<span className="agentic-wizard-step__label">
						{ stepItem.label }
					</span>
				</li>
			) ) }
		</ol>
	);
}

function App() {
	const [ options, setOptions ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ step, setStep ] = useState( 0 );

	const [ agent, setAgent ] = useState( CFG.presetAgent || '' );
	const [ surface, setSurface ] = useState( 'chat_widget' );

	const [ position, setPosition ] = useState( 'bottom-right' );
	const [ pages, setPages ] = useState( 'all' );
	const [ requireLogin, setRequireLogin ] = useState( false );
	const [ screens, setScreens ] = useState( [] );

	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( null );

	useEffect( () => {
		loadOptions();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	function loadOptions() {
		return apiFetch( { path: 'agentic/v1/deploy-wizard/options' } )
			.then( ( data ) => {
				setOptions( data );
				if ( ! agent && data.agents && data.agents.length ) {
					setAgent( data.agents[ 0 ].slug );
				}
				if ( data.ask_ai_screens && ! data.ask_ai_screens_are_set ) {
					setScreens( data.ask_ai_screens.map( ( s ) => s.key ) );
				}
			} )
			.catch( ( err ) =>
				setLoadError(
					err.message ||
						__( 'Could not load the wizard.', 'agent-builder' )
				)
			);
	}

	const toggleScreen = ( key ) =>
		setScreens( ( prev ) =>
			prev.includes( key )
				? prev.filter( ( x ) => x !== key )
				: [ ...prev, key ]
		);

	const pagesOptionsFor = ( surfaceKey ) =>
		surfaceKey === 'admin_bar'
			? [
					{ label: __( 'Everywhere', 'agent-builder' ), value: 'all' },
					{ label: __( 'Admin only', 'agent-builder' ), value: 'admin' },
					{ label: __( 'Front end only', 'agent-builder' ), value: 'front' },
			  ]
			: [
					{ label: __( 'Everywhere', 'agent-builder' ), value: 'all' },
					{ label: __( 'Front end only', 'agent-builder' ), value: 'front' },
					{ label: __( 'Single posts/pages only', 'agent-builder' ), value: 'singular' },
					{ label: __( 'Homepage only', 'agent-builder' ), value: 'homepage' },
			  ];

	const submit = () => {
		setSubmitting( true );
		setError( '' );
		const config = {};
		if ( surface === 'chat_widget' || surface === 'admin_bar' ) {
			config.position = position;
			config.pages = pages;
		}
		if ( surface === 'chat_widget' ) {
			config.require_login = requireLogin;
		}
		if ( surface === 'ask_ai' ) {
			config.screens = screens;
		}
		apiFetch( {
			path: 'agentic/v1/deploy-wizard/save',
			method: 'POST',
			data: { agent, surface, config },
		} )
			.then( ( res ) => {
				setDone( res );
				// Keep options fresh so re-opening this surface later in the
				// same session (via "Publish on another surface") reflects
				// what was actually just saved, e.g. Ask AI launcher screens.
				return loadOptions();
			} )
			.catch( ( err ) =>
				setError(
					err.message ||
						__(
							'Something went wrong publishing this agent.',
							'agent-builder'
						)
				)
			)
			.finally( () => setSubmitting( false ) );
	};

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	}

	if ( ! options ) {
		return (
			<div className="agentic-wizard-loading">
				<Spinner /> { __( 'Loading…', 'agent-builder' ) }
			</div>
		);
	}

	if ( ! options.agents || ! options.agents.length ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'No active agents yet. Activate or train an agent first, then come back to publish it.',
					'agent-builder'
				) }{ ' ' }
				<a href={ CFG.agentsUrl }>
					{ __( 'Go to Agents', 'agent-builder' ) }
				</a>
			</Notice>
		);
	}

	if ( done ) {
		const agentInfo = options.agents.find( ( a ) => a.slug === agent );
		const surfaceInfo = SURFACES.find( ( s ) => s.key === surface );
		return (
			<Card className="agentic-wizard-card">
				<CardBody>
					<h2 className="agentic-wizard-success">
						{ '🚀 ' }
						{ sprintf(
							/* translators: 1: agent name, 2: surface name. */
							__( '%1$s is published on %2$s!', 'agent-builder' ),
							( agentInfo && agentInfo.name ) || agent,
							( surfaceInfo && surfaceInfo.label ) || surface
						) }
					</h2>
					<p>
						{ __(
							'Fine-tune this further, or publish on another surface.',
							'agent-builder'
						) }
					</p>
					<Flex justify="flex-start" gap={ 3 } wrap>
						<FlexItem>
							<Button
								variant="primary"
								onClick={ () => {
									setDone( null );
									setStep( 0 );
								} }
							>
								{ __( 'Publish on another surface', 'agent-builder' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="secondary" href={ done.manage_url }>
								{ __( 'Manage in Publish', 'agent-builder' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="tertiary" href={ CFG.dashboardUrl }>
								{ __( 'Back to Dashboard', 'agent-builder' ) }
							</Button>
						</FlexItem>
					</Flex>
				</CardBody>
			</Card>
		);
	}

	return (
		<Card className="agentic-wizard-card">
			<CardHeader>
				<Stepper current={ step } />
			</CardHeader>
			<CardBody>
				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }

				{ step === 0 && (
					<Fragment>
						<SelectControl
							label={ __( 'Which agent?', 'agent-builder' ) }
							value={ agent }
							options={ options.agents.map( ( a ) => ( {
								label: `${ a.icon || '' } ${ a.name }`.trim(),
								value: a.slug,
							} ) ) }
							onChange={ setAgent }
							__next40pxDefaultSize
						/>
						<p className="agentic-wizard-sublabel">
							{ __( 'Where should people reach it?', 'agent-builder' ) }
						</p>
						<div className="agentic-wizard-tools">
							{ SURFACES.map( ( s ) => (
								<div key={ s.key } className="agentic-wizard-surface-choice">
									<Button
										variant={
											surface === s.key ? 'primary' : 'secondary'
										}
										onClick={ () => setSurface( s.key ) }
									>
										{ s.label }
									</Button>
									{ surface === s.key && (
										<p className="agentic-wizard-hint">
											{ s.hint }
										</p>
									) }
								</div>
							) ) }
						</div>
					</Fragment>
				) }

				{ step === 1 && surface === 'gutenberg_block' && (
					<p className="agentic-wizard-hint">
						{ __(
							'Nothing else to configure — saving makes this agent available to insert as a block from any post or page editor.',
							'agent-builder'
						) }
					</p>
				) }

				{ step === 1 && ( surface === 'chat_widget' || surface === 'admin_bar' ) && (
					<Fragment>
						<SelectControl
							label={ __( 'Position', 'agent-builder' ) }
							value={ position }
							options={ [
								{ label: __( 'Bottom right', 'agent-builder' ), value: 'bottom-right' },
								{ label: __( 'Bottom left', 'agent-builder' ), value: 'bottom-left' },
							] }
							onChange={ setPosition }
							__next40pxDefaultSize
						/>
						<SelectControl
							label={ __( 'Show on', 'agent-builder' ) }
							value={ pages }
							options={ pagesOptionsFor( surface ) }
							onChange={ setPages }
							__next40pxDefaultSize
						/>
						{ surface === 'chat_widget' && (
							<CheckboxControl
								label={ __( 'Only show to logged-in visitors', 'agent-builder' ) }
								checked={ requireLogin }
								onChange={ setRequireLogin }
							/>
						) }
					</Fragment>
				) }

				{ step === 1 && surface === 'ask_ai' && (
					<Fragment>
						{ options.ask_ai_screens_are_set ? (
							<p className="agentic-wizard-hint">
								{ sprintf(
									/* translators: %s: agent name. */
									__(
										'Ask AI launchers are already turned on for some admin screens. Saving makes %s the agent that answers them, replacing the current choice — the screens themselves stay as configured.',
										'agent-builder'
									),
									( options.agents.find( ( a ) => a.slug === agent ) || {} )
										.name || agent
								) }
							</p>
						) : (
							<Fragment>
								<p className="agentic-wizard-sublabel">
									{ __( 'Which admin screens?', 'agent-builder' ) }
								</p>
								{ ( options.ask_ai_screens || [] ).map( ( s ) => (
									<CheckboxControl
										key={ s.key }
										label={ s.label }
										help={ s.description }
										checked={ screens.includes( s.key ) }
										onChange={ () => toggleScreen( s.key ) }
									/>
								) ) }
							</Fragment>
						) }
					</Fragment>
				) }
			</CardBody>
			<CardFooter>
				<Flex justify="space-between">
					<FlexItem>
						{ step > 0 && (
							<Button
								variant="secondary"
								onClick={ () => setStep( step - 1 ) }
								disabled={ submitting }
							>
								{ __( 'Back', 'agent-builder' ) }
							</Button>
						) }
					</FlexItem>
					<FlexItem>
						{ step === 0 ? (
							<Button
								variant="primary"
								onClick={ () => setStep( 1 ) }
								disabled={ ! agent }
							>
								{ __( 'Continue', 'agent-builder' ) }
							</Button>
						) : (
							<Button
								variant="primary"
								onClick={ submit }
								isBusy={ submitting }
								disabled={ submitting }
							>
								{ __( 'Publish', 'agent-builder' ) }
							</Button>
						) }
					</FlexItem>
				</Flex>
			</CardFooter>
		</Card>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agentic-deploy-wizard-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
