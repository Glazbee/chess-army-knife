<?php
/**
 * Unit tests for the mailer's pure parts.
 *
 * @package Chess_Army_Knife
 */

class MailerTest extends Chess_Army_Knife_TestCase {

	public function test_body_ends_with_a_footer_naming_the_kind_of_email_and_the_unsubscribe_link() {
		$body = Chess_Army_Knife_Mailer::build_body( "Hello\n\n", 'Renewal reminders', 'https://example.test/?u=1', 'Club' );

		$this->assertStringStartsWith( "Hello\n\n-- \n", $body );
		$this->assertStringContainsString( 'Renewal reminders', $body );
		$this->assertStringContainsString( 'https://example.test/?u=1', $body );
	}
}
