<?php
/**
 * Checks on the membership records that help a membership secretary tidy
 * them: missing or invalid ECF codes, incomplete applications and possible
 * duplicates. These work on lists of members already loaded, so they make no
 * queries of their own; the ECF is only asked about a code from the Member
 * Checks screen, a few at a time, and the answer is cached.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Member_Audit {

	const VERDICT_KEY = 'ecf_code_verdict_';

	const VERDICT_VALID   = 'valid';
	const VERDICT_UNKNOWN = 'unknown';

	/**
	 * Current members who have not paid for the season now running.
	 *
	 * @param array[] $members Member rows.
	 * @return array[]
	 */
	public static function unpaid( array $members ) {
		return array_values(
			array_filter(
				$members,
				function ( $member ) {
					return Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === $member['status'] && '' === $member['paid_on'];
				}
			)
		);
	}

	/* -------------------------------------------------------------
	 * ECF codes
	 * ------------------------------------------------------------- */

	/**
	 * Current members with no ECF rating code.
	 *
	 * @param array[] $members Member rows.
	 * @return array[]
	 */
	public static function missing_ecf_code( array $members ) {
		return array_values(
			array_filter(
				$members,
				function ( $member ) {
					return '' === $member['ecf_code'] && Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === $member['status'];
				}
			)
		);
	}

	/**
	 * Whether a stored ECF rating code looks like one: six digits and, usually,
	 * a check letter (for example 120787J).
	 *
	 * @param string $code Code as stored (letters and digits only).
	 * @return bool
	 */
	public static function ecf_code_looks_valid( $code ) {
		/**
		 * Filter the pattern an ECF rating code must match to look valid.
		 *
		 * @param string $pattern Regular expression, six digits and an optional letter by default.
		 */
		$pattern = (string) apply_filters( 'Chess_Army_Knife_ecf_code_pattern', '/^[0-9]{6}[A-Z]?$/' );
		return (bool) preg_match( $pattern, strtoupper( (string) $code ) );
	}

	/**
	 * Current members who have an ECF code that does not look like one.
	 *
	 * @param array[] $members Member rows.
	 * @return array[]
	 */
	public static function malformed_ecf_code( array $members ) {
		return array_values(
			array_filter(
				$members,
				function ( $member ) {
					return '' !== $member['ecf_code'] && ! self::ecf_code_looks_valid( $member['ecf_code'] ) && Chess_Army_Knife_Membership_Store::STATUS_ACTIVE === $member['status'];
				}
			)
		);
	}

	/**
	 * What the ECF said the last time the code was checked, if it has been.
	 * Nothing is fetched.
	 *
	 * @param string $code ECF rating code.
	 * @return string|null VERDICT_VALID, VERDICT_UNKNOWN, or null if not checked (recently).
	 */
	public static function ecf_verdict( $code ) {
		$verdict = Chess_Army_Knife_Cache::peek( self::VERDICT_KEY . Chess_Army_Knife_ECF_Client::normalise_code( $code ) );
		return in_array( $verdict, array( self::VERDICT_VALID, self::VERDICT_UNKNOWN ), true ) ? $verdict : null;
	}

	/**
	 * Ask the ECF whether a code belongs to a player. The answer is kept for
	 * six hours, like the ECF's other answers, so the same code is not asked
	 * about twice in that time.
	 *
	 * @param string $code ECF rating code.
	 * @return string|WP_Error VERDICT_VALID or VERDICT_UNKNOWN; an error if the ECF could not be asked (for a couple of minutes only).
	 */
	public static function verify_ecf_code( $code ) {
		$digits = Chess_Army_Knife_ECF_Client::normalise_code( $code );

		return Chess_Army_Knife_Cache::remember(
			self::VERDICT_KEY . $digits,
			6 * HOUR_IN_SECONDS,
			function () use ( $digits ) {
				$player = Chess_Army_Knife_ECF_Client::get_player_by_code( $digits );
				if ( ! is_wp_error( $player ) ) {
					return self::VERDICT_VALID;
				}
				return self::is_not_found( $player ) ? self::VERDICT_UNKNOWN : $player;
			}
		);
	}

	/**
	 * Whether an ECF error means "no such player", rather than the ECF being
	 * unavailable, which says nothing about the code.
	 *
	 * @param WP_Error $error Error from the ECF client.
	 * @return bool
	 */
	public static function is_not_found( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		return 'ecf_api_error' === $error->get_error_code() && $status >= 400 && $status < 500 && 408 !== $status && 429 !== $status;
	}

	/**
	 * Check the next few codes nobody has asked the ECF about yet. A small batch
	 * keeps within the ECF's daily processing allowance; run it again for more.
	 *
	 * @param array[] $members Member rows, current members with well-formed codes.
	 * @param int     $limit   Most codes to ask about.
	 * @return array { asked, failed } how many were asked about, and how many of those the ECF could not answer.
	 */
	public static function verify_next_codes( array $members, $limit ) {
		$result  = array(
			'asked'  => 0,
			'failed' => 0,
		);
		$checked = array();

		foreach ( $members as $member ) {
			$digits = Chess_Army_Knife_ECF_Client::normalise_code( $member['ecf_code'] );
			if ( $result['asked'] >= $limit || isset( $checked[ $digits ] ) || null !== self::ecf_verdict( $digits ) ) {
				continue;
			}

			$checked[ $digits ] = true;
			++$result['asked'];
			if ( is_wp_error( self::verify_ecf_code( $digits ) ) ) {
				++$result['failed'];
				if ( $result['failed'] >= Chess_Army_Knife_Rating_Refresh::MAX_FAILURES_IN_A_ROW ) {
					break; // The ECF is likely down or limiting us.
				}
			}
		}

		return $result;
	}

	/* -------------------------------------------------------------
	 * Applications
	 * ------------------------------------------------------------- */

	/**
	 * What is missing from a pending application, for the secretary to chase.
	 *
	 * @param array  $member Member row.
	 * @param string $today  Today's site-local date, YYYY-MM-DD.
	 * @return string[] Plain-language problems; empty if the application is complete.
	 */
	public static function application_problems( array $member, $today ) {
		$problems = array();

		if ( Chess_Army_Knife_Membership_Store::contact_email( $member ) === '' ) {
			$problems[] = __( 'No email address', 'chess-army-knife' );
		}
		if ( 0 === $member['membership_type_id'] ) {
			$problems[] = __( 'No membership type', 'chess-army-knife' );
		}
		if ( Chess_Army_Knife_Membership_Store::SOURCE_FORM === $member['source'] && '' === $member['consent_at'] ) {
			$problems[] = __( 'Has not agreed to the club keeping their details', 'chess-army-knife' );
		}

		$junior = '' !== $member['guardian_name'] || Chess_Army_Knife_Memberships::is_under_18( $member['date_of_birth'], $today );
		if ( $junior ) {
			if ( '' === $member['date_of_birth'] ) {
				$problems[] = __( 'Junior with no date of birth', 'chess-army-knife' );
			}
			if ( '' === $member['guardian_name'] || '' === $member['guardian_email'] ) {
				$problems[] = __( 'Junior with no parent or guardian name and email', 'chess-army-knife' );
			}
		}

		return $problems;
	}

	/**
	 * Pending applications that are missing something.
	 *
	 * @param array[] $members Member rows.
	 * @param string  $today   Today's site-local date, YYYY-MM-DD.
	 * @return array[] Each { member, problems }, oldest application first.
	 */
	public static function incomplete_applications( array $members, $today ) {
		$found = array();

		foreach ( $members as $member ) {
			if ( Chess_Army_Knife_Membership_Store::STATUS_PENDING !== $member['status'] ) {
				continue;
			}
			$problems = self::application_problems( $member, $today );
			if ( $problems ) {
				$found[] = array(
					'member'   => $member,
					'problems' => $problems,
				);
			}
		}

		usort(
			$found,
			function ( $a, $b ) {
				return strcmp( (string) $a['member']['created_at'], (string) $b['member']['created_at'] );
			}
		);
		return $found;
	}

	/* -------------------------------------------------------------
	 * Duplicates
	 * ------------------------------------------------------------- */

	/**
	 * A name with its case, punctuation and word order ignored, so "Smith, John"
	 * and "john smith" match.
	 *
	 * @param string $name Name.
	 * @return string '' if nothing is left.
	 */
	public static function name_key( $name ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( (string) $name ), -1, PREG_SPLIT_NO_EMPTY );
		sort( $words );
		return implode( ' ', $words );
	}

	/**
	 * People who may be in the club's records twice. Two records are flagged when
	 * they share an ECF code or an email address of their own; two with the same
	 * name are flagged too, unless their dates of birth or ECF codes show they
	 * are different people. A parent's email is never used: brothers and sisters
	 * share one. Records already erased are left out.
	 *
	 * @param array[] $members Member rows.
	 * @return array[] Each { reason (ecf_code, email or name), members }, for each set of two or more.
	 */
	public static function duplicate_groups( array $members ) {
		$erased = Chess_Army_Knife_Membership_Store::erased_name();
		$keyed  = array(
			'ecf_code' => array(),
			'email'    => array(),
			'name'     => array(),
		);

		foreach ( $members as $member ) {
			if ( $member['name'] === $erased ) {
				continue;
			}

			$digits = Chess_Army_Knife_ECF_Client::normalise_code( $member['ecf_code'] );
			if ( '' !== $digits ) {
				$keyed['ecf_code'][ $digits ][] = $member;
			}
			if ( '' !== $member['email'] ) {
				$keyed['email'][ strtolower( $member['email'] ) ][] = $member;
			}
			$name = self::name_key( $member['name'] );
			if ( '' !== $name ) {
				$keyed['name'][ $name ][] = $member;
			}
		}

		$groups = array();
		foreach ( $keyed as $reason => $sets ) {
			foreach ( $sets as $set ) {
				if ( 'name' === $reason ) {
					$set = self::same_person_by_name( $set );
				}
				if ( count( $set ) > 1 ) {
					$groups[] = array(
						'reason'  => $reason,
						'members' => $set,
					);
				}
			}
		}
		return $groups;
	}

	/**
	 * From people sharing a name, those who could be one person: dropping anyone
	 * whose date of birth or ECF code differs from everyone else's, as that
	 * shows they are not.
	 *
	 * @param array[] $set Member rows with the same name.
	 * @return array[] The rows that may be the same person, or none.
	 */
	protected static function same_person_by_name( array $set ) {
		$likely = array();

		foreach ( $set as $index => $member ) {
			foreach ( $set as $other_index => $other ) {
				if ( $index === $other_index || ! self::could_be_same_person( $member, $other ) ) {
					continue;
				}
				$likely[ $index ] = $member;
			}
		}
		return array_values( $likely );
	}

	/**
	 * Whether two people with the same name could be one person.
	 *
	 * @param array $a Member row.
	 * @param array $b Member row.
	 * @return bool
	 */
	protected static function could_be_same_person( array $a, array $b ) {
		if ( '' !== $a['date_of_birth'] && '' !== $b['date_of_birth'] && $a['date_of_birth'] !== $b['date_of_birth'] ) {
			return false;
		}

		$code_a = Chess_Army_Knife_ECF_Client::normalise_code( $a['ecf_code'] );
		$code_b = Chess_Army_Knife_ECF_Client::normalise_code( $b['ecf_code'] );
		return ! ( '' !== $code_a && '' !== $code_b && $code_a !== $code_b );
	}
}
