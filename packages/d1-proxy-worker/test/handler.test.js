import { env } from 'cloudflare:test';
import { describe, expect, it } from 'vitest';
import { createD1ProxyHandler } from '../src/handler.js';

const handler = createD1ProxyHandler( () => env.DB, { allowInsecure: true } );

async function call( path, body ) {
	const request = new Request( `http://d1.internal${ path }`, {
		method: body === undefined ? 'GET' : 'POST',
		body: body === undefined ? undefined : JSON.stringify( body ),
	} );
	const response = await handler( request );
	return { status: response.status, body: await response.json() };
}

async function query( sql, params = [] ) {
	return call( '/v1/query', { sql, params } );
}

describe( 'health', () => {
	it( 'reports the contract version', async () => {
		const { status, body } = await call( '/v1/health' );
		expect( status ).toBe( 200 );
		expect( body ).toMatchObject( { ok: true, contract: 1 } );
	} );

	it( 'supports a deep check', async () => {
		const { status, body } = await call( '/v1/health?deep=1' );
		expect( status ).toBe( 200 );
		expect( body.ok ).toBe( true );
	} );
} );

describe( 'query', () => {
	it( 'executes writes and reports meta', async () => {
		await query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		const { status, body } = await query( 'INSERT INTO t (name) VALUES (?), (?)', [ 'Alice', 'Bob' ] );
		expect( status ).toBe( 200 );
		expect( body.success ).toBe( true );
		expect( body.meta.changes ).toBe( 2 );
		expect( body.meta.last_row_id ).toBe( 2 );
	} );

	it( 'returns columnar rows for reads', async () => {
		await query( 'CREATE TABLE t_read ( id INTEGER PRIMARY KEY, name TEXT, ratio REAL )' );
		await query( 'INSERT INTO t_read (name, ratio) VALUES (?, ?)', [ 'Alice', 1.5 ] );

		const { body } = await query( 'SELECT id, name, ratio, NULL AS missing FROM t_read' );
		expect( body.columns ).toEqual( [ 'id', 'name', 'ratio', 'missing' ] );
		expect( body.rows ).toEqual( [ [ 1, 'Alice', 1.5, null ] ] );
	} );

	it( 'reports column names for empty results', async () => {
		await query( 'CREATE TABLE t_empty ( id INTEGER PRIMARY KEY, name TEXT )' );
		const { body } = await query( 'SELECT id, name FROM t_empty WHERE id = ?', [ 123 ] );
		expect( body.columns ).toEqual( [ 'id', 'name' ] );
		expect( body.rows ).toEqual( [] );
	} );

	it( 'preserves duplicate column names', async () => {
		const { body } = await query( 'SELECT 1 AS id, 2 AS id' );
		expect( body.columns ).toEqual( [ 'id', 'id' ] );
		expect( body.rows ).toEqual( [ [ 1, 2 ] ] );
	} );

	it( 'round-trips BLOB values', async () => {
		await query( 'CREATE TABLE t_blob ( data BLOB )' );
		const b64 = btoa( String.fromCharCode( 0, 1, 2, 255 ) );
		await query( 'INSERT INTO t_blob (data) VALUES (?)', [ { $type: 'blob', b64 } ] );

		const { body } = await query( 'SELECT data FROM t_blob' );
		expect( body.rows ).toEqual( [ [ { $type: 'blob', b64 } ] ] );
	} );

	it( 'reports SQLite error codes', async () => {
		await query( 'CREATE TABLE t_uniq ( id INTEGER PRIMARY KEY, name TEXT UNIQUE )' );
		await query( 'INSERT INTO t_uniq (name) VALUES (?)', [ 'Alice' ] );

		const { status, body } = await query( 'INSERT INTO t_uniq (name) VALUES (?)', [ 'Alice' ] );
		expect( status ).toBe( 409 );
		expect( body.success ).toBe( false );
		expect( body.error.code ).toBe( 'SQLITE_CONSTRAINT' );
		expect( body.error.message ).toContain( 'UNIQUE constraint failed' );
	} );

	it( 'rejects transaction control statements', async () => {
		const { status, body } = await query( 'BEGIN' );
		expect( status ).toBe( 409 );
		expect( body.success ).toBe( false );
	} );
} );

describe( 'batch', () => {
	it( 'executes statements and returns one result each', async () => {
		const { status, body } = await call( '/v1/batch', {
			statements: [
				{ sql: 'CREATE TABLE t_batch ( id INTEGER PRIMARY KEY, name TEXT )' },
				{ sql: 'INSERT INTO t_batch (name) VALUES (?)', params: [ 'Alice' ] },
				{ sql: 'INSERT INTO t_batch (name) VALUES (?), (?)', params: [ 'Bob', 'Carol' ] },
			],
		} );
		expect( status ).toBe( 200 );
		expect( body.success ).toBe( true );
		expect( body.results ).toHaveLength( 3 );
		expect( body.results[ 1 ].meta.changes ).toBe( 1 );
		expect( body.results[ 2 ].meta.changes ).toBe( 2 );
		expect( body.results[ 2 ].meta.last_row_id ).toBe( 3 );
	} );

	it( 'rolls back the whole batch on failure', async () => {
		await query( 'CREATE TABLE t_rollback ( id INTEGER PRIMARY KEY, name TEXT )' );

		const { status, body } = await call( '/v1/batch', {
			statements: [
				{ sql: 'INSERT INTO t_rollback (name) VALUES (?)', params: [ 'Alice' ] },
				{ sql: 'INSERT INTO no_such_table (name) VALUES (?)', params: [ 'Bob' ] },
			],
		} );
		expect( status ).toBe( 409 );
		expect( body.success ).toBe( false );
		expect( body.error.code ).not.toBeNull();

		const { body: after } = await query( 'SELECT COUNT(*) AS c FROM t_rollback' );
		expect( after.rows ).toEqual( [ [ 0 ] ] );
	} );

	it( 'enforces the batch statement limit', async () => {
		const limited = createD1ProxyHandler( () => env.DB, {
			allowInsecure: true,
			maxBatchStatements: 2,
		} );
		const request = new Request( 'http://d1.internal/v1/batch', {
			method: 'POST',
			body: JSON.stringify( {
				statements: [ { sql: 'SELECT 1' }, { sql: 'SELECT 2' }, { sql: 'SELECT 3' } ],
			} ),
		} );
		const response = await limited( request );
		expect( response.status ).toBe( 400 );
		expect( ( await response.json() ).error.code ).toBe( 'PROXY_BATCH_LIMIT' );
	} );
} );

describe( 'protocol validation', () => {
	it( 'rejects invalid JSON', async () => {
		const request = new Request( 'http://d1.internal/v1/query', { method: 'POST', body: '{oops' } );
		const response = await handler( request );
		expect( response.status ).toBe( 400 );
		expect( ( await response.json() ).error.code ).toBe( 'PROXY_INVALID_JSON' );
	} );

	it( 'rejects unknown endpoints', async () => {
		const { status, body } = await call( '/v2/query', { sql: 'SELECT 1' } );
		expect( status ).toBe( 404 );
		expect( body.error.code ).toBe( 'PROXY_NOT_FOUND' );
	} );

	it( 'rejects invalid statements', async () => {
		const { status, body } = await call( '/v1/query', { sql: '' } );
		expect( status ).toBe( 400 );
		expect( body.error.code ).toBe( 'PROXY_INVALID_STATEMENT' );
	} );
} );

describe( 'authorization', () => {
	it( 'refuses requests when no token is configured', async () => {
		const closed = createD1ProxyHandler( () => env.DB );
		const response = await closed( new Request( 'http://d1.internal/v1/health' ) );
		expect( response.status ).toBe( 503 );
	} );

	it( 'requires a valid bearer token when configured', async () => {
		const secured = createD1ProxyHandler( () => env.DB, { token: 'secret' } );

		const unauthorized = await secured( new Request( 'http://d1.internal/v1/health' ) );
		expect( unauthorized.status ).toBe( 401 );

		const wrong = await secured(
			new Request( 'http://d1.internal/v1/health', {
				headers: { authorization: 'Bearer nope' },
			} )
		);
		expect( wrong.status ).toBe( 401 );

		const authorized = await secured(
			new Request( 'http://d1.internal/v1/health', {
				headers: { authorization: 'Bearer secret' },
			} )
		);
		expect( authorized.status ).toBe( 200 );
	} );
} );
