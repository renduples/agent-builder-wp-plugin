/**
 * Knowledge Wizard — guided "Add knowledge" flow.
 *
 * A short React island (@wordpress/element + components) that walks a
 * non-technical user through adding one piece of knowledge: pick a source
 * (paste text, upload a file, or point at existing pages/posts), give it a
 * title and tags, then save. Saving goes through
 * agentic/v1/knowledge-wizard/save, which writes the same OKF concept format
 * the classic Wiki editor uses — this is a friendlier front door to it, not
 * a separate knowledge system.
 */
import { createRoot, useState, useEffect, useRef, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardBody,
	CardHeader,
	CardFooter,
	Button,
	TextControl,
	TextareaControl,
	Notice,
	Spinner,
	CheckboxControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const CFG = ( typeof window !== 'undefined' && window.agenticKnowledgeWizard ) || {};

const SOURCES = [
	{ key: 'paste', label: __( 'Paste text', 'agent-builder' ) },
	{ key: 'upload', label: __( 'Upload a file', 'agent-builder' ) },
	{ key: 'pages', label: __( 'Pick existing pages', 'agent-builder' ) },
];

const STEPS = [
	{ key: 'source', label: __( 'Source', 'agent-builder' ) },
	{ key: 'details', label: __( 'Title & tags', 'agent-builder' ) },
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
	const [ step, setStep ] = useState( 0 );
	const [ source, setSource ] = useState( 'paste' );
	const [ text, setText ] = useState( '' );
	const [ fileName, setFileName ] = useState( '' );
	const [ fileError, setFileError ] = useState( '' );

	const [ pageQuery, setPageQuery ] = useState( '' );
	const [ pageResults, setPageResults ] = useState( null );
	const [ pageError, setPageError ] = useState( '' );
	const [ selectedPageIds, setSelectedPageIds ] = useState( [] );
	const searchTimer = useRef( null );

	const [ title, setTitle ] = useState( '' );
	const [ tags, setTags ] = useState( '' );

	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ done, setDone ] = useState( null );

	useEffect( () => {
		if ( source !== 'pages' ) {
			return;
		}
		if ( searchTimer.current ) {
			clearTimeout( searchTimer.current );
		}
		searchTimer.current = setTimeout( () => {
			apiFetch( {
				path:
					'agentic/v1/knowledge-wizard/search-pages?q=' +
					encodeURIComponent( pageQuery ),
			} )
				.then( ( res ) => setPageResults( res.pages || [] ) )
				.catch( ( err ) =>
					setPageError(
						err.message ||
							__( 'Could not load pages.', 'agent-builder' )
					)
				);
		}, 300 );
		return () => clearTimeout( searchTimer.current );
	}, [ source, pageQuery ] );

	const togglePage = ( id ) =>
		setSelectedPageIds( ( prev ) =>
			prev.includes( id )
				? prev.filter( ( x ) => x !== id )
				: [ ...prev, id ]
		);

	const readFile = ( file ) => {
		setFileError( '' );
		if ( ! file ) {
			return;
		}
		const okExt = /\.(txt|md|markdown)$/i.test( file.name );
		if ( ! okExt ) {
			setFileError(
				__(
					'Please choose a plain text or Markdown file (.txt or .md).',
					'agent-builder'
				)
			);
			return;
		}
		const reader = new FileReader();
		reader.onload = () => {
			setText( String( reader.result || '' ) );
			setFileName( file.name );
			if ( ! title.trim() ) {
				setTitle( file.name.replace( /\.(txt|md|markdown)$/i, '' ) );
			}
		};
		reader.onerror = () =>
			setFileError(
				__( 'Could not read that file.', 'agent-builder' )
			);
		reader.readAsText( file );
	};

	const canLeaveSource = () => {
		if ( source === 'paste' ) {
			return text.trim() !== '';
		}
		if ( source === 'upload' ) {
			return text.trim() !== '' && ! fileError;
		}
		return selectedPageIds.length > 0;
	};

	const canSave = () => title.trim() !== '' && ! submitting;

	const submit = () => {
		if ( ! canSave() ) {
			return;
		}
		setSubmitting( true );
		setError( '' );
		const data = {
			source,
			title,
			tags: tags
				.split( ',' )
				.map( ( t ) => t.trim() )
				.filter( Boolean ),
		};
		if ( source === 'pages' ) {
			data.page_ids = selectedPageIds;
		} else {
			data.text = text;
		}
		apiFetch( {
			path: 'agentic/v1/knowledge-wizard/save',
			method: 'POST',
			data,
		} )
			.then( ( res ) => setDone( res ) )
			.catch( ( err ) =>
				setError(
					err.message ||
						__(
							'Something went wrong saving this knowledge.',
							'agent-builder'
						)
				)
			)
			.finally( () => setSubmitting( false ) );
	};

	if ( done ) {
		return (
			<Card className="agentic-wizard-card">
				<CardBody>
					<h2 className="agentic-wizard-success">
						{ '📚 ' }
						{ sprintf(
							/* translators: %s: the knowledge title. */
							__( '"%s" is saved!', 'agent-builder' ),
							title
						) }
					</h2>
					<p>
						{ __(
							'Your agents can now use this. Add another, or review it in the full Knowledge Wiki.',
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
									setSource( 'paste' );
									setText( '' );
									setFileName( '' );
									setSelectedPageIds( [] );
									setTitle( '' );
									setTags( '' );
								} }
							>
								{ __( 'Add another', 'agent-builder' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="secondary"
								href={
									done.knowledge_url || CFG.knowledgeUrl
								}
							>
								{ __( 'View Knowledge Wiki', 'agent-builder' ) }
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
						<p className="agentic-wizard-sublabel">
							{ __( 'Where is this knowledge coming from?', 'agent-builder' ) }
						</p>
						<Flex gap={ 2 } wrap justify="flex-start" className="agentic-mb-12">
							{ SOURCES.map( ( s ) => (
								<FlexItem key={ s.key }>
									<Button
										variant={
											source === s.key
												? 'primary'
												: 'secondary'
										}
										onClick={ () => setSource( s.key ) }
									>
										{ s.label }
									</Button>
								</FlexItem>
							) ) }
						</Flex>

						{ source === 'paste' && (
							<TextareaControl
								label={ __( 'Paste the text', 'agent-builder' ) }
								help={ __(
									'A fact, policy, FAQ answer — anything you want your agents to know is true.',
									'agent-builder'
								) }
								value={ text }
								onChange={ setText }
								rows={ 10 }
							/>
						) }

						{ source === 'upload' && (
							<Fragment>
								<label
									className="agentic-wizard-sublabel"
									htmlFor="agentic-knowledge-wizard-file"
								>
									{ __( 'Upload a text or Markdown file', 'agent-builder' ) }
								</label>
								<input
									id="agentic-knowledge-wizard-file"
									type="file"
									accept=".txt,.md,.markdown,text/plain,text/markdown"
									onChange={ ( e ) =>
										readFile( e.target.files && e.target.files[ 0 ] )
									}
								/>
								{ fileError && (
									<Notice status="error" isDismissible={ false }>
										{ fileError }
									</Notice>
								) }
								{ fileName && ! fileError && (
									<p className="agentic-wizard-hint">
										{ sprintf(
											/* translators: %s: file name. */
											__( 'Loaded "%s" — you can review and edit the text below.', 'agent-builder' ),
											fileName
										) }
									</p>
								) }
								{ text && (
									<TextareaControl
										label={ __( 'File contents', 'agent-builder' ) }
										value={ text }
										onChange={ setText }
										rows={ 8 }
									/>
								) }
							</Fragment>
						) }

						{ source === 'pages' && (
							<Fragment>
								<TextControl
									label={ __( 'Search your pages and posts', 'agent-builder' ) }
									value={ pageQuery }
									onChange={ setPageQuery }
									placeholder={ __( 'Leave blank to see recent pages', 'agent-builder' ) }
									__next40pxDefaultSize
								/>
								{ pageError && (
									<Notice status="warning" isDismissible={ false }>
										{ pageError }
									</Notice>
								) }
								{ pageResults === null && ! pageError && (
									<div className="agentic-wizard-loading">
										<Spinner />{ ' ' }
										{ __( 'Loading…', 'agent-builder' ) }
									</div>
								) }
								{ pageResults && pageResults.length === 0 && (
									<p className="agentic-wizard-hint">
										{ __( 'No matching pages or posts.', 'agent-builder' ) }
									</p>
								) }
								{ pageResults && pageResults.length > 0 && (
									<div className="agentic-wizard-tools">
										{ pageResults.map( ( p ) => (
											<CheckboxControl
												key={ p.id }
												label={ p.title + ' (' + p.type + ')' }
												help={ p.excerpt }
												checked={ selectedPageIds.includes( p.id ) }
												onChange={ () => togglePage( p.id ) }
											/>
										) ) }
									</div>
								) }
								{ selectedPageIds.length > 0 && (
									<p className="agentic-wizard-hint">
										{ sprintf(
											/* translators: %s: number of selected pages. */
											__( '%s page(s)/post(s) selected.', 'agent-builder' ),
											String( selectedPageIds.length )
										) }
									</p>
								) }
							</Fragment>
						) }
					</Fragment>
				) }

				{ step === 1 && (
					<Fragment>
						<TextControl
							label={ __( 'Title', 'agent-builder' ) }
							help={ __(
								'A short, clear name, e.g. "Return policy" or "Support hours".',
								'agent-builder'
							) }
							value={ title }
							onChange={ setTitle }
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __( 'Tags (optional)', 'agent-builder' ) }
							help={ __(
								'Comma-separated, e.g. billing, returns, policy.',
								'agent-builder'
							) }
							value={ tags }
							onChange={ setTags }
							__next40pxDefaultSize
						/>
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
								disabled={ ! canLeaveSource() }
							>
								{ __( 'Continue', 'agent-builder' ) }
							</Button>
						) : (
							<Button
								variant="primary"
								onClick={ submit }
								isBusy={ submitting }
								disabled={ ! canSave() }
							>
								{ __( 'Save knowledge', 'agent-builder' ) }
							</Button>
						) }
					</FlexItem>
				</Flex>
			</CardFooter>
		</Card>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agentic-knowledge-wizard-root' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
