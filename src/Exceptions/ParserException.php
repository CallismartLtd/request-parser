<?php
/**
 * Parser Exception.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\RequestParser\Exceptions
 * @since 1.0.0
 */

namespace Callismart\RequestParser\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown whenever a request cannot be parsed.
 *
 * Carries a machine-readable string error code (for example
 * `post_max_size_exceeded` or `invalid_json`) next to the human-readable
 * message, so callers can branch on the code without matching message text.
 */
class ParserException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string          $error_code Machine-readable error code.
	 * @param string          $message    Human-readable message.
	 * @param Throwable|null  $previous   Optional previous exception for chaining.
	 */
	public function __construct( private string $error_code, string $message = '', ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * Get the machine-readable error code.
	 *
	 * @return string
	 */
	public function get_error_code() : string {
		return $this->error_code;
	}
}