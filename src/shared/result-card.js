/**
 * Dumb result-card renderer shared by the React chat embed and the Tasks drawer.
 *
 * Mirrors assets/js/chat.js's renderResultCard() for the vanilla chat surfaces,
 * drawing from the same Result_Card contract (see includes/class-result-card.php):
 * a run/approval that produced a post, file, or list result normalizes to a small
 * card here. Unknown card types render nothing.
 */
import { __ } from '@wordpress/i18n';

const CARD_STYLE = {
	display: 'flex',
	gap: '8px',
	alignItems: 'flex-start',
	padding: '8px 10px',
	marginTop: '8px',
	background: 'rgba(0, 0, 0, 0.03)',
	borderRadius: '6px',
	fontSize: '13px',
};

const LINK_STYLE = {
	marginTop: '2px',
	display: 'flex',
	gap: '8px',
	flexWrap: 'wrap',
};

const ITEMS_STYLE = {
	margin: '4px 0 0',
	paddingLeft: '18px',
};

function postHeading( card ) {
	const verb = 'updated' === card.action ? __( 'Updated', 'agent-builder' ) : __( 'Created', 'agent-builder' );
	return verb + ' ' + __( 'a post', 'agent-builder' ) + ( card.title ? ' “' + card.title + '”' : '' );
}

function ResultCard( { card } ) {
	if ( ! card || ! card.type ) {
		return null;
	}

	const icon = 'post' === card.type ? '📝' : 'file' === card.type ? '📄' : '📋';
	let heading;
	let detail = null;

	if ( 'post' === card.type ) {
		heading = postHeading( card );
		if ( card.view_url || card.edit_url ) {
			detail = (
				<div style={ LINK_STYLE }>
					{ card.view_url && (
						<a href={ card.view_url } target="_blank" rel="noopener">
							{ __( 'View', 'agent-builder' ) }
						</a>
					) }
					{ card.edit_url && (
						<a href={ card.edit_url } target="_blank" rel="noopener">
							{ __( 'Edit', 'agent-builder' ) }
						</a>
					) }
				</div>
			);
		}
	} else if ( 'file' === card.type ) {
		heading =
			__( 'Generated a file', 'agent-builder' ) +
			( card.title ? ' “' + card.title + '”' : '' );
		if ( card.url ) {
			detail = (
				<div style={ LINK_STYLE }>
					<a href={ card.url } target="_blank" rel="noopener">
						{ __( 'Download', 'agent-builder' ) }
					</a>
				</div>
			);
		}
	} else if ( 'list' === card.type ) {
		heading =
			__( 'Listed', 'agent-builder' ) +
			' ' +
			( card.count || 0 ) +
			' ' +
			( card.title || __( 'items', 'agent-builder' ) ).toLowerCase();
		const items = ( card.items || [] ).slice( 0, 5 );
		if ( items.length ) {
			detail = (
				<ul style={ ITEMS_STYLE }>
					{ items.map( ( item ) => (
						<li key={ item.id }>{ item.title }</li>
					) ) }
				</ul>
			);
		}
	} else {
		return null;
	}

	return (
		<div className={ 'agentic-result-card agentic-result-card--' + card.type } style={ CARD_STYLE }>
			<span className="agentic-result-card__icon" aria-hidden="true">
				{ icon }
			</span>
			<div className="agentic-result-card__body">
				<strong>{ heading }</strong>
				{ detail }
			</div>
		</div>
	);
}

/**
 * Render a list of result cards, or null when there are none.
 */
export function ResultCards( { cards } ) {
	if ( ! cards || ! cards.length ) {
		return null;
	}
	return (
		<div className="agentic-result-cards">
			{ cards.map( ( card, i ) => (
				<ResultCard key={ card.post_id || card.path || card.title || i } card={ card } />
			) ) }
		</div>
	);
}

export default ResultCard;
