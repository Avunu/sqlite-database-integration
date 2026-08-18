/**
 * The D1 proxy handler.
 *
 * This module exports an environment-free handler factory that exposes a D1
 * database over a small JSON-over-HTTP protocol. It can be deployed in two
 * ways:
 *
 * 1. Embedded in a Cloudflare Containers setup, as an outbound handler on
 *    a virtual hostname. Traffic never leaves the Workers runtime and the
 *    endpoint is unreachable from the internet:
 *
 *        import { Container } from '@cloudflare/containers';
 *        import { createD1ProxyHandler } from '@wp-sqlite/d1-proxy-worker';
 *
 *        export class WordPressContainer extends Container {
 *            static outboundByHost = {
 *                'd1.internal': ( request, env ) =>
 *                    createD1ProxyHandler( () => env.DB )( request ),
 *            };
 *        }
 *
 * 2. As a standalone Worker protected by a shared bearer secret.
 *    See "worker.js".
 *
 * ## Protocol (v1)
 *
 *   POST /v1/query   { sql, params?, session? }
 *     -> { success, columns, rows, meta, bookmark? }
 *   POST /v1/batch   { statements: [ { sql, params? }, ... ], session? }
 *     -> { success, results: [ { columns, rows, meta }, ... ], bookmark? }
 *   GET  /v1/health  [?deep=1]
 *     -> { ok, version, contract }
 *
 * Rows are columnar (`columns` + positional `rows`) to preserve duplicate
 * column names and column order. BLOB values are carried as
 * `{ "$type": "blob", "b64": "<base64>" }`. Errors are reported as
 * `{ success: false, error: { code, message, statement_index? } }`.
 */

import {
	CONTRACT_VERSION,
	ProtocolError,
	isReadStatement,
	jsonResponse,
	readJsonBody,
	shapeColumnarResult,
	shapeObjectResult,
	validateStatement,
} from './protocol.js';
import { shapeD1Error } from './errors.js';

const DEFAULT_MAX_BATCH_STATEMENTS = 100;
const DEFAULT_MAX_BODY_BYTES = 1024 * 1024;

/**
 * Create a D1 proxy request handler.
 *
 * @param {() => D1Database} getDb  Returns the D1 database binding.
 * @param {object}  [options]
 * @param {?string} [options.token]              Require this bearer token.
 * @param {boolean} [options.allowInsecure]      Allow requests without a token
 *                                               when no token is configured.
 * @param {number}  [options.maxBatchStatements] Max statements per batch.
 * @param {number}  [options.maxBodyBytes]       Max request body size.
 * @param {string}  [options.version]            Version string for /v1/health.
 * @returns {( request: Request ) => Promise<Response>}
 */
export function createD1ProxyHandler( getDb, options = {} ) {
	const maxBatchStatements = options.maxBatchStatements ?? DEFAULT_MAX_BATCH_STATEMENTS;
	const maxBodyBytes = options.maxBodyBytes ?? DEFAULT_MAX_BODY_BYTES;

	return async function handle( request ) {
		try {
			const authError = await checkAuthorization( request, options );
			if ( authError ) {
				return authError;
			}

			const url = new URL( request.url );
			switch ( url.pathname ) {
				case '/v1/health':
					return await handleHealth( getDb, url, options );
				case '/v1/query':
					return await handleQuery( getDb, request, maxBodyBytes );
				case '/v1/batch':
					return await handleBatch( getDb, request, maxBodyBytes, maxBatchStatements );
				default:
					return jsonResponse( 404, {
						success: false,
						error: { code: 'PROXY_NOT_FOUND', message: `Unknown endpoint: ${ url.pathname }` },
					} );
			}
		} catch ( error ) {
			if ( error instanceof ProtocolError ) {
				return jsonResponse( error.status, {
					success: false,
					error: { code: error.code, message: error.message },
				} );
			}
			return jsonResponse( 500, {
				success: false,
				error: shapeD1Error( error ),
			} );
		}
	};
}

/**
 * Verify the bearer token, when one is configured.
 *
 * @param {Request} request The incoming request.
 * @param {object}  options The handler options.
 * @returns {Promise<?Response>} An error response, or null when authorized.
 */
async function checkAuthorization( request, options ) {
	if ( ! options.token ) {
		if ( options.allowInsecure ) {
			return null;
		}
		return jsonResponse( 503, {
			success: false,
			error: {
				code: 'PROXY_TOKEN_NOT_CONFIGURED',
				message: 'No token is configured. Set WP_D1_PROXY_TOKEN, or explicitly allow unauthenticated access.',
			},
		} );
	}

	const header = request.headers.get( 'authorization' ) ?? '';
	const provided = header.startsWith( 'Bearer ' ) ? header.slice( 7 ) : '';
	if ( ! ( await timingSafeStringEqual( provided, options.token ) ) ) {
		return jsonResponse( 401, {
			success: false,
			error: { code: 'PROXY_UNAUTHORIZED', message: 'Invalid or missing bearer token.' },
		} );
	}
	return null;
}

/**
 * Compare two strings in constant time via SHA-256 digests.
 *
 * @param {string} a
 * @param {string} b
 * @returns {Promise<boolean>}
 */
async function timingSafeStringEqual( a, b ) {
	const encoder = new TextEncoder();
	const [ digestA, digestB ] = await Promise.all( [
		crypto.subtle.digest( 'SHA-256', encoder.encode( a ) ),
		crypto.subtle.digest( 'SHA-256', encoder.encode( b ) ),
	] );
	const bytesA = new Uint8Array( digestA );
	const bytesB = new Uint8Array( digestB );
	let diff = 0;
	for ( let i = 0; i < bytesA.length; i++ ) {
		diff |= bytesA[ i ] ^ bytesB[ i ];
	}
	return diff === 0;
}

/**
 * Handle the GET /v1/health endpoint.
 */
async function handleHealth( getDb, url, options ) {
	if ( url.searchParams.get( 'deep' ) ) {
		await getDb().prepare( 'SELECT 1' ).raw();
	}
	return jsonResponse( 200, {
		ok: true,
		version: options.version ?? 'dev',
		contract: CONTRACT_VERSION,
	} );
}

/**
 * Handle the POST /v1/query endpoint.
 */
async function handleQuery( getDb, request, maxBodyBytes ) {
	const body = await readJsonBody( request, maxBodyBytes );
	const { sql, params } = validateStatement( body );
	const { db, getBookmark } = openSession( getDb(), body.session );

	let result;
	try {
		const statement = db.prepare( sql ).bind( ...params );
		if ( isReadStatement( sql ) ) {
			result = shapeColumnarResult( await statement.raw( { columnNames: true } ), null );
		} else {
			const runResult = await statement.run();
			result = shapeObjectResult( runResult.results ?? [], runResult.meta );
		}
	} catch ( error ) {
		return jsonResponse( 409, { success: false, error: shapeD1Error( error ) } );
	}

	return jsonResponse( 200, {
		success: true,
		...result,
		...bookmarkField( getBookmark ),
	} );
}

/**
 * Handle the POST /v1/batch endpoint.
 *
 * The statements are executed atomically: when any statement fails, the
 * whole batch is rolled back by D1.
 */
async function handleBatch( getDb, request, maxBodyBytes, maxBatchStatements ) {
	const body = await readJsonBody( request, maxBodyBytes );
	if ( ! Array.isArray( body?.statements ) || body.statements.length === 0 ) {
		throw new ProtocolError( 400, 'PROXY_INVALID_BATCH', 'Batch "statements" must be a non-empty array.' );
	}
	if ( body.statements.length > maxBatchStatements ) {
		throw new ProtocolError(
			400,
			'PROXY_BATCH_LIMIT',
			`Batch exceeds the limit of ${ maxBatchStatements } statements.`
		);
	}

	const statements = body.statements.map( validateStatement );
	const { db, getBookmark } = openSession( getDb(), body.session );

	let results;
	try {
		results = await db.batch(
			statements.map( ( { sql, params } ) => db.prepare( sql ).bind( ...params ) )
		);
	} catch ( error ) {
		return jsonResponse( 409, { success: false, error: shapeD1Error( error ) } );
	}

	return jsonResponse( 200, {
		success: true,
		results: results.map( ( result ) => shapeObjectResult( result.results ?? [], result.meta ) ),
		...bookmarkField( getBookmark ),
	} );
}

/**
 * Open a D1 session when requested, for sequential read consistency.
 *
 * @param {D1Database} db      The D1 database binding.
 * @param {any}        session The "session" request field, if any.
 * @returns {{ db: any, getBookmark: ?(() => ?string) }}
 */
function openSession( db, session ) {
	if ( typeof session !== 'object' || session === null || typeof db.withSession !== 'function' ) {
		return { db, getBookmark: null };
	}
	const constraint = typeof session.bookmark === 'string'
		? session.bookmark
		: ( session.constraint ?? 'first-unconstrained' );
	const d1Session = db.withSession( constraint );
	return {
		db: d1Session,
		getBookmark: () => d1Session.getBookmark(),
	};
}

/**
 * Build the optional "bookmark" response field.
 *
 * @param {?(() => ?string)} getBookmark The session bookmark getter, if any.
 * @returns {object}
 */
function bookmarkField( getBookmark ) {
	const bookmark = getBookmark ? getBookmark() : null;
	return bookmark ? { bookmark } : {};
}
