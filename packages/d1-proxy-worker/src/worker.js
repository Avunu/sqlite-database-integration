/**
 * Standalone Worker entrypoint for the D1 proxy.
 *
 * This wraps the environment-free handler for deployment as a dedicated
 * Worker, authenticating requests with a shared bearer secret:
 *
 *   wrangler secret put WP_D1_PROXY_TOKEN
 *
 * When no token is configured, requests are refused, unless the
 * WP_D1_PROXY_ALLOW_INSECURE variable is set to "true" (intended only for
 * local development with `wrangler dev`).
 */

import { createD1ProxyHandler } from './handler.js';

export default {
	/**
	 * @param {Request} request The incoming HTTP request.
	 * @param {object}  env     The Worker environment bindings.
	 * @returns {Promise<Response>}
	 */
	async fetch( request, env ) {
		const handler = createD1ProxyHandler( () => env.DB, {
			token: env.WP_D1_PROXY_TOKEN ?? null,
			allowInsecure: env.WP_D1_PROXY_ALLOW_INSECURE === 'true',
			maxBatchStatements: env.WP_D1_PROXY_MAX_BATCH_STATEMENTS
				? Number( env.WP_D1_PROXY_MAX_BATCH_STATEMENTS )
				: undefined,
			version: env.WP_D1_PROXY_VERSION ?? 'dev',
		} );
		return handler( request );
	},
};
