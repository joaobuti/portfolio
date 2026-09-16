import { createApp } from './server.js';

/**
 * Entry point. Reads the port, starts the server, and shuts down cleanly.
 *
 * The graceful shutdown is not ceremony: this service is called synchronously
 * in the path of a plan change, so a deploy that kills the process mid-request
 * turns into a degraded quote — or, worse, a retry that double-applies a
 * coupon. `server.close()` stops accepting new connections and lets in-flight
 * requests finish first.
 */

const port = Number.parseInt(process.env.PORT ?? '3000', 10);

if (!Number.isSafeInteger(port) || port <= 0 || port > 65_535) {
  console.error(`Invalid PORT [${process.env.PORT}].`);
  process.exit(1);
}

const server = createApp();

server.listen(port, () => {
  console.log(JSON.stringify({ level: 'info', message: 'pricing service listening', port }));
});

for (const signal of ['SIGTERM', 'SIGINT'] as const) {
  process.on(signal, () => {
    console.log(JSON.stringify({ level: 'info', message: 'shutting down', signal }));

    server.close((error) => {
      if (error) {
        console.error('shutdown failed', error);
        process.exit(1);
      }

      process.exit(0);
    });

    // If connections refuse to drain, stop waiting. Kubernetes and Compose
    // will SIGKILL us shortly anyway; exiting on our own terms keeps the logs
    // honest about what happened.
    setTimeout(() => process.exit(1), 10_000).unref();
  });
}
