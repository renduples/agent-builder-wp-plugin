/**
 * Security log page: close the cleanup dialog on backdrop click.
 */
var agenticCleanupDialog = document.getElementById('agentic-cleanup-dialog');
if (agenticCleanupDialog) agenticCleanupDialog.addEventListener('click', function(e) {
	if ( e.target === this ) {
		this.classList.remove('active');
	}
});
