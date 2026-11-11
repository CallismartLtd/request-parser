# Request Parser

[![Latest Version](https://img.shields.io/packagist/v/callismart/request-parser?style=flat-square&label=release)](https://packagist.org/packages/callismart/request-parser)
[![Total Downloads](https://img.shields.io/packagist/dt/callismart/request-parser?style=flat-square)](https://packagist.org/packages/callismart/request-parser)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE)
[![Streaming](https://img.shields.io/badge/uploads-streamed%20to%20disk-blue?style=flat-square)](#uploaded-files)

**A streaming HTTP request parser for PHP.** It fills the gaps in PHP's native `$_POST` and `$_FILES` population, and returns every request in the same three-key shape: `post`, `files` and `query`.

PHP only populates `$_POST` and `$_FILES` for `POST` requests carrying `multipart/form-data` or `application/x-www-form-urlencoded` bodies. Everything else is left to you: `PUT`, `PATCH` and `DELETE` bodies, and JSON bodies on any method. Request Parser handles exactly those cases, streams the body instead of buffering it, and leaves native handling alone.

## Highlights

- **Fills only the gaps.** Parses what PHP does not, and steps aside for what it does.
- **One result shape.** `post`, `files` and `query`, on every request.
- **Streamed uploads.** Files are written to temporary storage as they are read, never held in memory.
- **Native-compatible output.** `files` uses the `$_FILES` structure and `query` matches `$_GET`.
- **Limits enforced while streaming.** Honours `post_max_size`, `upload_max_filesize`, `max_file_uploads` and `memory_limit`.
- **Stable error codes.** Every failure is a `ParserException` with a machine-readable code.
- **No dependencies.** Plain PHP 8.1+.

## Installation

```bash
composer require callismart/request-parser
```

## Quick start

```php
use Callismart\RequestParser\HttpRequestParser;
use Callismart\RequestParser\Exceptions\ParserException;

$parser = new HttpRequestParser(
	$_SERVER['REQUEST_METHOD'],
	$_SERVER['CONTENT_TYPE'] ?? ''
);

try {
	$result = $parser->parse();
} catch ( ParserException $e ) {
	// $e->get_error_code() is a stable string such as 'invalid_json'.
}

$result['post'];  // Parsed body fields.
$result['files']; // Uploaded files, in the native $_FILES structure.
$result['query']; // URL query parameters.
```

## The result shape

`parse()` and `parse_all()` always return:

```php
[
	'post'  => array,
	'files' => array,
	'query' => array,
]
```

| Key     | Contents |
|---------|----------|
| `post`  | Decoded body fields. For JSON, the decoded object or array. |
| `files` | Uploaded files in the native `$_FILES` structure (`name`, `type`, `tmp_name`, `error`, `size`), including the array form for `field[]` names. |
| `query` | The URL query string, decoded with `parse_str()`, so it matches what PHP puts in `$_GET` for the same string (including its key normalization, e.g. `d.e` becomes `d_e`). |

`query` is always filled, whatever the method, Content-Type or SAPI, even when the body is skipped.

### Where the query string comes from

The last constructor argument is the query string, with or without the leading `?`. When omitted, `$_SERVER['QUERY_STRING']` is used.

```php
$parser = new HttpRequestParser( 'PUT', 'application/json', null, false, false, '?page=2&sort=asc' );
```

## Which requests are parsed

`parse()` is the "in context" API for a normal PHP request. It parses only when PHP would not have done so natively:

| Method                   | Content-Type                        | Parsed by `parse()`?             |
|--------------------------|-------------------------------------|----------------------------------|
| `GET`, `HEAD`, `OPTIONS` | any                                 | No (no body)                     |
| any other                | `application/json`                  | Yes                              |
| `PUT`, `PATCH`, `DELETE` | `multipart/form-data`               | Yes                              |
| `PUT`, `PATCH`, `DELETE` | `application/x-www-form-urlencoded` | Yes                              |
| `POST`                   | multipart or urlencoded             | No (PHP does it)                 |
| any                      | anything else                       | No, `post` and `files` are empty |

Under the CLI SAPI, `parse()` never reads a body.

`parse_all()` ignores what PHP would have done natively and parses by Content-Type alone (still skipping bodyless methods and the CLI). Use it in a standalone router, a test harness, or anywhere nothing populates the superglobals for you.

## Populating the superglobals

```php
$parser->populate_globals();
```

Merges the parsed `post` into `$_POST` and `$_REQUEST`, and `files` into `$_FILES`. Existing keys are never overwritten. It only acts when `parse()` would, and returns `false` when there was nothing to do. `$_GET` is untouched because PHP always populates it.

## Uploaded files

Files are streamed to temporary files as they are read. Three things to know:

- The files are created with `tempnam()`, so PHP does not consider them uploaded: `is_uploaded_file()` and `move_uploaded_file()` will not accept them. Use `rename()` or `copy()` instead.
- Temporary files are deleted at shutdown (and when the parser is destroyed), so move what you need during the request.
- The `type` of each file is the client-supplied MIME type, exactly as in native `$_FILES`. It is untrusted input; verify the content yourself before relying on it.

An empty file input (`filename=""`) is reported the way native PHP does: `name` `''`, `error` `UPLOAD_ERR_NO_FILE`, and no temporary file.

The `upload_tmp_prefix` constructor argument sets the `tempnam()` prefix (default `http_upload_`).

## API

### `HttpRequestParser`

```php
new HttpRequestParser(
	string $method,
	string $content_type,
	?string $upload_tmp_prefix = null,
	bool $debug = false,
	bool $verbose = false,
	?string $query_string = null
);
```

| Method | Purpose |
|--------|---------|
| `should_parse() : bool` | Whether PHP would not have populated `$_POST` / `$_FILES` natively for this method and Content-Type. |
| `parse() : array` | Parse only when `should_parse()` is true. Result is cached. |
| `parse_all() : array` | Parse by Content-Type alone. Result is cached. |
| `populate_globals() : bool` | Merge the result into `$_POST`, `$_FILES` and `$_REQUEST`. |
| `get_post_data() : array` | Parsed body fields (parses first if needed). |
| `get_files_data() : array` | Parsed files (parses first if needed). |
| `get_query_data() : array` | Parsed URL query. Does not touch the body. |
| `get_files_count() : int` | Number of files received (counts files, not field names). |
| `get_bytes_read() : int` | Bytes read from the request stream. |
| `get_temp_files() : array` | Paths of temporary files created. |
| `get_headers() : array` | All request headers, lower-case keys. |
| `get_header( string $name, ?string $default = null )` | One header, case-insensitive. |
| `get_cookies() : array` | All request cookies. |
| `get_cookie( string $name, $default = null )` | One request cookie. |
| `get_stats() : array` | Method, Content-Type, parsed flag, byte, post, files, query and temp-file counts, and the active limits. |
| `cleanup() : void` | Delete temporary files now. Also runs at shutdown. |

### Body parsers

`HttpRequestParser` delegates to one dedicated parser per Content-Type. All implement `RequestBodyParserInterface` (`parse()`, `get_bytes_read()`, `get_files_count()`, `get_temp_files()`) and return `['post' => …, 'files' => …]`.

| Class | Content-Type |
|-------|--------------|
| `MultipartRequestParser` (uses `MultipartStreamEngine`) | `multipart/form-data` |
| `JsonRequestParser` | `application/json` |
| `UrlencodedRequestParser` | `application/x-www-form-urlencoded` |

## Limits

The parser enforces your `php.ini` values while streaming: `post_max_size`, `upload_max_filesize`, `max_file_uploads` and `memory_limit` (a buffered body may not exceed a quarter of it). It also caps part headers at 64 KB, a single header line at 8 KB, a non-file form field at 1 MB, a field name at 255 characters, and field-name nesting at 10 levels.

## Errors

Every failure throws `Callismart\RequestParser\Exceptions\ParserException` (a `RuntimeException`). `get_error_code()` returns a stable string:

| Code | Raised when |
|------|-------------|
| `stream_open_failed`, `stream_read_error` | `php://input` cannot be opened or read. |
| `post_max_size_exceeded` | The body or `Content-Length` exceeds `post_max_size`. |
| `memory_limit_exceeded` | Buffered data approaches `memory_limit`. |
| `empty_body` | `Content-Length` is present and zero. |
| `invalid_json`, `invalid_json_structure` | The JSON is malformed, or does not decode to an object or array. |
| `missing_boundary`, `invalid_boundary` | The multipart boundary is absent, or violates RFC 2046. |
| `malformed_multipart`, `malformed_boundary`, `malformed_headers` | The multipart structure is broken. |
| `headers_too_large`, `header_line_too_long` | Part header limits exceeded. |
| `missing_content_disposition`, `missing_field_name` | A part lacks the headers needed to name it. |
| `field_name_too_long`, `malformed_field_name`, `excessive_nesting` | Field-name validation failed. |
| `field_too_large` | A non-file field exceeds 1 MB. |
| `max_file_uploads_exceeded`, `file_too_large` | File count or size limits exceeded. |
| `tempfile_creation_failed`, `tempfile_open_failed`, `file_write_error` | Temporary file storage failed. |

## Debugging

Pass `debug: true` to record the multipart engine's state transitions, and `verbose: true` to also write them to `error_log()`. `MultipartRequestParser::get_debug_log()` returns the log.

## License

Released under the [MIT License](LICENSE).