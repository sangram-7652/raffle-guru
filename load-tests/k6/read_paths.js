// P12.3 — baseline load test for the public, read-heavy paths
// (competition listing, competition detail, health). See
// docs/LOAD_AND_CONCURRENCY.md "Load-test methodology".
//
// Usage (against local Docker Compose, the default and only target this
// script allows without an explicit override):
//
//   docker run --rm --network host \
//     -e BASE_URL=http://localhost:8080 \
//     -v "$PWD/load-tests/k6:/scripts" \
//     grafana/k6 run /scripts/read_paths.js
//
// PRODUCTION SAFETY (Step 14): this script refuses to run — exits
// before issuing a single request — unless BASE_URL's host is
// "localhost" or "127.0.0.1", UNLESS ALLOW_NON_LOCAL_TARGET is set to
// the exact token "STAGING_CONFIRMED". There is no way to point this at
// an arbitrary/production host by accident: a typo'd env var, a copied
// command, or a missing flag all fail closed, not open.

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const ALLOW_NON_LOCAL_TARGET = __ENV.ALLOW_NON_LOCAL_TARGET || '';

function assertSafeTarget(url) {
  let host;
  try {
    host = url.replace(/^https?:\/\//, '').split(/[/:]/)[0];
  } catch (e) {
    throw new Error(`P12.3 load-test safety guard: could not parse BASE_URL "${url}".`);
  }

  const isLocal = host === 'localhost' || host === '127.0.0.1';

  if (!isLocal && ALLOW_NON_LOCAL_TARGET !== 'STAGING_CONFIRMED') {
    throw new Error(
      `P12.3 load-test safety guard: refusing to run against "${host}". ` +
      'BASE_URL must be localhost/127.0.0.1, or set ALLOW_NON_LOCAL_TARGET=STAGING_CONFIRMED ' +
      'for an explicitly isolated staging environment. NEVER target production — see ' +
      'docs/LOAD_AND_CONCURRENCY.md "What must NOT be load-tested in production".',
    );
  }
}

// Runs once, before any VU iteration — a bad target aborts the whole
// run immediately rather than after already sending traffic.
assertSafeTarget(BASE_URL);

export const options = {
  scenarios: {
    competition_listing: {
      executor: 'constant-vus',
      exec: 'listing',
      vus: 10,
      duration: '30s',
    },
    competition_detail: {
      executor: 'constant-vus',
      exec: 'detail',
      vus: 10,
      duration: '30s',
      startTime: '30s',
    },
    health: {
      executor: 'constant-vus',
      exec: 'health',
      vus: 2,
      duration: '30s',
      startTime: '60s',
    },
  },
  // Not pass/fail gates for this exploratory baseline run (see
  // docs/LOAD_AND_CONCURRENCY.md "Baseline results") — just documents
  // the bar a future CI load-test smoke run could enforce.
  thresholds: {
    http_req_failed: ['rate<0.01'],
  },
};

export function listing() {
  const res = http.get(`${BASE_URL}/api/v1/competitions`);
  check(res, { 'listing status is 200': (r) => r.status === 200 });
  sleep(0.2);
}

export function detail() {
  const res = http.get(`${BASE_URL}/api/v1/competitions/${__ENV.DETAIL_SLUG || 'dolores-aperiam-unde-giveaway-3358'}`);
  check(res, { 'detail status is 200': (r) => r.status === 200 });
  sleep(0.2);
}

export function health() {
  const res = http.get(`${BASE_URL}/api/v1/health`);
  check(res, { 'health status is 200': (r) => r.status === 200 });
  sleep(0.5);
}
