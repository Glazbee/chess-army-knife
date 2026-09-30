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
	<h2 class="cak-data-policy__heading"><?php echo esc_html( '' !== $block_title ? $block_title : __( 'How we handle your data', 'chess-army-knife' ) ); ?></h2>
	<?php foreach ( Chess_Army_Knife_Membership_Privacy::policy_sections() as $policy_section ) : ?>
		<h3><?php echo esc_html( $policy_section['heading'] ); ?></h3>
		<?php foreach ( $policy_section['paragraphs'] as $policy_paragraph ) : ?>
			<p><?php echo esc_html( $policy_paragraph ); ?></p>
		<?php endforeach; ?>
	<?php endforeach; ?>
</div>
