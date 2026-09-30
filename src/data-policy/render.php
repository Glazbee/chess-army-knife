<?php
/**
 * Server-side render for the Club Data Policy block: how the club handles
 * members' personal data, from the same wording as the suggested privacy
 * policy text (see Chess_Army_Knife_Membership_Privacy::policy_sections()).
 *
 * @package Chess_Army_Knife
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$block_title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
?>
<div <?php echo wp_kses_post( get_block_wrapper_attributes() ); ?>>
	<?php echo Chess_Army_Knife_A11y::heading( 0, 'cak-data-policy__heading', '' !== $block_title ? $block_title : __( 'How we handle your data', 'chess-army-knife' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
	<?php foreach ( Chess_Army_Knife_Membership_Privacy::policy_sections() as $policy_section ) : ?>
		<?php echo Chess_Army_Knife_A11y::heading( 1, 'cak-data-policy__section', $policy_section['heading'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in heading(). ?>
		<?php foreach ( $policy_section['paragraphs'] as $policy_paragraph ) : ?>
			<p><?php echo esc_html( $policy_paragraph ); ?></p>
		<?php endforeach; ?>
	<?php endforeach; ?>
</div>
