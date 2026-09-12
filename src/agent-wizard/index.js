/**
 * Agent Creation Wizard — the "Train an Agent" guided flow.
 *
 * A multi-step React island (rendered with @wordpress/element + components) that
 * walks a non-technical user through creating and activating a new AI agent. It
 * reads form data from agentic/v1/agent-wizard/options and submits to
 * agentic/v1/agent-wizard/create. All real work (manifest, abilities, activation)
 * happens server-side; this is purely the guided UI.
 */
import { createRoot, useState, useEffect, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardBody,
	CardHeader,
	CardFooter,
	Button,
	TextControl,
	TextareaControl,
	SelectControl,
	RadioControl,
	CheckboxControl,
	Notice,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const STEPS = [
	{ key: 'basics', label: __( 'Basics', 'agent-builder' ) },
	{ key: 'persona', label: __( 'Persona', 'agent-builder' ) },
	{ key: 'brain', label: __( 'Capabilities', 'agent-builder' ) },
	{ key: 'knowledge', label: __( 'Knowledge', 'agent-builder' ) },
	{ key: 'review', label: __( 'Review', 'agent-builder' ) },
];

// Localized RAG/knowledge bridge (admin-ajax + nonce) from agent-wizard.php.
const RAG = ( typeof window !== 'undefined' && window.agenticWizardRag ) || {};

function slugify( value ) {
	return String( value )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

// Lightweight single-placeholder formatter (avoids extra deps).
function format( template, value ) {
	return template.replace( '%s', value );
}

function capitalize( value ) {
	const text = String( value );
	return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
}

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

function ReviewRow( { label, value } ) {
	return (
		<div className="agentic-wizard-review__row">
			<span className="agentic-wizard-review__label">{ label }</span>
			<span className="agentic-wizard-review__value">{ value }</span>
		</div>
	);
}

function App() {
	const [ options, setOptions ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ step, setStep ] = useState( 0 );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( null );

	// Knowledge (RAG) step state.
	const [ kbItems, setKbItems ] = useState( null );
	const [ kbError, setKbError ] = useState( '' );
	const [ selectedPosts, setSelectedPosts ] = useState( [] );
	const [ files, setFiles ] = useState( [] );
	const [ training, setTraining ] = useState( null );

	const [ form, setForm ] = useState( {
		name: '',
		slug: '',
		slugTouched: false,
		description: '',
		category: 'admin',
		icon: '🤖',
		system_prompt: '',
		welcome_message: '',
		prompts: [ '', '', '' ],
		provider: '',
		model: '',
		mode: 'supervised',
		tools: [],
	} );

	useEffect( () => {
		apiFetch( { path: 'agentic/v1/agent-wizard/options' } )
			.then( ( data ) => {
				setOptions( data );
				if ( data.providers && data.providers.length ) {
					const configured =
						data.providers.find( ( p ) => p.configured ) ||
						data.providers[ 0 ];
					setForm( ( f ) => ( {
						...f,
						provider: configured.slug,
						model: configured.default_model || '',
					} ) );
				}
			} )
			.catch( ( err ) =>
				setLoadError(
					err.message ||
						__( 'Could not load the wizard.', 'agent-builder' )
				)
			);
	}, [] );

	const set = ( key, value ) =>
		setForm( ( f ) => {
			const next = { ...f, [ key ]: value };
			if ( key === 'name' && ! f.slugTouched ) {
				next.slug = slugify( value );
			}
			if ( key === 'slug' ) {
				next.slug = slugify( value );
				next.slugTouched = true;
			}
			return next;
		} );

	const ragAjax = ( action, formData ) => {
		formData.append( 'action', action );
		formData.append( '_ajax_nonce', RAG.nonce || '' );
		return fetch( RAG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} ).then( ( r ) => r.json() );
	};

	useEffect( () => {
		if ( step !== 3 || ! RAG.hasLicense || kbItems !== null ) {
			return;
		}
		const fd = new FormData();
		fd.append( 'post_type', 'both' );
		fd.append( 'limit', '50' );
		ragAjax( 'agentic_td_scan_content', fd )
			.then( ( res ) => {
				if ( res && res.success ) {
					setKbItems( ( res.data && res.data.items ) || [] );
				} else {
					setKbItems( [] );
					setKbError( ( res && res.data ) || __( 'Could not load your content.', 'agent-builder' ) );
				}
			} )
			.catch( () => {
				setKbItems( [] );
				setKbError( __( 'Could not load your content.', 'agent-builder' ) );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ step ] );

	const togglePost = ( id ) =>
		setSelectedPosts( ( prev ) =>
			prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ]
		);

	const knowledgeSummary = () => {
		const parts = [];
		if ( selectedPosts.length ) {
			parts.push( format( __( '%s pages/posts', 'agent-builder' ), String( selectedPosts.length ) ) );
		}
		if ( files.length ) {
			parts.push( format( __( '%s files', 'agent-builder' ), String( files.length ) ) );
		}
		return parts.length ? parts.join( ', ' ) : __( '(none)', 'agent-builder' );
	};

	const startTraining = () => {
		if ( ! RAG.hasLicense ) {
			return;
		}
		const tasks = [];
		selectedPosts.forEach( ( id ) => tasks.push( { type: 'post', id } ) );
		files.forEach( ( file ) => tasks.push( { type: 'file', file } ) );
		if ( ! tasks.length ) {
			return;
		}
		setTraining( { total: tasks.length, done: 0, ok: 0, fail: 0, running: true } );
		( async () => {
			let ok = 0;
			let fail = 0;
			for ( let i = 0; i < tasks.length; i++ ) {
				const task = tasks[ i ];
				const fd = new FormData();
				let action;
				if ( task.type === 'post' ) {
					action = 'agentic_td_train_post';
					fd.append( 'post_id', String( task.id ) );
				} else {
					action = 'agentic_td_upload_file';
					fd.append( 'file', task.file );
				}
				try {
					// eslint-disable-next-line no-await-in-loop
					const res = await ragAjax( action, fd );
					if ( res && res.success ) { ok++; } else { fail++; }
				} catch ( e ) { fail++; }
				setTraining( { total: tasks.length, done: i + 1, ok, fail, running: i + 1 < tasks.length } );
			}
		} )();
	};

	const selectedProvider =
		options && options.providers
			? options.providers.find( ( p ) => p.slug === form.provider )
			: null;

	const canNext = () => {
		if ( step === 0 ) {
			return (
				form.name.trim() !== '' &&
				form.description.trim() !== '' &&
				form.slug !== ''
			);
		}
		return true;
	};

	const submit = () => {
		setSubmitting( true );
		setError( '' );
		apiFetch( {
			path: 'agentic/v1/agent-wizard/create',
			method: 'POST',
			data: {
				name: form.name,
				slug: form.slug,
				description: form.description,
				category: form.category,
				icon: form.icon,
				system_prompt: form.system_prompt,
				welcome_message: form.welcome_message,
				suggested_prompts: form.prompts.filter(
					( p ) => p.trim() !== ''
				),
				provider: form.provider,
				model: form.model,
				mode: form.mode,
				tools: form.tools,
			},
		} )
			.then( ( res ) => {
				setDone( res );
				startTraining();
			} )
			.catch( ( err ) =>
				setError(
					err.message ||
						__(
							'Something went wrong creating the agent.',
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

	if ( done ) {
		return (
			<Card className="agentic-wizard-card">
				<CardBody>
					<h2 className="agentic-wizard-success">
						{ form.icon }{ ' ' }
						{ format(
							// translators: %s: the new agent's name.
							__( '%s is ready!', 'agent-builder' ),
							done.name || form.name
						) }
					</h2>
					<p>
						{ done.activated
							? __(
									'Your agent has been created and activated. Add knowledge to make it smarter, or start chatting right away.',
									'agent-builder'
							  )
							: __(
									'Your agent was created. Activate it from the Agents page, then add knowledge or start chatting.',
									'agent-builder'
							  ) }
					</p>
					{ training && (
						<Notice
							status={ training.running ? 'info' : training.fail ? 'warning' : 'success' }
							isDismissible={ false }
						>
							{ training.running
								? format( __( 'Training your agent on your knowledge… %s', 'agent-builder' ), `${ training.done }/${ training.total }` )
								: format( __( 'Knowledge training finished — %s sources trained.', 'agent-builder' ), `${ training.ok }/${ training.total }` ) }
						</Notice>
					) }
					{ done.warning && (
						<Notice status="warning" isDismissible={ false }>
							{ done.warning }
						</Notice>
					) }
					{ done.default_tools && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'A safe set of read-only tools was added so your agent works right away. You can change its tools later from the Agents page.',
								'agent-builder'
							) }
						</Notice>
					) }
					<Flex justify="flex-start" gap={ 3 } wrap>
						<FlexItem>
							<Button
								variant="primary"
								href={ done.knowledge_url }
							>
								{ __( 'Add Knowledge', 'agent-builder' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="secondary" href={ done.chat_url }>
								{ __( 'Open Chat', 'agent-builder' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="secondary" href={ done.deploy_url }>
								{ __( 'Publish This Agent', 'agent-builder' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="tertiary"
								href={ done.configure_url }
							>
								{ __( 'Fine-tune Persona', 'agent-builder' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="tertiary" href={ done.agents_url }>
								{ __( 'View all Agents', 'agent-builder' ) }
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
						<TextControl
							label={ __( 'Agent name', 'agent-builder' ) }
							help={ __(
								'A friendly name, e.g. "Support Helper".',
								'agent-builder'
							) }
							value={ form.name }
							onChange={ ( v ) => set( 'name', v ) }
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __( 'Identifier (slug)', 'agent-builder' ) }
							help={ __(
								'Used in URLs and files. Auto-filled from the name.',
								'agent-builder'
							) }
							value={ form.slug }
							onChange={ ( v ) => set( 'slug', v ) }
							__next40pxDefaultSize
						/>
						<TextareaControl
							label={ __(
								'What does this agent do?',
								'agent-builder'
							) }
							help={ __(
								'A short description shown in the agents list.',
								'agent-builder'
							) }
							value={ form.description }
							onChange={ ( v ) => set( 'description', v ) }
						/>
						<div className="agentic-wizard-inline-fields">
							<div className="agentic-wizard-inline-fields__grow">
								<SelectControl
									label={ __( 'Category', 'agent-builder' ) }
									value={ form.category }
									options={ options.categories.map(
										( c ) => ( {
											label:
												c.charAt( 0 ).toUpperCase() +
												c.slice( 1 ),
											value: c,
										} )
									) }
									onChange={ ( v ) => set( 'category', v ) }
									__next40pxDefaultSize
								/>
							</div>
							<div className="agentic-wizard-inline-fields__icon">
								<TextControl
									label={ __( 'Icon', 'agent-builder' ) }
									value={ form.icon }
									onChange={ ( v ) => set( 'icon', v ) }
									__next40pxDefaultSize
								/>
							</div>
						</div>
					</Fragment>
				) }

				{ step === 1 && (
					<Fragment>
						<TextareaControl
							label={ __(
								'Instructions (system prompt)',
								'agent-builder'
							) }
							help={ __(
								'Describe how the agent should behave, its tone, and what it should focus on.',
								'agent-builder'
							) }
							value={ form.system_prompt }
							onChange={ ( v ) => set( 'system_prompt', v ) }
							rows={ 6 }
						/>
						<TextControl
							label={ __(
								'Welcome message (optional)',
								'agent-builder'
							) }
							help={ __(
								'The greeting shown when a chat starts.',
								'agent-builder'
							) }
							value={ form.welcome_message }
							onChange={ ( v ) => set( 'welcome_message', v ) }
							__next40pxDefaultSize
						/>
						<p className="agentic-wizard-sublabel">
							{ __(
								'Suggested prompts (optional)',
								'agent-builder'
							) }
						</p>
						{ form.prompts.map( ( prompt, index ) => (
							<TextControl
								key={ index }
								label={ format(
									__( 'Suggested prompt %s', 'agent-builder' ),
									String( index + 1 )
								) }
								hideLabelFromVision
								value={ prompt }
								placeholder={ __(
									'e.g. Summarize my latest posts',
									'agent-builder'
								) }
								onChange={ ( v ) => {
									const prompts = form.prompts.slice();
									prompts[ index ] = v;
									set( 'prompts', prompts );
								} }
								__next40pxDefaultSize
							/>
						) ) }
						{ form.prompts.length < 6 && (
							<Button
								variant="link"
								onClick={ () =>
									set( 'prompts', [ ...form.prompts, '' ] )
								}
							>
								{ __(
									'+ Add another prompt',
									'agent-builder'
								) }
							</Button>
						) }
					</Fragment>
				) }

				{ step === 2 && (
					<Fragment>
						<SelectControl
							label={ __( 'AI provider', 'agent-builder' ) }
							value={ form.provider }
							options={ options.providers.map( ( p ) => ( {
								label:
									p.name +
									( p.configured
										? ''
										: ' ' +
										  __(
												'(needs API key)',
												'agent-builder'
										  ) ),
								value: p.slug,
							} ) ) }
							onChange={ ( v ) => {
								const prov = options.providers.find(
									( p ) => p.slug === v
								);
								setForm( ( f ) => ( {
									...f,
									provider: v,
									model: prov ? prov.default_model || '' : '',
								} ) );
							} }
							help={ __(
								'Leave as-is to use your site default.',
								'agent-builder'
							) }
							__next40pxDefaultSize
						/>
						{ selectedProvider &&
							selectedProvider.models &&
							selectedProvider.models.length > 0 && (
								<SelectControl
									label={ __( 'Model', 'agent-builder' ) }
									value={ form.model }
									options={ selectedProvider.models.map(
										( m ) => ( {
											label: m,
											value: m,
										} )
									) }
									onChange={ ( v ) => set( 'model', v ) }
									__next40pxDefaultSize
								/>
							) }
						<RadioControl
							label={ __( 'Autonomy', 'agent-builder' ) }
							selected={ form.mode }
							options={ [
								{
									label: __(
										'Supervised — asks before taking actions (recommended)',
										'agent-builder'
									),
									value: 'supervised',
								},
								{
									label: __(
										'Autonomous — acts on its own',
										'agent-builder'
									),
									value: 'autonomous',
								},
								{
									label: __(
										'Disabled — replies only, never acts',
										'agent-builder'
									),
									value: 'disabled',
								},
							] }
							onChange={ ( v ) => set( 'mode', v ) }
						/>
						<p className="agentic-wizard-hint">
							{ __(
								'This only controls how often the agent pauses to ask you. Higher-risk actions still queue for your approval, and the riskiest actions are always blocked, no matter which autonomy level you pick.',
								'agent-builder'
							) }
						</p>
						{ options.tools && options.tools.length > 0 && (
							<Fragment>
								<p className="agentic-wizard-sublabel">
									{ __(
										'Tools this agent can use (optional)',
										'agent-builder'
									) }
								</p>
								<p className="agentic-wizard-hint">
									{ __(
										'If you don’t pick any, a safe set of read-only tools is added so your agent works right away.',
										'agent-builder'
									) }
								</p>
								<div className="agentic-wizard-tools">
									{ options.tools.map( ( tool ) => (
										<CheckboxControl
											key={ tool.name }
											label={ tool.name }
											help={ tool.description }
											checked={ form.tools.includes(
												tool.name
											) }
											onChange={ ( checked ) => {
												const tools = checked
													? [
															...form.tools,
															tool.name,
													  ]
													: form.tools.filter(
															( t ) =>
																t !== tool.name
													  );
												set( 'tools', tools );
											} }
										/>
									) ) }
								</div>
							</Fragment>
						) }
					</Fragment>
				) }

				{ step === 3 && (
					<Fragment>
						{ ! RAG.hasLicense ? (
							<Notice status="info" isDismissible={ false }>
								{ __( 'Knowledge uses the Agentic AI service. Connect your license to train agents on your content.', 'agent-builder' ) }
								{ ' ' }
								<a href={ RAG.knowledgeUrl }>
									{ __( 'Add knowledge later from the Knowledge page.', 'agent-builder' ) }
								</a>
							</Notice>
						) : (
							<Fragment>
								<p className="agentic-wizard-hint">
									{ __( 'Optionally give your agents knowledge to draw on. Selected pages, posts and files are embedded into your shared Vector Store after the agent is created — training runs in the background.', 'agent-builder' ) }
								</p>
								<label className="agentic-wizard-sublabel" htmlFor="agentic-wizard-kb-files">
									{ __( 'Upload files (PDF or text)', 'agent-builder' ) }
								</label>
								<input
									id="agentic-wizard-kb-files"
									type="file"
									multiple
									accept=".pdf,.txt,.md,application/pdf,text/plain"
									onChange={ ( e ) => setFiles( Array.from( e.target.files || [] ) ) }
								/>
								{ files.length > 0 && (
									<p className="agentic-wizard-hint">{ format( __( '%s file(s) selected.', 'agent-builder' ), String( files.length ) ) }</p>
								) }
								<p className="agentic-wizard-sublabel">{ __( 'Train on your site content', 'agent-builder' ) }</p>
								{ kbError && (
									<Notice status="warning" isDismissible={ false }>{ kbError }</Notice>
								) }
								{ kbItems === null && (
									<div className="agentic-wizard-loading"><Spinner />{ ' ' }{ __( 'Loading your content…', 'agent-builder' ) }</div>
								) }
								{ kbItems && kbItems.length === 0 && ! kbError && (
									<p className="agentic-wizard-hint">{ __( 'No published pages or posts found.', 'agent-builder' ) }</p>
								) }
								{ kbItems && kbItems.length > 0 && (
									<div className="agentic-wizard-tools">
										{ kbItems.map( ( item ) => (
											<CheckboxControl
												key={ item.id }
												label={ item.title + ' (' + item.type + ( item.trained ? ', ' + __( 'trained', 'agent-builder' ) : '' ) + ')' }
												checked={ selectedPosts.includes( item.id ) }
												onChange={ () => togglePost( item.id ) }
											/>
										) ) }
									</div>
								) }
							</Fragment>
						) }
					</Fragment>
				) }

				{ step === 4 && (
					<div className="agentic-wizard-review">
						<ReviewRow
							label={ __( 'Name', 'agent-builder' ) }
							value={ `${ form.icon } ${ form.name }` }
						/>
						<ReviewRow
							label={ __( 'Identifier', 'agent-builder' ) }
							value={ form.slug }
						/>
						<ReviewRow
							label={ __( 'Description', 'agent-builder' ) }
							value={ form.description }
						/>
						<ReviewRow
							label={ __( 'Category', 'agent-builder' ) }
							value={ capitalize( form.category ) }
						/>
						<ReviewRow
							label={ __( 'Instructions', 'agent-builder' ) }
							value={
								form.system_prompt ||
								__( '(none)', 'agent-builder' )
							}
						/>
						<ReviewRow
							label={ __( 'Provider / Model', 'agent-builder' ) }
							value={ `${
								( selectedProvider && selectedProvider.name ) ||
								form.provider ||
								'—'
							} / ${ form.model || '—' }` }
						/>
						<ReviewRow
							label={ __( 'Autonomy', 'agent-builder' ) }
							value={ capitalize( form.mode ) }
						/>
						<ReviewRow
							label={ __( 'Tools', 'agent-builder' ) }
							value={
								form.tools.length
									? form.tools.join( ', ' )
									: __( '(none)', 'agent-builder' )
							}
						/>
						<ReviewRow
							label={ __( 'Knowledge', 'agent-builder' ) }
							value={ knowledgeSummary() }
						/>
					</div>
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
						{ step < STEPS.length - 1 ? (
							<Button
								variant="primary"
								onClick={ () => setStep( step + 1 ) }
								disabled={ ! canNext() }
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
								{ __( 'Create agent', 'agent-builder' ) }
							</Button>
						) }
					</FlexItem>
				</Flex>
			</CardFooter>
		</Card>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agentic-agent-wizard-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
