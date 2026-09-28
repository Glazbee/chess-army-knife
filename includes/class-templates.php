<?php
/**
 * Templates: named, reusable presets for a block type.
 *
 * A template stores a set of block settings (rating list, days to look
 * back, show match location...) plus styling (accent/background/text
 * colours, corner radius, custom CSS). A block picks a template in its
 * sidebar; at render time any value the template defines overrides the
 * block's own value, and anything the template leaves blank is inherited
 * from the block. Editing a template therefore updates every block that
 * uses it.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Templates {

	const OPTION = 'Chess_Army_Knife_templates';

	/**
	 * Boot admin page + handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_ecf_lms_save_template', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ecf_lms_delete_template', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Block types that can have templates (slug => label).
	 *
	 * @return array
	 */
	public static function block_types() {
		return array(
			'rating-chart'    => __( 'ECF Rating Chart', 'chess-army-knife' ),
			'club-results'    => __( 'ECF Club Results', 'chess-army-knife' ),
			'league-table'    => __( 'ECF League Standings & Matchups', 'chess-army-knife' ),
			'team-carousel'   => __( 'ECF Team Fixtures Carousel', 'chess-army-knife' ),
			'biggest-gainers' => __( 'ECF Biggest Rating Gainers', 'chess-army-knife' ),
			'featured-player' => __( 'ECF Featured Player', 'chess-army-knife' ),
		);
	}

	/**
	 * Field definitions for a block type: the settings it can preset,
	 * followed by the styling fields common to every block.
	 *
	 * Field keys in the 'settings' group are the block attribute names.
	 * Every field is optional: blank means "inherit from the block".
	 *
	 * @param string $slug Block slug.
	 * @return array[] Each: key, label, type, group, [options], [min], [max], [help].
	 */
	public static function fields( $slug ) {
		$bool   = array(
			''  => __( '— Use block setting —', 'chess-army-knife' ),
			'1' => __( 'Yes', 'chess-army-knife' ),
			'0' => __( 'No', 'chess-army-knife' ),
		);
		$domain = array(
			''   => __( '— Use block setting —', 'chess-army-knife' ),
			'S'  => __( 'Standard (OTB)', 'chess-army-knife' ),
			'R'  => __( 'Rapid (OTB)', 'chess-army-knife' ),
			'B'  => __( 'Blitz (OTB)', 'chess-army-knife' ),
			'SW' => __( 'Online Standard', 'chess-army-knife' ),
			'RW' => __( 'Online Rapid', 'chess-army-knife' ),
			'BW' => __( 'Online Blitz', 'chess-army-knife' ),
		);

		$s = array();

		switch ( $slug ) {
			case 'rating-chart':
				$s[] = array(
					'key'     => 'domain',
					'label'   => __( 'Rating list', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $domain,
				);
				$s[] = array(
					'key'   => 'gamesLimit',
					'label' => __( 'Games to include', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 10,
					'max'   => 300,
				);
				$s[] = array(
					'key'   => 'height',
					'label' => __( 'Chart height (px)', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 120,
					'max'   => 800,
				);
				$s[] = array(
					'key'     => 'showStats',
					'label'   => __( 'Show summary stats', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				break;
			case 'club-results':
				$s[] = array(
					'key'     => 'domain',
					'label'   => __( 'Rating list', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => array_slice( $domain, 0, 4, true ),
				);
				$s[] = array(
					'key'   => 'daysBack',
					'label' => __( 'Days to look back', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 1,
					'max'   => 365,
				);
				$s[] = array(
					'key'   => 'maxResults',
					'label' => __( 'Max results shown', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 1,
					'max'   => 100,
				);
				$s[] = array(
					'key'     => 'showOpponentRating',
					'label'   => __( "Show opponents' ratings", 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				$s[] = array(
					'key'     => 'showEvent',
					'label'   => __( 'Show event column', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				break;
			case 'league-table':
				$s[] = array(
					'key'     => 'displayMode',
					'label'   => __( 'Show', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => array(
						''        => __( '— Use block setting —', 'chess-army-knife' ),
						'both'    => __( 'Table + matchups', 'chess-army-knife' ),
						'table'   => __( 'Table only', 'chess-army-knife' ),
						'matches' => __( 'Matchups only', 'chess-army-knife' ),
					),
				);
				$s[] = array(
					'key'   => 'maxMatches',
					'label' => __( 'Matchups to show', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 1,
					'max'   => 50,
				);
				$s[] = array(
					'key'     => 'showLocation',
					'label'   => __( 'Show match location/venue', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
					'help'    => __( 'Only shown when the LMS supplies a venue for the match.', 'chess-army-knife' ),
				);
				$s[] = array(
					'key'   => 'highlightTeam',
					'label' => __( 'Highlight team', 'chess-army-knife' ),
					'type'  => 'text',
				);
				break;
			case 'team-carousel':
				$s[] = array(
					'key'     => 'autoAdvance',
					'label'   => __( 'Auto-advance', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				$s[] = array(
					'key'   => 'intervalSeconds',
					'label' => __( 'Seconds per slide', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 3,
					'max'   => 30,
				);
				$s[] = array(
					'key'     => 'showLocation',
					'label'   => __( 'Show match location/venue', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
					'help'    => __( 'Only shown when the LMS supplies a venue for the match.', 'chess-army-knife' ),
				);
				$s[] = array(
					'key'   => 'highlightTeam',
					'label' => __( 'Highlight team', 'chess-army-knife' ),
					'type'  => 'text',
				);
				break;
			case 'biggest-gainers':
				$s[] = array(
					'key'     => 'domain',
					'label'   => __( 'Rating list', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => array_slice( $domain, 0, 4, true ),
				);
				$s[] = array(
					'key'   => 'daysBack',
					'label' => __( 'Days to look back', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 1,
					'max'   => 365,
				);
				$s[] = array(
					'key'   => 'topCount',
					'label' => __( 'Players to show', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 1,
					'max'   => 50,
				);
				$s[] = array(
					'key'   => 'minGames',
					'label' => __( 'Minimum games to qualify', 'chess-army-knife' ),
					'type'  => 'number',
					'min'   => 1,
					'max'   => 20,
				);
				$s[] = array(
					'key'     => 'showDetail',
					'label'   => __( 'Show "from → to" rating detail', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				break;
			case 'featured-player':
				$s[] = array(
					'key'   => 'heading',
					'label' => __( 'Heading', 'chess-army-knife' ),
					'type'  => 'text',
					'help'  => __( 'e.g. "Player of the month".', 'chess-army-knife' ),
				);
				$s[] = array(
					'key'     => 'domain',
					'label'   => __( 'Rating list', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $domain,
				);
				$s[] = array(
					'key'     => 'showRating',
					'label'   => __( 'Show current rating', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				$s[] = array(
					'key'     => 'showClub',
					'label'   => __( 'Show club', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				$s[] = array(
					'key'     => 'showLinks',
					'label'   => __( 'Show chess.com / Lichess links', 'chess-army-knife' ),
					'type'    => 'select',
					'options' => $bool,
				);
				break;
		}

		foreach ( $s as &$field ) {
			$field['group'] = 'settings';
		}
		unset( $field );

		$style = array(
			array(
				'key'   => 'accent',
				'label' => __( 'Accent colour', 'chess-army-knife' ),
				'type'  => 'color',
				'help'  => __( 'Titles, table highlights, carousel dots and chart lines.', 'chess-army-knife' ),
			),
			array(
				'key'   => 'bg',
				'label' => __( 'Background colour', 'chess-army-knife' ),
				'type'  => 'color',
			),
			array(
				'key'   => 'text',
				'label' => __( 'Text colour', 'chess-army-knife' ),
				'type'  => 'color',
			),
			array(
				'key'   => 'radius',
				'label' => __( 'Corner radius (px)', 'chess-army-knife' ),
				'type'  => 'number',
				'min'   => 0,
				'max'   => 40,
			),
			array(
				'key'   => 'custom_css',
				'label' => __( 'Custom CSS', 'chess-army-knife' ),
				'type'  => 'textarea',
				'help'  => __( 'Use {block} to target blocks using this template, e.g. {block} th { text-transform: uppercase; }', 'chess-army-knife' ),
			),
		);
		foreach ( $style as &$field ) {
			$field['group'] = 'style';
		}
		unset( $field );

		return array_merge( $s, $style );
	}

	/**
	 * All templates, keyed by id.
	 *
	 * @return array
	 */
	public static function get_all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * One template by id, or null.
	 *
	 * @param string $id Template id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::get_all();
		return ( '' !== $id && isset( $all[ $id ] ) ) ? $all[ $id ] : null;
	}

	/**
	 * Templates available for one block type, as [ {id, name} ].
	 *
	 * @param string $slug Block slug.
	 * @return array
	 */
	public static function list_for_block( $slug ) {
		$out = array();
		foreach ( self::get_all() as $tpl ) {
			if ( isset( $tpl['block'] ) && $tpl['block'] === $slug ) {
				$out[] = array(
					'id'   => $tpl['id'],
					'name' => $tpl['name'],
				);
			}
		}
		return $out;
	}

	/**
	 * Merge a template's settings over a block's attributes. Called at the
	 * top of each block's render.php.
	 *
	 * @param string $slug       Block slug.
	 * @param array  $attributes Block attributes.
	 * @return array Attributes with template values applied.
	 */
	public static function apply( $slug, $attributes ) {
		$tpl = self::get( isset( $attributes['templateId'] ) ? (string) $attributes['templateId'] : '' );
		if ( ! $tpl || $tpl['block'] !== $slug ) {
			return $attributes;
		}

		$values = isset( $tpl['values'] ) ? $tpl['values'] : array();

		foreach ( self::fields( $slug ) as $field ) {
			if ( 'settings' !== $field['group'] || ! isset( $values[ $field['key'] ] ) || '' === $values[ $field['key'] ] ) {
				continue;
			}
			$v = $values[ $field['key'] ];
			if ( 'select' === $field['type'] && isset( $field['options'] ) && isset( $field['options']['1'], $field['options']['0'] ) && 3 === count( $field['options'] ) ) {
				$v = ( '1' === (string) $v ); // Yes/No fields become booleans.
			} elseif ( 'number' === $field['type'] ) {
				$v = $v + 0;
			}
			$attributes[ $field['key'] ] = $v;
		}

		// The accent colour doubles as the rating chart's line colour.
		if ( 'rating-chart' === $slug && ! empty( $values['accent'] ) ) {
			$attributes['lineColor'] = $values['accent'];
		}

		return $attributes;
	}

	/**
	 * Wrapper attributes for a block, including the template's colour
	 * variables, radius and scoping class. Use in place of a bare
	 * get_block_wrapper_attributes().
	 *
	 * @param string $slug       Block slug.
	 * @param array  $attributes Block attributes (with templateId).
	 * @return string
	 */
	public static function wrapper_attributes( $slug, $attributes ) {
		$tpl = self::get( isset( $attributes['templateId'] ) ? (string) $attributes['templateId'] : '' );
		if ( ! $tpl || $tpl['block'] !== $slug ) {
			return get_block_wrapper_attributes();
		}

		$v     = isset( $tpl['values'] ) ? $tpl['values'] : array();
		$style = array();

		if ( ! empty( $v['accent'] ) && sanitize_hex_color( $v['accent'] ) ) {
			$style[] = '--ecf-accent:' . $v['accent'];
			$style[] = '--ecf-accent-soft:' . $v['accent'] . ( 7 === strlen( $v['accent'] ) ? '22' : '' );
		}
		if ( ! empty( $v['bg'] ) && sanitize_hex_color( $v['bg'] ) ) {
			$style[] = 'background-color:' . $v['bg'];
			$style[] = 'padding:1em';
		}
		if ( ! empty( $v['text'] ) && sanitize_hex_color( $v['text'] ) ) {
			$style[] = 'color:' . $v['text'];
		}
		if ( isset( $v['radius'] ) && '' !== $v['radius'] ) {
			$style[] = 'border-radius:' . (int) $v['radius'] . 'px';
			if ( ! empty( $v['bg'] ) ) {
				$style[] = 'overflow:hidden';
			}
		}

		return get_block_wrapper_attributes(
			array(
				'class' => 'ecf-tpl-' . sanitize_html_class( $tpl['id'] ),
				'style' => implode( ';', $style ),
			)
		);
	}

	/**
	 * The template's custom CSS as a <style> element (once per template
	 * per page), with {block} replaced by the template's scoping class.
	 *
	 * @param array $attributes Block attributes (with templateId).
	 * @return string
	 */
	public static function custom_css( $attributes ) {
		static $printed = array();

		$tpl = self::get( isset( $attributes['templateId'] ) ? (string) $attributes['templateId'] : '' );
		if ( ! $tpl || empty( $tpl['values']['custom_css'] ) || isset( $printed[ $tpl['id'] ] ) ) {
			return '';
		}
		$printed[ $tpl['id'] ] = true;

		$css = str_replace( '{block}', '.ecf-tpl-' . sanitize_html_class( $tpl['id'] ), $tpl['values']['custom_css'] );

		return '<style id="ecf-tpl-css-' . esc_attr( sanitize_html_class( $tpl['id'] ) ) . '">' . wp_strip_all_tags( $css ) . '</style>';
	}

	/**
	 * Add the Templates submenu.
	 */
	public static function add_menu() {
		add_submenu_page(
			'chess-army-knife',
			__( 'Templates', 'chess-army-knife' ),
			__( 'Templates', 'chess-army-knife' ),
			'manage_options',
			'chess-army-knife-templates',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Save (create or update) a template.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ecf_lms_save_template' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		$types = self::block_types();
		$slug  = isset( $_POST['block'] ) ? sanitize_key( wp_unslash( $_POST['block'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

		if ( ! isset( $types[ $slug ] ) || '' === $name ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => 'chess-army-knife-templates',
						'ecf_lms_tpl_error' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$raw    = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? wp_unslash( $_POST['values'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$values = array();

		foreach ( self::fields( $slug ) as $field ) {
			$key = $field['key'];
			if ( ! isset( $raw[ $key ] ) || '' === trim( (string) $raw[ $key ] ) ) {
				continue;
			}
			$val = (string) $raw[ $key ];

			switch ( $field['type'] ) {
				case 'select':
					if ( isset( $field['options'][ $val ] ) ) {
						$values[ $key ] = $val;
					}
					break;
				case 'number':
					$n              = (float) $val;
					$n              = isset( $field['min'] ) ? max( $field['min'], $n ) : $n;
					$n              = isset( $field['max'] ) ? min( $field['max'], $n ) : $n;
					$values[ $key ] = ( floor( $n ) === $n ) ? (int) $n : $n;
					break;
				case 'color':
					$c = sanitize_hex_color( $val );
					if ( $c ) {
						$values[ $key ] = $c;
					}
					break;
				case 'textarea':
					$css            = wp_strip_all_tags( $val );
					$css            = preg_replace( '/@import[^;]*;?/i', '', $css );
					$css            = preg_replace( '/expression\s*\(|javascript\s*:|behavior\s*:/i', '', $css );
					$values[ $key ] = mb_substr( $css, 0, 5000 );
					break;
				default:
					$values[ $key ] = sanitize_text_field( $val );
			}
		}

		$all = self::get_all();
		if ( '' === $id || ! isset( $all[ $id ] ) ) {
			$id = 'tpl' . strtolower( wp_generate_password( 8, false ) );
		}
		$all[ $id ] = array(
			'id'     => $id,
			'name'   => $name,
			'block'  => $slug,
			'values' => $values,
		);
		update_option( self::OPTION, $all );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'chess-army-knife-templates',
					'ecf_lms_tpl_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Delete a template.
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ecf_lms_delete_template' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'chess-army-knife' ) );
		}

		$id  = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$all = self::get_all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'chess-army-knife-templates',
					'ecf_lms_tpl_deleted' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Templates admin page (list, or the edit form).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $action || 'new' === $action ) {
			self::render_form( $action );
			return;
		}

		$types = self::block_types();
		$all   = self::get_all();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Templates', 'chess-army-knife' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'A template is a reusable preset for a block type: its settings plus its look (colours, corner radius, custom CSS, whether match locations show). Pick a template in any block\'s sidebar; anything the template sets overrides that block, so editing a template updates every block using it.', 'chess-army-knife' ); ?>
			</p>

			<?php if ( isset( $_GET['ecf_lms_tpl_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template saved.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( isset( $_GET['ecf_lms_tpl_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template deleted.', 'chess-army-knife' ); ?></p></div>
			<?php elseif ( isset( $_GET['ecf_lms_tpl_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'A template needs a name and a block type.', 'chess-army-knife' ); ?></p></div>
			<?php endif; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:1em 0;">
				<input type="hidden" name="page" value="chess-army-knife-templates" />
				<input type="hidden" name="action" value="new" />
				<select name="block">
					<?php foreach ( $types as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Add template', 'chess-army-knife' ), 'primary', '', false ); ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Block', 'chess-army-knife' ); ?></th>
						<th><?php esc_html_e( 'Overrides', 'chess-army-knife' ); ?></th>
						<th style="width:140px;"><?php esc_html_e( 'Actions', 'chess-army-knife' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $all ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No templates yet.', 'chess-army-knife' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $all as $tpl ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $tpl['name'] ); ?></strong></td>
								<td><?php echo esc_html( isset( $types[ $tpl['block'] ] ) ? $types[ $tpl['block'] ] : $tpl['block'] ); ?></td>
								<td><?php echo (int) count( $tpl['values'] ); ?></td>
								<td>
									<a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page'   => 'chess-army-knife-templates',
												'action' => 'edit',
												'id'     => $tpl['id'],
											),
											admin_url( 'admin.php' )
										)
									);
									?>
												"><?php esc_html_e( 'Edit', 'chess-army-knife' ); ?></a> |
									<a
										href="
										<?php
										echo esc_url(
											wp_nonce_url(
												add_query_arg(
													array(
														'action' => 'ecf_lms_delete_template',
														'id' => $tpl['id'],
													),
													admin_url( 'admin-post.php' )
												),
												'ecf_lms_delete_template'
											)
										);
										?>
												"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this template? Blocks using it will fall back to their own settings.', 'chess-army-knife' ) ); ?>');"
									><?php esc_html_e( 'Delete', 'chess-army-knife' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the create/edit form for one template.
	 *
	 * @param string $action 'new' or 'edit'.
	 */
	protected static function render_form( $action ) {
		$types = self::block_types();
		$tpl   = null;

		if ( 'edit' === $action ) {
			$tpl = self::get( isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$slug = $tpl ? $tpl['block'] : ( isset( $_GET['block'] ) ? sanitize_key( wp_unslash( $_GET['block'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $types[ $slug ] ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Unknown block type.', 'chess-army-knife' ) . '</p></div>';
			return;
		}

		$values = $tpl ? $tpl['values'] : array();
		$fields = self::fields( $slug );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $tpl ? __( 'Edit template', 'chess-army-knife' ) : __( 'New template', 'chess-army-knife' ) ); ?> — <?php echo esc_html( $types[ $slug ] ); ?></h1>
			<p class="description"><?php esc_html_e( 'Leave any field blank to let the block keep its own setting.', 'chess-army-knife' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ecf_lms_save_template" />
				<input type="hidden" name="block" value="<?php echo esc_attr( $slug ); ?>" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $tpl ? $tpl['id'] : '' ); ?>" />
				<?php wp_nonce_field( 'ecf_lms_save_template' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tpl_name"><?php esc_html_e( 'Template name', 'chess-army-knife' ); ?></label></th>
						<td><input type="text" id="tpl_name" name="name" class="regular-text" required value="<?php echo esc_attr( $tpl ? $tpl['name'] : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Home page — compact', 'chess-army-knife' ); ?>" /></td>
					</tr>
				</table>

				<?php
				foreach ( array(
					'settings' => __( 'Block settings', 'chess-army-knife' ),
					'style'    => __( 'Appearance', 'chess-army-knife' ),
				) as $group => $heading ) :
					?>
					<h2><?php echo esc_html( $heading ); ?></h2>
					<table class="form-table" role="presentation">
																													<?php foreach ( $fields as $field ) : ?>
																														<?php
																														if ( $field['group'] !== $group ) {
																															continue;
																														}
																														$current = isset( $values[ $field['key'] ] ) ? $values[ $field['key'] ] : '';
																														$name    = 'values[' . $field['key'] . ']';
																														$id      = 'f_' . $field['key'];
																														?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
								<td>
																														<?php if ( 'select' === $field['type'] ) : ?>
										<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
																															<?php foreach ( $field['options'] as $val => $label ) : ?>
												<option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) $current, (string) $val ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php elseif ( 'number' === $field['type'] ) : ?>
										<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="small-text" value="<?php echo esc_attr( $current ); ?>" min="<?php echo esc_attr( $field['min'] ); ?>" max="<?php echo esc_attr( $field['max'] ); ?>" />
									<?php elseif ( 'color' === $field['type'] ) : ?>
										<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="chess-army-knife-color" value="<?php echo esc_attr( $current ); ?>" placeholder="#1e3a5f" maxlength="7" size="9" />
										<input type="color" value="<?php echo esc_attr( $current ? $current : '#1e3a5f' ); ?>" oninput="document.getElementById('<?php echo esc_js( $id ); ?>').value=this.value;" aria-label="<?php echo esc_attr( $field['label'] ); ?>" />
									<?php elseif ( 'textarea' === $field['type'] ) : ?>
										<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="6" class="large-text code"><?php echo esc_textarea( $current ); ?></textarea>
									<?php else : ?>
										<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text" value="<?php echo esc_attr( $current ); ?>" />
									<?php endif; ?>
																														<?php if ( ! empty( $field['help'] ) ) : ?>
										<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>

				<?php submit_button( $tpl ? __( 'Save template', 'chess-army-knife' ) : __( 'Create template', 'chess-army-knife' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=chess-army-knife-templates' ) ); ?>"><?php esc_html_e( 'Back to templates', 'chess-army-knife' ); ?></a>
			</form>
		</div>
		<?php
	}
}

Chess_Army_Knife_Templates::init();
