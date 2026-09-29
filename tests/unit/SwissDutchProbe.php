<?php
/**
 * A subclass of the Swiss pairing engine that exposes its internals to the tests.
 *
 * @package Chess_Army_Knife
 */

/**
 * Exposes the engine's internals for testing.
 */
class Swiss_Dutch_Probe extends Chess_Army_Knife_Swiss_Dutch {

	public function prepare( array $ids, array $rounds, $total ) {
		$this->build( $ids, $rounds, $total, array() );
	}

	public function probe_colour_bound( array $pairs, array $players, $k ) {
		return $this->colour_bound( $pairs, $players, $k );
	}

	public function probe_split_bound( array $s1, array $s2 ) {
		return $this->split_colour_bound( $s1, $s2 );
	}

	public function probe_exchanges( array $list, array $s1, array $s2, $size, $aim = null ) {
		return null === $aim ? $this->exchanges( $s1, $s2, $size ) : $this->reaching_exchanges( $list, $s1, $s2, $size, $aim );
	}

	public function probe_can_fill( array $left, array $right ) {
		return $this->can_fill( $left, $right, array() );
	}

	public function probe_can_pair( $a, $b ) {
		return $this->can_pair( $a, $b );
	}

	public function probe_components( $a, $b ) {
		return $this->pair_components( $a, $b );
	}
}
