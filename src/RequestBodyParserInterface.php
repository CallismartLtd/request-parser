<?php
/**
 * Contract for a dedicated content-type request body parser.
 *
 * @package Callismart\RequestParser
 * @author Callistus Nwachukwu
 */

namespace Callismart\RequestParser;

use Callismart\RequestParser\Exceptions\ParserException;

/**
 * A parser dedicated to a single request body content type.
 */
interface RequestBodyParserInterface {

	/**
	 * Parse the request body.
	 *
	 * @return array{post: array, files: array}
	 * @throws ParserException
	 */
	public function parse() : array;

	/**
	 * Total bytes read from the request stream.
	 *
	 * @return int
	 */
	public function get_bytes_read() : int;

	/**
	 * Number of files received in the request body. Zero for parsers
	 * that never carry files (json, urlencoded).
	 *
	 * @return int
	 */
	public function get_files_count() : int;

	/**
	 * Temporary files created while parsing. Empty for parsers that
	 * never write to disk (json, urlencoded).
	 *
	 * @return string[]
	 */
	public function get_temp_files() : array;
}
