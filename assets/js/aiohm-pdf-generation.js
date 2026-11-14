/**
 * AIOHM PDF Generation JavaScript
 * Handles PDF print functionality with user confirmation
 */

(function () {
	'use strict';

	/**
	 * Initialize PDF generation functionality
	 */
	function initPDFGeneration() {
		// Check if this is a PDF generation page
		if ( ! document.body.classList.contains( 'aiohm-pdf-output' )) {
			return;
		}

		// Show confirmation dialog and trigger print
		if (confirm( "Click OK to open print dialog to save as PDF, or Cancel to view the content." )) {
			window.print();
		}
	}

	// Initialize when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener( 'DOMContentLoaded', initPDFGeneration );
	} else {
		initPDFGeneration();
	}

	// Expose function globally for backward compatibility if needed
	window.aiohmPDFGeneration = {
		init: initPDFGeneration,
		triggerPrint: function () {
			window.print();
		}
	};

})();