import { drawRatingCharts } from '../shared/rating-chart-canvas';

/**
 * Front-end entry point: draws every rating chart present in the page
 * once the DOM is ready. The block's render.php embeds the data points
 * as JSON next to each canvas, so this file has no API calls of its own
 * to make - it just visualises what PHP already fetched (and cached)
 * server-side.
 */
function init() {
	drawRatingCharts( document );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
