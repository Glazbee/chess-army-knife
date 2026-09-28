<?php
/**
 * Maximum cardinality matching in a general graph (Edmonds' blossom algorithm).
 *
 * Pure logic with no WordPress dependencies. The Swiss pairing engine uses it
 * to check that the players still to be paired can always be completed (FIDE
 * Dutch criterion C4).
 *
 * @package Chess_Army_Knife
 */

defined( 'ABSPATH' ) || exit;

class Chess_Army_Knife_Matching {

	/**
	 * Whether every vertex can be matched to another (a perfect matching).
	 *
	 * @param array[] $adjacency Neighbour lists; vertices are 0..n-1.
	 * @return bool
	 */
	public static function has_perfect( array $adjacency ) {
		$count = count( $adjacency );
		if ( 0 === $count % 2 ) {
			return self::max_matching( $adjacency ) * 2 === $count;
		}
		return false;
	}

	/**
	 * Size of a maximum matching.
	 *
	 * @param array[] $adjacency Neighbour lists; vertices are 0..n-1.
	 * @return int Number of matched pairs.
	 */
	public static function max_matching( array $adjacency ) {
		$count = count( $adjacency );
		$match = array_fill( 0, $count, -1 );

		// Greedy start makes the augmenting phase much cheaper.
		foreach ( $adjacency as $vertex => $neighbours ) {
			if ( -1 !== $match[ $vertex ] ) {
				continue;
			}
			foreach ( $neighbours as $other ) {
				if ( -1 === $match[ $other ] ) {
					$match[ $vertex ] = $other;
					$match[ $other ]  = $vertex;
					break;
				}
			}
		}

		for ( $vertex = 0; $vertex < $count; $vertex++ ) {
			if ( -1 !== $match[ $vertex ] ) {
				continue;
			}
			$end = self::find_augmenting_path( $adjacency, $match, $vertex, $parent );
			while ( -1 !== $end ) {
				$previous           = $parent[ $end ];
				$next               = $match[ $previous ];
				$match[ $end ]      = $previous;
				$match[ $previous ] = $end;
				$end                = $next;
			}
		}

		$pairs = 0;
		foreach ( $match as $vertex => $other ) {
			if ( $other > $vertex ) {
				++$pairs;
			}
		}
		return $pairs;
	}

	/**
	 * Breadth-first search for an augmenting path from an unmatched vertex,
	 * contracting blossoms as they are found.
	 *
	 * @param array[]    $adjacency Neighbour lists.
	 * @param int[]      $match     Current matching (vertex => partner or -1).
	 * @param int        $root      Unmatched start vertex.
	 * @param int[]|null $parent    Filled with the search tree parents.
	 * @return int The far end of an augmenting path, or -1 if there is none.
	 */
	protected static function find_augmenting_path( array $adjacency, array $match, $root, &$parent ) {
		$count  = count( $adjacency );
		$used   = array_fill( 0, $count, false );
		$parent = array_fill( 0, $count, -1 );
		$base   = range( 0, $count - 1 );

		$used[ $root ] = true;
		$queue         = array( $root );

		for ( $head = 0; $head < count( $queue ); $head++ ) {
			$vertex = $queue[ $head ];

			foreach ( $adjacency[ $vertex ] as $to ) {
				if ( $base[ $vertex ] === $base[ $to ] || $match[ $vertex ] === $to ) {
					continue;
				}

				if ( $to === $root || ( -1 !== $match[ $to ] && -1 !== $parent[ $match[ $to ] ] ) ) {
					// Odd cycle: contract the blossom into its base.
					$current_base = self::lowest_common_ancestor( $match, $parent, $base, $vertex, $to );
					$in_blossom   = array_fill( 0, $count, false );
					self::mark_path( $match, $parent, $base, $in_blossom, $vertex, $current_base, $to );
					self::mark_path( $match, $parent, $base, $in_blossom, $to, $current_base, $vertex );

					for ( $i = 0; $i < $count; $i++ ) {
						if ( $in_blossom[ $base[ $i ] ] ) {
							$base[ $i ] = $current_base;
							if ( ! $used[ $i ] ) {
								$used[ $i ] = true;
								$queue[]    = $i;
							}
						}
					}
				} elseif ( -1 === $parent[ $to ] ) {
					$parent[ $to ] = $vertex;
					if ( -1 === $match[ $to ] ) {
						return $to;
					}
					$partner          = $match[ $to ];
					$used[ $partner ] = true;
					$queue[]          = $partner;
				}
			}
		}

		return -1;
	}

	/**
	 * Lowest common ancestor of two vertices in the alternating search tree.
	 *
	 * @param int[] $match  Matching.
	 * @param int[] $parent Search tree parents.
	 * @param int[] $base   Blossom bases.
	 * @param int   $a      First vertex.
	 * @param int   $b      Second vertex.
	 * @return int
	 */
	protected static function lowest_common_ancestor( array $match, array $parent, array $base, $a, $b ) {
		$visited = array();
		while ( true ) {
			$a             = $base[ $a ];
			$visited[ $a ] = true;
			if ( -1 === $match[ $a ] ) {
				break;
			}
			$a = $parent[ $match[ $a ] ];
		}
		while ( true ) {
			$b = $base[ $b ];
			if ( isset( $visited[ $b ] ) ) {
				return $b;
			}
			$b = $parent[ $match[ $b ] ];
		}
	}

	/**
	 * Mark the vertices on the path from $vertex up to the blossom base.
	 *
	 * @param int[]  $match       Matching.
	 * @param int[]  $parent      Search tree parents (updated).
	 * @param int[]  $base        Blossom bases.
	 * @param bool[] $in_blossom  Marked bases (updated).
	 * @param int    $vertex      Start vertex.
	 * @param int    $current_base Base of the blossom.
	 * @param int    $child       Vertex the path continues through.
	 */
	protected static function mark_path( array $match, array &$parent, array $base, array &$in_blossom, $vertex, $current_base, $child ) {
		while ( $base[ $vertex ] !== $current_base ) {
			$in_blossom[ $base[ $vertex ] ]           = true;
			$in_blossom[ $base[ $match[ $vertex ] ] ] = true;
			$parent[ $vertex ]                        = $child;
			$child                                    = $match[ $vertex ];
			$vertex                                   = $parent[ $match[ $vertex ] ];
		}
	}
}
