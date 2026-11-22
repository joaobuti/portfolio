import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http';

import { type ApiError, parseQuoteRequest } from './contract.js';
import { AmountOverflowError } from './money.js';
import { quote } from './quote.js';

/**
 * The HTTP surface: two routes, no framework.
 *
 * Express or Fastify would earn their place in a service with middleware,
 * sessions and thirty endpoints. This one has a health check and a pure
 * function behind a POST. Node's own http module covers it, which means this
 * service has **zero runtime dependencies** — nothing to audit, nothing to
 * patch on a CVE Friday, and a container image that is the base image plus a
 * few kilobytes of JavaScript.
 *
 * The billing core calls this synchronously in the path of a plan change, so
 * everything here is written to fail fast and predictably: bounded body size,
 * no keep-alive surprises, and errors in the same envelope as the happy path.
 */

/** Bigger than any legitimate quote request; a body past this is not one. */
const MAX_BODY_BYTES = 8 * 1024;

export function createApp(): Server {
  const server = createServer((request, response) => {
    handle(request, response).catch((error: unknown) => {
      // Any throw that reaches here is a bug in this service, not bad input.
      // The client is told so with a 500 it can treat as a transport failure
      // and degrade around, rather than a 4xx it would treat as its own fault.
      console.error('unhandled request error', error);
      send(response, 500, {
        error: { code: 'internal_error', message: 'The pricing service failed to handle the request.' },
      });
    });
  });

  // A request that opens a socket and says nothing must not hold a worker
  // slot open forever. The caller's shorter timeout controls its fallback.
  server.requestTimeout = 5_000;
  server.headersTimeout = 5_000;

  return server;
}

async function handle(request: IncomingMessage, response: ServerResponse): Promise<void> {
  const url = new URL(request.url ?? '/', 'http://localhost');

  if (request.method === 'GET' && url.pathname === '/health') {
    // Deliberately trivial: this service holds no connections and reads no
    // datastore, so "the process is answering" is the whole of its health.
    // A health check that lies about a dependency is worse than none.
    send(response, 200, { status: 'ok' });

    return;
  }

  if (request.method === 'POST' && url.pathname === '/v1/quote') {
    await handleQuote(request, response);

    return;
  }

  send(response, 404, {
    error: { code: 'not_found', message: `No route for ${request.method} ${url.pathname}.` },
  });
}

async function handleQuote(request: IncomingMessage, response: ServerResponse): Promise<void> {
  let raw: string;

  try {
    raw = await readBody(request);
  } catch (error) {
    response.setHeader('connection', 'close');
    send(response, 413, {
      error: {
        code: 'payload_too_large',
        message: (error as Error).message,
      },
    });

    return;
  }

  let body: unknown;

  try {
    body = JSON.parse(raw === '' ? 'null' : raw);
  } catch {
    send(response, 400, {
      error: { code: 'invalid_json', message: 'The request body is not valid JSON.' },
    });

    return;
  }

  const parsed = parseQuoteRequest(body);

  if (!parsed.ok) {
    send(response, 422, parsed.error);

    return;
  }

  try {
    send(response, 200, quote(parsed.value));
  } catch (error) {
    if (error instanceof AmountOverflowError || error instanceof TypeError || error instanceof RangeError) {
      send(response, 422, {
        error: { code: 'unprocessable_amount', message: error.message },
      });

      return;
    }

    throw error;
  }
}

function readBody(request: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    let size = 0;

    request.on('data', (chunk: Buffer) => {
      size += chunk.length;

      if (size > MAX_BODY_BYTES) {
        // Stop reading immediately. Buffering an unbounded body is how a
        // small service becomes a memory-exhaustion target.
        chunks.length = 0;
        request.pause();
        reject(new Error(`The request body exceeds ${MAX_BODY_BYTES} bytes.`));

        return;
      }

      chunks.push(chunk);
    });

    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    request.on('error', reject);
  });
}

function send(response: ServerResponse, status: number, payload: ApiError | object): void {
  if (response.writableEnded) {
    return;
  }

  const body = JSON.stringify(payload);

  response.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': Buffer.byteLength(body),
  });
  response.end(body);
}
