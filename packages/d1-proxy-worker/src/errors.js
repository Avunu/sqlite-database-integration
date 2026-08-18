/**
 * Mapping of D1 errors to structured protocol errors.
 *
 * D1 exposes SQLite errors as `Error` objects with messages of the form:
 *
 *   D1_ERROR: UNIQUE constraint failed: t.id: SQLITE_CONSTRAINT
 *   D1_EXEC_ERROR: Error in line 1: ...: SQLITE_ERROR
 *
 * The trailing `SQLITE_*` constant is extracted as a machine-readable code,
 * and the message is preserved for the client to interpret and map to MySQL
 * error semantics.
 */

/**
 * Extract a structured error from a D1/SQLite error.
 *
 * @param {unknown} error          The thrown error.
 * @param {?number} statementIndex The index of the failed statement in a
 *                                 batch, when known.
 * @returns {{ code: ?string, message: string, statement_index?: number }}
 */
export function shapeD1Error( error, statementIndex = null ) {
	const message = error instanceof Error ? error.message : String( error );
	const match = message.match( /(SQLITE_[A-Z_]+)/ );

	const shaped = {
		code: match ? match[ 1 ] : null,
		message,
	};
	if ( statementIndex !== null ) {
		shaped.statement_index = statementIndex;
	}
	return shaped;
}
