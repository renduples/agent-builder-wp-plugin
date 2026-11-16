import { __ } from '@wordpress/i18n';
import { bootConfig } from '../util';

function AdminPageFooter( { footer } ) {
	const f = footer || bootConfig().footer || {};
	const docUrl = f.doc_url || 'https://agentic-plugin.com/agent-tools/';
	const supportUrl = f.support_url || 'https://agentic-plugin.com/support/';
	const policy =
		f.policy ||
		__(
			'Agents only use the tools you allow. Higher-risk actions still follow Approvals and your safety settings.',
			'siteloom'
		);

	return (
		<div className="agentic-page-footer agentic-page-footer--react">
			<span className="agentic-page-footer-left">
				{ policy && (
					<span className="agentic-page-footer-policy">
						{ policy }{ ' ' }
					</span>
				) }
				{ __( 'Need help?', 'siteloom' ) }{ ' ' }
				<a href={ supportUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Visit our Support Center', 'siteloom' ) }
				</a>
				{ ' | ' }
				<a href={ docUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Documentation', 'siteloom' ) }
				</a>
			</span>
			<span className="agentic-page-footer-right">
				<a
					href={
						f.terms_url ||
						'https://agentic-plugin.com/terms-of-service/'
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Terms of Service', 'siteloom' ) }
				</a>
				{ ' | ' }
				<a
					href={
						f.privacy_url ||
						'https://agentic-plugin.com/privacy-policy/'
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Privacy Policy', 'siteloom' ) }
				</a>
				{ ' | ' }
				<a
					href={
						f.gdpr_url || 'https://agentic-plugin.com/gdpr-policy/'
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'GDPR Policy', 'siteloom' ) }
				</a>
			</span>
		</div>
	);
}

export default AdminPageFooter;
