<?php
/**
 * Server-side render for the Club Officers block: each position with the name
 * of whoever holds it now, and team captains. Only positions and names are
 * shown, and when they began if the block is set to.
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$attributes   = Chess_Army_Knife_Templates::apply( 'officers', $attributes );
$block_title  = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
$show_tenure  = ! empty( $attributes['showTenure'] );
$with_captain = ! empty( $attributes['includeCaptains'] );
$show_title   = ! isset( $attributes['showTitle'] ) || $attributes['showTitle'];
$columns      = isset( $attributes['columns'] ) ? max( 1, min( 4, absint( $attributes['columns'] ) ) ) : 1;
$stacked      = isset( $attributes['layout'] ) && 'stacked' === $attributes['layout'];
$separators   = ! isset( $attributes['showSeparators'] ) || $attributes['showSeparators'];
// 0 shows a position as bold text, 1 to 6 as that heading level, and -1 as a heading that follows the site's level.
$position_level = isset( $attributes['positionHeadingLevel'] ) ? max( -1, min( 6, (int) $attributes['positionHeadingLevel'] ) ) : 0;
$list_classes   = 'cak-officers__list' . ( $separators ? ' has-separators' : '' ) . ( $stacked ? ' is-stacked' : '' );
$since_text     = function ( $person ) use ( $show_tenure, $stacked ) {
	if ( ! $show_tenure || '' === $person['since'] ) {
		return '';
	}
	/* translators: %s: the date someone took up a position */
	$text = sprintf( esc_html__( 'since %s', 'chess-army-knife' ), Chess_Army_Knife_A11y::date( $person['since'] ) );
	return '<span class="cak-officer__tenure">' . ( $stacked ? '(' . $text . ')' : $text ) . '</span>';
};
$officers       = Chess_Army_Knife_Officers::listing(
	array(
		'order'            => isset( $attributes['order'] ) ? $attributes['order'] : array(),
		'include_captains' => $with_captain,
	)
);
?>
<?php echo Chess_Army_Knife_Templates::custom_css( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by custom_css(): the template id is escaped and the CSS has tags stripped. ?>
<?php $name_style = Chess_Army_Knife_Names::style_for( $attributes ); ?>
<div <?php echo wp_kses_post( Chess_Army_Knife_Templates::wrapper_attributes( 'officers', $attributes ) ); ?>>
	<?php if ( $show_title ) : ?>
		<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-officers__heading', '' !== $block_title ? $block_title : sprintf( /* translators: %s: the club's name */ __( '%s officers', 'chess-army-knife' ), Chess_Army_Knife_Settings::club_name() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<?php endif; ?>
	<?php if ( ! $officers ) : ?>
		<p><?php esc_html_e( 'No officers to show yet.', 'chess-army-knife' ); ?></p>
	<?php elseif ( 0 === $position_level ) : ?>
		<dl class="<?php echo esc_attr( $list_classes ); ?>" style="--cak-officer-columns:<?php echo esc_attr( $columns ); ?>">
			<?php foreach ( $officers as $officer ) : ?>
				<div class="cak-officer">
					<dt class="cak-officer__position"><?php echo esc_html( $officer['label'] ); ?></dt>
					<?php foreach ( $officer['people'] as $person ) : ?>
						<dd class="cak-officer__name">
							<?php echo esc_html( Chess_Army_Knife_Names::person( $person, $name_style ) ); ?>
							<?php
							echo wp_kses(
								$since_text( $person ),
								array(
									'span' => array( 'class' => true ),
									'time' => array( 'datetime' => true ),
								)
							);
							?>
						</dd>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</dl>
	<?php else : ?>
		<?php // A heading cannot go in a description list, so a position that is a heading uses plain elements. ?>
		<div class="<?php echo esc_attr( $list_classes ); ?>" style="--cak-officer-columns:<?php echo esc_attr( $columns ); ?>">
			<?php foreach ( $officers as $officer ) : ?>
				<div class="cak-officer">
					<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-officer__position', $officer['label'], max( 0, $position_level ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
					<?php foreach ( $officer['people'] as $person ) : ?>
						<p class="cak-officer__name">
							<?php echo esc_html( Chess_Army_Knife_Names::person( $person, $name_style ) ); ?>
							<?php
							echo wp_kses(
								$since_text( $person ),
								array(
									'span' => array( 'class' => true ),
									'time' => array( 'datetime' => true ),
								)
							);
							?>
						</p>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
