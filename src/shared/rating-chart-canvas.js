import {
	Chart,
	LineController,
	LineElement,
	PointElement,
	LinearScale,
	CategoryScale,
	Filler,
	Tooltip,
} from 'chart.js';

Chart.register(
	LineController,
	LineElement,
	PointElement,
	LinearScale,
	CategoryScale,
	Filler,
	Tooltip
);

/**
 * Draw (or redraw) a rating-over-time line chart into every matching
 * wrapper found under `root`. Safe to call repeatedly on the same DOM -
 * any existing Chart instance on a canvas is destroyed first, so this
 * works both for a one-off frontend render and for the block editor,
 * where it needs to re-run every time ServerSideRender updates the
 * preview markup (e.g. after a settings change).
 *
 * @param {ParentNode} root Element to search within. Defaults to the whole document.
 */
export function drawRatingCharts( root = document ) {
	const wraps = root.querySelectorAll
		? root.querySelectorAll( '[data-ecf-rating-chart]' )
		: [];

	wraps.forEach( ( wrap ) => {
		const dataEl = wrap.querySelector( 'script[type="application/json"]' );
		const canvas = wrap.querySelector( 'canvas' );
		if ( ! dataEl || ! canvas ) {
			return;
		}

		let payload;
		try {
			payload = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}

		const { labels, values, color, unratedLabel } = payload;

		// Guard against "Canvas is already in use" errors when this runs
		// again on the same node (editor re-renders, or a script that
		// re-initialises after a dynamic content swap).
		const existing = Chart.getChart( canvas );
		if ( existing ) {
			existing.destroy();
		}

		new Chart( canvas, {
			type: 'line',
			data: {
				labels,
				datasets: [
					{
						label: unratedLabel || 'Rating',
						data: values,
						borderColor: color || '#1e3a5f',
						backgroundColor: ( color || '#1e3a5f' ) + '22',
						tension: 0.25,
						fill: true,
						pointRadius: values.length > 60 ? 0 : 3,
						spanGaps: true,
					},
				],
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: false,
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							title: ( items ) => labels[ items[ 0 ].dataIndex ],
						},
					},
				},
				scales: {
					x: {
						ticks: { maxRotation: 45, minRotation: 0, autoSkip: true, maxTicksLimit: 12 },
					},
					y: {
						ticks: { precision: 0 },
					},
				},
			},
		} );
	} );
}
