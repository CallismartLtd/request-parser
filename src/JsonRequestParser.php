<?php
/**
 * application/json request body parser.
 *
 * @package Callismart\RequestParser
 * @author Callistus Nwachukwu
 */

namespace Callismart\RequestParser;

use Callismart\RequestParser\Exceptions\ParserException;

/**
 * Decodes an application/json request body into the post/files shape
 * shared by every RequestBodyParserInterface implementation.
 */
class JsonRequestParser extends AbstractRawBodyParser {

	/**
	 * @inheritDoc
	 * @throws ParserException
	 */
	public function parse() : array {
		$raw = $this->read_raw_body();

		if ( '' === trim( $raw ) ) {
			return [ 'post' => [], 'files' => [] ];
		}

		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new ParserException( 'invalid_json', 'Request body is not valid JSON: ' . json_last_error_msg() );
		}

		if ( ! is_array( $decoded ) ) {
			throw new ParserException( 'invalid_json_structure', 'JSON body must decode to an object or array.' );
		}

		return [ 'post' => $decoded, 'files' => [] ];
	}
}
