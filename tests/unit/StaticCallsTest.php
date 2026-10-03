<?php
/**
 * Every call to a plugin class's static method must name a method that exists.
 * A method removed by accident (for example in a large replace) is then caught
 * here, without WordPress, rather than as a fatal error on a page.
 *
 * @package Chess_Army_Knife
 */

class StaticCallsTest extends Chess_Army_Knife_TestCase {

	/**
	 * The plugin's PHP files.
	 *
	 * @return string[]
	 */
	private function php_files() {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/chess-army-knife.php', $root . '/uninstall.php' );
		foreach ( array( 'includes', 'src' ) as $directory ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		sort( $files );

		return $files;
	}

	/**
	 * Read the plugin's classes (with their parent and methods) and every Class::method( call.
	 *
	 * @return array { classes: array, calls: array }
	 */
	private function scan() {
		$classes = array();
		$calls   = array();

		foreach ( $this->php_files() as $path ) {
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in a test.
					function ( $token ) {
						return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
					}
				)
			);
			$count  = count( $tokens );
			$class  = '';
			$depth  = 0;
			$inside = null; // Brace depth at which the current class body started.

			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				$text  = is_array( $token ) ? $token[1] : $token;

				if ( '{' === $text || ( is_array( $token ) && T_CURLY_OPEN === $token[0] ) || ( is_array( $token ) && T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) ) {
					++$depth;
				} elseif ( '}' === $text ) {
					--$depth;
					if ( null !== $inside && $depth < $inside ) {
						$class  = '';
						$inside = null;
					}
				}

				if ( is_array( $token ) && T_CLASS === $token[0] && isset( $tokens[ $i + 1 ][1] ) && T_STRING === $tokens[ $i + 1 ][0] && ( ! isset( $tokens[ $i - 1 ] ) || T_DOUBLE_COLON !== $tokens[ $i - 1 ][0] ) ) {
					$class             = $tokens[ $i + 1 ][1];
					$classes[ $class ] = array(
						'parent'  => '',
						'methods' => array(),
					);
					$inside            = $depth + 1;
					if ( isset( $tokens[ $i + 2 ][0] ) && T_EXTENDS === $tokens[ $i + 2 ][0] ) {
						$classes[ $class ]['parent'] = ltrim( $tokens[ $i + 3 ][1], '\\' );
					}
				}

				if ( '' !== $class && is_array( $token ) && T_FUNCTION === $token[0] ) {
					$next = $tokens[ $i + 1 ];
					$next = '&' === $next ? $tokens[ $i + 2 ] : $next;
					if ( is_array( $next ) && T_STRING === $next[0] ) {
						$classes[ $class ]['methods'][ strtolower( $next[1] ) ] = true;
					}
				}

				if ( is_array( $token ) && T_STRING === $token[0] && 0 === strpos( $token[1], 'Chess_Army_Knife_' ) && isset( $tokens[ $i + 3 ] ) && T_DOUBLE_COLON === ( is_array( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ][0] : 0 ) && is_array( $tokens[ $i + 2 ] ) && T_STRING === $tokens[ $i + 2 ][0] && '(' === $tokens[ $i + 3 ] ) {
					$calls[] = array( $token[1], $tokens[ $i + 2 ][1], basename( $path ) . ':' . $token[2] );
				}
			}
		}

		return array(
			'classes' => $classes,
			'calls'   => $calls,
		);
	}

	/**
	 * Whether a class, or a plugin class it extends, defines a method.
	 *
	 * @param array  $classes Classes from scan().
	 * @param string $class   Class name.
	 * @param string $method  Method name.
	 * @return bool
	 */
	private function defines( array $classes, $class, $method ) {
		while ( isset( $classes[ $class ] ) ) {
			if ( isset( $classes[ $class ]['methods'][ strtolower( $method ) ] ) ) {
				return true;
			}
			$class = $classes[ $class ]['parent'];
		}

		// A parent outside the plugin (WordPress, PHP) cannot be checked here.
		return '' !== $class;
	}

	public function test_every_static_call_names_a_method_that_exists() {
		$scan    = $this->scan();
		$missing = array();

		$this->assertGreaterThan( 50, count( $scan['classes'] ), 'The scan should have found the plugin classes.' );
		$this->assertGreaterThan( 500, count( $scan['calls'] ), 'The scan should have found the static calls.' );

		foreach ( $scan['calls'] as $call ) {
			list( $class, $method, $where ) = $call;
			if ( ! isset( $scan['classes'][ $class ] ) ) {
				$missing[] = "$where: class $class is not defined anywhere in the plugin";
			} elseif ( ! $this->defines( $scan['classes'], $class, $method ) ) {
				$missing[] = "$where: $class::$method() is not defined";
			}
		}

		$this->assertSame( array(), array_values( array_unique( $missing ) ) );
	}

	public function test_the_scan_notices_a_method_that_is_missing() {
		$classes = array(
			'Chess_Army_Knife_Example' => array(
				'parent'  => '',
				'methods' => array( 'present' => true ),
			),
		);

		$this->assertTrue( $this->defines( $classes, 'Chess_Army_Knife_Example', 'Present' ) );
		$this->assertFalse( $this->defines( $classes, 'Chess_Army_Knife_Example', 'gone' ) );
	}
}
