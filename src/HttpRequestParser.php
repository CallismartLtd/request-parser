<?php
/**
 * Streaming HTTP request parser — orchestrator.
 *
 * Philosophy: fill the gaps PHP leaves open, and never redo what PHP
 * already does. $_POST/$_FILES are only auto-populated for POST requests
 * carrying multipart/form-data or application/x-www-form-urlencoded
 * bodies, so this parser steps in for everything else (PUT, PATCH, DELETE,
 * and JSON bodies on any method). Delegates the actual per-content-type
 * parsing to dedicated classes (MultipartRequestParser, JsonRequestParser,
 * UrlencodedRequestParser) implementing RequestBodyParserInterface, and
 * additionally exposes normalized request header and cookie access.
 *
 * Every parse result has the same three keys, whatever the method or
 * Content-Type: `post`, `files` and `query`. `query` holds the URL query
 * string decoded exactly the way PHP decodes it for $_GET.
 *
 * @package Callismart\RequestParser
 * @author Callistus Nwachukwu
 * @since 1.0.0
 */

namespace Callismart\RequestParser;

use Callismart\RequestParser\Exceptions\ParserException;

/**
 * Orchestrates content-type detection and dispatch to the dedicated
 * request body parsers.
 */
class HttpRequestParser {

	/**
	 * Content types this parser knows how to handle (base mime, no parameters).
	 */
	private const CONTENT_TYPE_MULTIPART	= 'multipart/form-data';
	private const CONTENT_TYPE_URLENCODED	= 'application/x-www-form-urlencoded';
	private const CONTENT_TYPE_JSON			= 'application/json';

	/**
	 * Methods for which PHP does NOT natively populate $_POST/$_FILES
	 * for multipart/form-data or application/x-www-form-urlencoded bodies.
	 * POST already gets native handling for those two content types.
	 */
	private const NATIVE_GAP_METHODS = [ 'PUT', 'PATCH', 'DELETE' ];

	/**
	 * Methods that never carry a meaningful request body.
	 */
	private const BODYLESS_METHODS = [ 'GET', 'HEAD', 'OPTIONS' ];

	/**
	 * Whether parsing has completed.
	 *
	 * @var bool
	 */
	private bool $parsed = false;

	/**
	 * Parsed POST data.
	 *
	 * @var array
	 */
	private array $post_data = [];

	/**
	 * Parsed FILES data.
	 *
	 * @var array
	 */
	private array $files_data = [];

	/**
	 * Parsed URL query data, resolved on first access.
	 *
	 * @var array|null
	 */
	private ?array $query_data = null;

	/**
	 * PHP.INI limits.
	 *
	 * @var array
	 */
	private array $limits;

	/**
	 * The dedicated parser resolved for this request, once parsing starts.
	 *
	 * @var RequestBodyParserInterface|null
	 */
	private ?RequestBodyParserInterface $active_parser = null;

	/**
	 * Constructor.
	 *
	 * @param string      $method            HTTP method.
	 * @param string      $content_type      Content-Type header.
	 * @param string|null $upload_tmp_prefix Optional tempnam() prefix, passed through to the multipart parser.
	 * @param bool        $debug             Enable debugging.
	 * @param bool        $verbose           Whether to log stream errors.
	 * @param string|null $query_string      URL query string, with or without the leading "?". Defaults to $_SERVER['QUERY_STRING'].
	 */
	public function __construct(
		protected string $method,
		protected string $content_type,
		protected ?string $upload_tmp_prefix = null,
		protected bool $debug = false,
		protected bool $verbose = false,
		protected ?string $query_string = null
	) {
		$this->method = strtoupper( $this->method );
		$this->limits = $this->parse_php_ini_limits();

		register_shutdown_function( [ $this, 'cleanup' ] );
	}

	/**
	 * Parse php.ini limits with proper size conversion.
	 *
	 * @return array
	 */
	private function parse_php_ini_limits() : array {
		return [
			'post_max_size'       => $this->parse_ini_size( 'post_max_size' ),
			'upload_max_filesize' => $this->parse_ini_size( 'upload_max_filesize' ),
			'max_file_uploads'    => max( 1, (int) ini_get( 'max_file_uploads' ) ),
			'memory_limit'        => $this->parse_ini_size( 'memory_limit' ),
		];
	}

	/**
	 * Parse INI size value to bytes.
	 *
	 * @param string $key INI directive.
	 * @return int Bytes (0 = unlimited).
	 */
	private function parse_ini_size( string $key ) : int {
		$value = trim( (string) ini_get( $key ) );

		if ( empty( $value ) || '-1' === $value ) {
			return 0;
		}

		$unit  = strtolower( substr( $value, -1 ) );
		$bytes = (int) $value;

		switch ( $unit ) {
			case 'g':
				$bytes *= 1073741824;
				break;
			case 'm':
				$bytes *= 1048576;
				break;
			case 'k':
				$bytes *= 1024;
				break;
		}

		return max( 0, $bytes );
	}

	/**
	 * Normalize the Content-Type header to its base mime, stripping
	 * parameters like `; boundary=...` or `; charset=...`.
	 *
	 * @return string
	 */
	private function base_content_type() : string {
		$parts = explode( ';', $this->content_type, 2 );

		return strtolower( trim( $parts[0] ) );
	}

	/**
	 * Whether PHP would NOT already have populated $_POST/$_FILES
	 * natively for this method + Content-Type combination.
	 *
	 * @return bool
	 */
	public function should_parse() : bool {
		if ( 'cli' === php_sapi_name() ) {
			return false;
		}

		if ( in_array( $this->method, self::BODYLESS_METHODS, true ) ) {
			return false;
		}

		$base_type = $this->base_content_type();

		if ( self::CONTENT_TYPE_JSON === $base_type ) {
			// PHP does not parse JSON bodies, on any method.
			return true;
		}

		if ( ! in_array( $this->method, self::NATIVE_GAP_METHODS, true ) ) {
			// POST already gets native handling for multipart/urlencoded.
			return false;
		}

		return self::CONTENT_TYPE_MULTIPART === $base_type
			|| self::CONTENT_TYPE_URLENCODED === $base_type;
	}

	/**
	 * Resolve the dedicated parser for the current Content-Type.
	 *
	 * @return RequestBodyParserInterface|null Null if the content type isn't supported.
	 */
	private function resolve_parser() : ?RequestBodyParserInterface {
		switch ( $this->base_content_type() ) {
			case self::CONTENT_TYPE_MULTIPART:
				return new MultipartRequestParser( $this->content_type, $this->limits, $this->upload_tmp_prefix, $this->debug, $this->verbose );

			case self::CONTENT_TYPE_URLENCODED:
				return new UrlencodedRequestParser( $this->limits );

			case self::CONTENT_TYPE_JSON:
				return new JsonRequestParser( $this->limits );

			default:
				return null;
		}
	}

	/**
	 * Run whichever parser resolve_parser() picks and store the result.
	 *
	 * @return array{post: array, files: array, query: array}
	 * @throws ParserException
	 */
	private function run_parser() : array {
		try {
			$this->validate_content_length();

			$this->active_parser = $this->resolve_parser();

			if ( null === $this->active_parser ) {
				$this->parsed = true;
				return $this->result();
			}

			$result = $this->active_parser->parse();

			$this->post_data  = $result['post'];
			$this->files_data = $result['files'];
			$this->parsed     = true;

			return $this->result();

		} catch ( ParserException $e ) {
			$this->cleanup();
			throw $e;
		}
	}

	/**
	 * Parse the request body, but only if PHP wouldn't already have
	 * populated $_POST/$_FILES natively for this method + Content-Type.
	 * This is the "in context" API — use it inside a normal PHP SAPI
	 * request, typically alongside populate_globals().
	 *
	 * The `query` key is always filled, even when the body is skipped.
	 *
	 * @return array{post: array, files: array, query: array}
	 * @throws ParserException
	 */
	public function parse() : array {
		if ( $this->parsed ) {
			return $this->result();
		}

		if ( ! $this->should_parse() ) {
			$this->parsed = true;
			return $this->result();
		}

		return $this->run_parser();
	}

	/**
	 * Parse the request body based on Content-Type alone, ignoring
	 * whether PHP would have handled it natively. Use this outside a
	 * normal PHP SAPI request/response cycle — a standalone router, a
	 * CLI harness, or any environment where nothing populates
	 * $_POST/$_FILES for you regardless of method.
	 *
	 * @return array{post: array, files: array, query: array}
	 * @throws ParserException
	 */
	public function parse_all() : array {
		if ( $this->parsed ) {
			return $this->result();
		}

		if ( 'cli' === php_sapi_name() || in_array( $this->method, self::BODYLESS_METHODS, true ) ) {
			$this->parsed = true;
			return $this->result();
		}

		return $this->run_parser();
	}

	/**
	 * Populate global arrays (only fills the native gap; see parse()).
	 *
	 * @return bool
	 * @throws ParserException
	 */
	public function populate_globals() : bool {
		if ( ! $this->should_parse() ) {
			return false;
		}

		$result = $this->parse();

		$_POST    = $this->merge_arrays( $result['post'], $_POST );
		$_FILES   = $this->merge_files_arrays( $result['files'], $_FILES );
		$_REQUEST = $this->merge_arrays( $result['post'], $_REQUEST );

		return true;
	}

	/**
	 * Recursively merge arrays.
	 *
	 * @param array $new      New data.
	 * @param array $existing Existing data.
	 * @return array
	 */
	private function merge_arrays( array $new, array $existing ) : array {
		foreach ( $new as $key => $value ) {
			if ( isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && is_array( $value ) ) {
				$existing[ $key ] = $this->merge_arrays( $value, $existing[ $key ] );
			} elseif ( ! isset( $existing[ $key ] ) ) {
				$existing[ $key ] = $value;
			}
		}

		return $existing;
	}

	/**
	 * Merge FILES arrays with native structure preservation.
	 *
	 * @param array $new      New files.
	 * @param array $existing Existing files.
	 * @return array
	 */
	private function merge_files_arrays( array $new, array $existing ) : array {
		foreach ( $new as $field => $data ) {
			if ( isset( $existing[ $field ] ) ) {
				$existing[ $field ] = $this->merge_file_entries( $data, $existing[ $field ] );
			} else {
				$existing[ $field ] = $data;
			}
		}

		return $existing;
	}

	/**
	 * Merge individual file entries.
	 *
	 * @param array $new      New file data.
	 * @param array $existing Existing file data.
	 * @return array
	 */
	private function merge_file_entries( array $new, array $existing ) : array {
		$is_new_multiple      = isset( $new['name'] ) && is_array( $new['name'] );
		$is_existing_multiple = isset( $existing['name'] ) && is_array( $existing['name'] );

		if ( $is_new_multiple && $is_existing_multiple ) {
			foreach ( [ 'name', 'type', 'tmp_name', 'error', 'size' ] as $key ) {
				if ( isset( $new[ $key ] ) && isset( $existing[ $key ] ) ) {
					$existing[ $key ] = array_merge( (array) $existing[ $key ], (array) $new[ $key ] );
				}
			}
			return $existing;
		}

		if ( ! $is_new_multiple && ! $is_existing_multiple ) {
			return [
				'name'     => [ $existing['name'], $new['name'] ],
				'type'     => [ $existing['type'], $new['type'] ],
				'tmp_name' => [ $existing['tmp_name'], $new['tmp_name'] ],
				'error'    => [ $existing['error'], $new['error'] ],
				'size'     => [ $existing['size'], $new['size'] ],
			];
		}

		if ( ! $is_existing_multiple ) {
			$existing = $this->convert_to_multiple( $existing );
		}

		if ( ! $is_new_multiple ) {
			$new = $this->convert_to_multiple( $new );
		}

		foreach ( [ 'name', 'type', 'tmp_name', 'error', 'size' ] as $key ) {
			$existing[ $key ] = array_merge( (array) $existing[ $key ], (array) $new[ $key ] );
		}

		return $existing;
	}

	/**
	 * Convert single file structure to multiple.
	 *
	 * @param array $file Single file.
	 * @return array Multiple structure.
	 */
	private function convert_to_multiple( array $file ) : array {
		return [
			'name'     => [ $file['name'] ],
			'type'     => [ $file['type'] ],
			'tmp_name' => [ $file['tmp_name'] ],
			'error'    => [ $file['error'] ],
			'size'     => [ $file['size'] ],
		];
	}

	/**
	 * Validate Content-Length against post_max_size.
	 *
	 * @throws ParserException
	 */
	private function validate_content_length() : void {
		$content_length = $_SERVER['CONTENT_LENGTH'] ?? null;

		if ( null === $content_length ) {
			return;
		}

		$content_length = (int) $content_length;

		if ( $content_length <= 0 ) {
			throw new ParserException( 'empty_body', 'Content-Length is zero or negative.' );
		}

		$post_max = $this->limits['post_max_size'];

		if ( $post_max > 0 && $content_length > $post_max ) {
			throw new ParserException(
				'post_max_size_exceeded',
				sprintf( 'Content-Length (%d bytes) exceeds post_max_size (%d bytes).', $content_length, $post_max )
			);
		}
	}

	/**
	 * Get all normalized request headers (lower-case keys).
	 *
	 * @return array<string, string>
	 */
	public function get_headers() : array {
		if ( function_exists( 'getallheaders' ) ) {
			$raw = getallheaders();
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$raw = apache_request_headers();
		} else {
			$raw = $this->headers_from_server();
		}

		$headers = [];

		foreach ( $raw as $key => $value ) {
			$headers[ strtolower( $key ) ] = $value;
		}

		return $headers;
	}

	/**
	 * Fallback header extraction from $_SERVER for SAPIs without
	 * getallheaders()/apache_request_headers() (rare; both are
	 * available under Apache and FPM as of PHP 7.3+).
	 *
	 * @return array<string, string>
	 */
	private function headers_from_server() : array {
		$headers = [];

		foreach ( $_SERVER as $key => $value ) {
			if ( str_starts_with( $key, 'HTTP_' ) ) {
				$name             = substr( $key, 5 );
				$name             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', $name ) ) ) );
				$headers[ $name ] = $value;
				continue;
			}

			if ( in_array( $key, [ 'CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5' ], true ) ) {
				$name             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', $key ) ) ) );
				$headers[ $name ] = $value;
			}
		}

		if ( ! isset( $headers['Authorization'] ) ) {
			if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			} elseif ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
				$basic_pass               = $_SERVER['PHP_AUTH_PW'] ?? '';
				$headers['Authorization'] = 'Basic ' . base64_encode( $_SERVER['PHP_AUTH_USER'] . ':' . $basic_pass );
			} elseif ( isset( $_SERVER['PHP_AUTH_DIGEST'] ) ) {
				$headers['Authorization'] = $_SERVER['PHP_AUTH_DIGEST'];
			}
		}

		return $headers;
	}

	/**
	 * Get a single request header, case-insensitively.
	 *
	 * @param string      $name    Header name.
	 * @param string|null $default Default if the header is absent.
	 * @return string|null
	 */
	public function get_header( string $name, ?string $default = null ) : ?string {
		return $this->get_headers()[ strtolower( $name ) ] ?? $default;
	}

	/**
	 * Get all request cookies.
	 *
	 * @return array
	 */
	public function get_cookies() : array {
		return $_COOKIE;
	}

	/**
	 * Get a single request cookie.
	 *
	 * @param string $name    Cookie name.
	 * @param mixed  $default Default if the cookie is absent.
	 * @return mixed
	 */
	public function get_cookie( string $name, $default = null ) {
		return $_COOKIE[ $name ] ?? $default;
	}

	/**
	 * Total bytes read by the active parser, if any.
	 *
	 * @return int
	 */
	public function get_bytes_read() : int {
		return $this->active_parser ? $this->active_parser->get_bytes_read() : 0;
	}

	/**
	 * Number of files uploaded in this request.
	 *
	 * @return int
	 */
	public function get_files_count() : int {
		return $this->active_parser ? $this->active_parser->get_files_count() : 0;
	}

	/**
	 * Get the parsed URL query data.
	 *
	 * Decoded with parse_str(), so the result matches what PHP puts in
	 * $_GET for the same string (including its key normalization). Does
	 * not depend on the method, the Content-Type, or the body parser.
	 *
	 * @return array
	 */
	public function get_query_data() : array {
		if ( null === $this->query_data ) {
			$query = ltrim( $this->query_string ?? (string) ( $_SERVER['QUERY_STRING'] ?? '' ), '?' );
			$data  = [];

			parse_str( $query, $data );

			$this->query_data = $data;
		}

		return $this->query_data;
	}

	/**
	 * Assemble the shared result shape returned by parse() and parse_all().
	 *
	 * @return array{post: array, files: array, query: array}
	 */
	private function result() : array {
		return [
			'post'  => $this->post_data,
			'files' => $this->files_data,
			'query' => $this->get_query_data(),
		];
	}

	/**
	 * Get parsed POST data.
	 *
	 * @return array
	 * @throws ParserException
	 */
	public function get_post_data() : array {
		if ( ! $this->parsed ) {
			$this->parse();
		}

		return $this->post_data;
	}

	/**
	 * Get parsed FILES data.
	 *
	 * @return array
	 * @throws ParserException
	 */
	public function get_files_data() : array {
		if ( ! $this->parsed ) {
			$this->parse();
		}

		return $this->files_data;
	}

	/**
	 * Temp files created by the active parser, if any.
	 *
	 * @return string[]
	 */
	public function get_temp_files() : array {
		return $this->active_parser ? $this->active_parser->get_temp_files() : [];
	}

	/**
	 * Get statistics. Byte counts are raw integers — format them at
	 * the call site if a human-readable size is needed.
	 *
	 * @return array{method: string, content_type: string, parsed: bool, bytes_read: int, post_count: int, files_count: int, query_count: int, temp_files: int, limits: array}
	 */
	public function get_stats() : array {
		return [
			'method'       => $this->method,
			'content_type' => $this->base_content_type(),
			'parsed'       => $this->parsed,
			'bytes_read'   => $this->get_bytes_read(),
			'post_count'   => count( $this->post_data ),
			'files_count'  => $this->get_files_count(),
			'query_count'  => count( $this->get_query_data() ),
			'temp_files'   => count( $this->get_temp_files() ),
			'limits'       => $this->limits,
		];
	}

	/**
	 * Cleanup temp files safely.
	 */
	public function cleanup() : void {
		$sys_tmp = realpath( sys_get_temp_dir() );

		foreach ( $this->get_temp_files() as $path ) {
			$real_path = realpath( $path );

			if ( $real_path && 0 === strpos( $real_path, $sys_tmp ) && file_exists( $real_path ) ) {
				@unlink( $real_path );
			}
		}
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->cleanup();
	}
}
