import { __ } from '@wordpress/i18n';

function TabBar( { tabs, active, className = '' } ) {
	if ( ! tabs?.length ) {
		return null;
	}
	return (
		<nav
			className={ `agentic-react-tabs ${ className }`.trim() }
			aria-label={ __( 'Sections', 'agent-builder' ) }
		>
			{ tabs.map( ( t ) => (
				<a
					key={ t.id }
					href={ t.url }
					className={
						'agentic-react-tabs__tab' +
						( t.id === active ? ' is-active' : '' )
					}
				>
					{ t.label }
				</a>
			) ) }
		</nav>
	);
}


export default TabBar;
