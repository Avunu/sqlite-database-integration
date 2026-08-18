/**
 * Protocol helpers for the D1 proxy: request validation, SQLite value
 * encoding/decoding, and response shaping.
 *
 * Values are carried as JSON. SQLite BLOB values, which JSON cannot express,
 * are wrapped as `{ "$type": "blob", "b64": "<base64>" }` in both directions.
 *
 * Note that JavaScript numbers lose precision beyond 2^53. SQLite INTEGER
 * values outside of that range are not supported by this protocol.
 */

/**
 * The protocol version reported by the health endpoint.
 */
export const CONTRACT_VERSION = 1;

/**
 * An error carrying an HTTP status and a protocol error code.
 */
export class ProtocolError extends Error {
	/**
	 * @param {number} status  The HTTP status code.
	 * @param {string} code    A stable, machine-readable error code.
	 * @param {string} message A human-readable error message.
	 */
	constructor( status, code, message ) {
		super( message );
		this.status = status;
		this.code = code;
	}
}

/**
 * Parse and validate a JSON request body.
 *
 * @param {Request} request      The incoming HTTP request.
 * @param {number}  maxBodyBytes The maximum accepted body size in bytes.
 * @returns {Promise<any>}       The parsed JSON body.
 * @throws {ProtocolError}       On invalid or too large bodies.
 */
export async function readJsonBody( request, maxBodyBytes ) {
	const contentLength = Number( request.headers.get( 'content-length' ) ?? 0 );
	if ( contentLength > maxBodyBytes ) {
		throw new ProtocolError( 413, 'PROXY_BODY_TOO_LARGE', `Request body exceeds ${ maxBodyBytes } bytes.` );
	}

	const text = await request.text();
	if ( text.length > maxBodyBytes ) {
		throw new ProtocolError( 413, 'PROXY_BODY_TOO_LARGE', `Request body exceeds ${ maxBodyBytes } bytes.` );
	}

	try {
		return JSON.parse( text );
	} catch {
		throw new ProtocolError( 400, 'PROXY_INVALID_JSON', 'Request body is not valid JSON.' );
	}
}

/**
 * Validate a statement object (`{ sql, params? }`) and decode its parameters.
 *
 * @param {any} statement The statement input to validate.
 * @returns {{ sql: string, params: any[] }} The validated statement.
 * @throws {ProtocolError} On invalid input.
 */
export function validateStatement( statement ) {
	if ( typeof statement !== 'object' || statement === null ) {
		throw new ProtocolError( 400, 'PROXY_INVALID_STATEMENT', 'Statement must be an object.' );
	}
	if ( typeof statement.sql !== 'string' || statement.sql.length === 0 ) {
		throw new ProtocolError( 400, 'PROXY_INVALID_STATEMENT', 'Statement "sql" must be a non-empty string.' );
	}
	if ( statement.params !== undefined && ! Array.isArray( statement.params ) ) {
		throw new ProtocolError( 400, 'PROXY_INVALID_STATEMENT', 'Statement "params" must be an array.' );
	}
	return {
		sql: statement.sql,
		params: ( statement.params ?? [] ).map( decodeValue ),
	};
}

/**
 * Decode a JSON-carried parameter value to a D1-bindable value.
 *
 * @param {any} value The JSON value.
 * @returns {any}     The value to bind.
 * @throws {ProtocolError} On unsupported values.
 */
export function decodeValue( value ) {
	if ( value === null || typeof value === 'number' || typeof value === 'string' || typeof value === 'boolean' ) {
		return value;
	}
	if ( typeof value === 'object' && value.$type === 'blob' && typeof value.b64 === 'string' ) {
		const binary = atob( value.b64 );
		const bytes = new Uint8Array( binary.length );
		for ( let i = 0; i < binary.length; i++ ) {
			bytes[ i ] = binary.charCodeAt( i );
		}
		return bytes.buffer;
	}
	throw new ProtocolError( 400, 'PROXY_INVALID_PARAM', 'Unsupported parameter value.' );
}

/**
 * Encode a D1 result value into its JSON-carried representation.
 *
 * D1 returns BLOB values as arrays of bytes (or ArrayBuffers); these are
 * wrapped as base64 blob objects. All other values are JSON-native.
 *
 * @param {any} value The D1 result value.
 * @returns {any}     The JSON-encodable value.
 */
export function encodeValue( value ) {
	if ( value instanceof ArrayBuffer ) {
		return encodeBlob( new Uint8Array( value ) );
	}
	if ( Array.isArray( value ) ) {
		// D1 represents BLOB values as arrays of byte numbers.
		return encodeBlob( Uint8Array.from( value ) );
	}
	return value;
}

/**
 * Encode a byte array as a base64 blob object.
 *
 * @param {Uint8Array} bytes The bytes to encode.
 * @returns {{ $type: 'blob', b64: string }} The blob object.
 */
function encodeBlob( bytes ) {
	let binary = '';
	for ( let i = 0; i < bytes.length; i++ ) {
		binary += String.fromCharCode( bytes[ i ] );
	}
	return { $type: 'blob', b64: btoa( binary ) };
}

/**
 * Shape a columnar result (from `stmt.raw( { columnNames: true } )`).
 *
 * @param {any[][]} rawRows The raw rows; the first entry is the column names.
 * @param {any}     meta    The D1 result meta, when available.
 * @returns {{ columns: string[], rows: any[][], meta: object }}
 */
export function shapeColumnarResult( rawRows, meta ) {
	const [ columns = [], ...rows ] = rawRows;
	return {
		columns,
		rows: rows.map( ( row ) => row.map( encodeValue ) ),
		meta: shapeMeta( meta ),
	};
}

/**
 * Shape an object-rows result (from `stmt.run()` or `db.batch()`).
 *
 * Note that object rows cannot represent duplicate column names; the last
 * duplicate wins. The SQLite driver only executes writes through this path,
 * where result rows are not expected.
 *
 * @param {object[]} results The result rows as objects.
 * @param {any}      meta    The D1 result meta.
 * @returns {{ columns: string[], rows: any[][], meta: object }}
 */
export function shapeObjectResult( results, meta ) {
	const columns = results.length > 0 ? Object.keys( results[ 0 ] ) : [];
	return {
		columns,
		rows: results.map( ( row ) => columns.map( ( name ) => encodeValue( row[ name ] ) ) ),
		meta: shapeMeta( meta ),
	};
}

/**
 * Shape a D1 result meta object, keeping only stable, documented fields.
 *
 * @param {any} meta The D1 result meta, when available.
 * @returns {object} The shaped meta.
 */
export function shapeMeta( meta ) {
	return {
		changes: meta?.changes ?? 0,
		last_row_id: meta?.last_row_id ?? 0,
		rows_read: meta?.rows_read ?? 0,
		rows_written: meta?.rows_written ?? 0,
		duration: meta?.duration ?? 0,
		served_by_primary: meta?.served_by_primary ?? null,
	};
}

/**
 * Check whether an SQL statement is a read (returns rows without writing).
 *
 * This decides how a statement is executed against D1:
 *
 *   - Reads use `raw( { columnNames: true } )`, which reports column names
 *     accurately (including for empty results and duplicate column names),
 *     but returns no meta.
 *   - Writes use `run()`, which reports accurate meta (changes/last_row_id),
 *     while column names are reconstructed from result rows, if any.
 *
 * The SQLite driver only emits statements falling cleanly into these two
 * classes; the meta is meaningless for reads, and result column naming is
 * meaningless for writes.
 *
 * @param {string} sql The SQL statement.
 * @returns {boolean}  True when the statement is a read.
 */
export function isReadStatement( sql ) {
	const match = sql.match( /^\s*([a-zA-Z]+)/ );
	if ( ! match ) {
		return false;
	}
	const keyword = match[ 1 ].toUpperCase();
	return keyword === 'SELECT' || keyword === 'PRAGMA' || keyword === 'EXPLAIN' || keyword === 'WITH';
}

/**
 * Create a JSON HTTP response.
 *
 * @param {number} status The HTTP status code.
 * @param {any}    body   The JSON-encodable response body.
 * @returns {Response}
 */
export function jsonResponse( status, body ) {
	return new Response( JSON.stringify( body ), {
		status,
		headers: { 'content-type': 'application/json' },
	} );
}
