<?php
/**
 * Who may pick a team: a captain, and officers.
 *
 * A team's captain is a person on the club's records (shown to officers) and,
 * if they are to use the Team Selection screen, a website user too. Giving
 * someone the website side needs the power to promote users, because it adds
 * a permission to their account. The permission only opens the teams they
 * captain (and the squad, availability and line-up of those teams); people
 * with the team permission (and so those with the membership permission) can
 * work with every team.
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Captains {

	const CAPABILITY     = 'chess_army_captain';
	const META_USER      = '_chess_army_team_captain_user';
	const META_BOARDS    = '_chess_army_team_boards';
	const DEFAULT_BOARDS = 4;
	const MAX_BOARDS     = 20;

	/**
	 * The website user who captains a team.
	 *
	 * @param int $team_id Team id.
	 * @return int 0 if none.
	 */
	public static function user_of_team( $team_id ) {
		return (int) get_post_meta( $team_id, self::META_USER, true );
	}

	/**
	 * How many boards a team plays.
	 *
	 * @param int $team_id Team id.
	 * @return int
	 */
	public static function boards( $team_id ) {
		$boards = (int) get_post_meta( $team_id, self::META_BOARDS, true );
		return $boards > 0 ? min( self::MAX_BOARDS, $boards ) : self::DEFAULT_BOARDS;
	}

	/**
	 * Make a user the website captain of a team (or remove them), keeping the permission in step.
	 *
	 * @param int $team_id Team id.
	 * @param int $user_id User id, or 0 for none.
	 */
	public static function set_user( $team_id, $user_id ) {
		$old = self::user_of_team( $team_id );
		update_post_meta( $team_id, self::META_USER, (int) $user_id );

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$user->add_cap( self::CAPABILITY );
			}
		}
		// Take the permission back from someone who no longer captains any team.
		if ( $old && $old !== (int) $user_id && ! self::captained_team_ids( $old ) ) {
			$previous = get_userdata( $old );
			if ( $previous ) {
				$previous->remove_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * The teams a website user captains.
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public static function captained_team_ids( $user_id ) {
		$ids = array();
		foreach ( Chess_Army_Knife_Teams::all() as $team ) {
			if ( self::user_of_team( $team['id'] ) === (int) $user_id ) {
				$ids[] = $team['id'];
			}
		}
		return $ids;
	}

	/**
	 * Whether the current user has anything to do on the Team Selection screen.
	 *
	 * @return bool
	 */
	public static function user_can_select() {
		return Chess_Army_Knife_Teams::user_can_manage() || current_user_can( self::CAPABILITY );
	}

	/**
	 * The teams a user may pick.
	 *
	 * @param int $user_id User id; the current user by default.
	 * @return array[] Teams (see Chess_Army_Knife_Teams::all()).
	 */
	public static function teams_for_user( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( user_can( $user_id, Chess_Army_Knife_Teams::CAPABILITY ) ) {
			return Chess_Army_Knife_Teams::all();
		}
		if ( ! user_can( $user_id, self::CAPABILITY ) ) {
			return array();
		}
		return array_values(
			array_filter(
				Chess_Army_Knife_Teams::all(),
				function ( $team ) use ( $user_id ) {
					return self::user_of_team( $team['id'] ) === $user_id;
				}
			)
		);
	}

	/**
	 * Whether the current user may pick a team.
	 *
	 * @param int $team_id Team id.
	 * @return bool
	 */
	public static function can_manage_team( $team_id ) {
		foreach ( self::teams_for_user() as $team ) {
			if ( $team['id'] === (int) $team_id ) {
				return true;
			}
		}
		return false;
	}
}
