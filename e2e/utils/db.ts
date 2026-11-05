import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { BASE_URL } from './env';

interface DbTarget {
  composeFile: string;
  user: string;
  database: string;
}

const repoPath = (relative: string): string => fileURLToPath(new URL(`../../${relative}`, import.meta.url));

const isDevStack = (): boolean => new URL(BASE_URL).port === '8443';

const defaultTarget = (): DbTarget => isDevStack()
  ? { composeFile: repoPath('docker/development/docker-compose.dev.yml'), user: 'username', database: 'backend' }
  : { composeFile: repoPath('docker/e2e/docker-compose.e2e.yml'), user: 'hievents', database: 'hievents_e2e' };

const dbService = (): string => process.env.E2E_DB_SERVICE ?? 'pgsql';

function dbTarget(): DbTarget {
  const defaults = defaultTarget();
  return {
    composeFile: process.env.E2E_COMPOSE_FILE ?? defaults.composeFile,
    user: process.env.E2E_DB_USER ?? defaults.user,
    database: process.env.E2E_DB_NAME ?? defaults.database,
  };
}

function runSql(sql: string): string {
  const { composeFile, user, database } = dbTarget();
  return execFileSync(
    'docker',
    ['compose', '-f', composeFile, 'exec', '-T', dbService(), 'psql', '-U', user, '-d', database, '-v', 'ON_ERROR_STOP=1', '-qtA', '-c', sql],
    { encoding: 'utf8' },
  );
}

const SHORT_ID = /^[A-Za-z0-9_]+$/;

export function expireOrder(shortId: string): void {
  if (!SHORT_ID.test(shortId)) {
    throw new Error(`Refusing to expire order with an unexpected short id: ${shortId}`);
  }
  const updated = runSql(
    `UPDATE orders SET reserved_until = NOW() - INTERVAL '1 hour' WHERE short_id = '${shortId}' RETURNING id`,
  ).trim();
  if (updated === '') {
    throw new Error(`No order found with short id ${shortId}`);
  }
}

const STRIPE_ACCOUNT_ID = /^acct_[A-Za-z0-9]+$/;

export function linkStripeConnectAccount(organizerId: number, stripeAccountId: string): void {
  if (!Number.isInteger(organizerId) || !STRIPE_ACCOUNT_ID.test(stripeAccountId)) {
    throw new Error(`Refusing to link an unexpected Stripe account ${stripeAccountId} to organizer ${organizerId}`);
  }
  runSql(
    `INSERT INTO organizer_stripe_platforms (organizer_id, stripe_connect_account_type, stripe_connect_platform, stripe_account_id, stripe_setup_completed_at, created_at, updated_at)
     SELECT ${organizerId}, 'custom', 'ie', '${stripeAccountId}', NOW(), NOW(), NOW()
     WHERE NOT EXISTS (SELECT 1 FROM organizer_stripe_platforms WHERE organizer_id = ${organizerId} AND deleted_at IS NULL)`,
  );
}

export function stripeReaderIdFor(readerId: number): string {
  if (!Number.isInteger(readerId)) {
    throw new Error(`Refusing to look up an unexpected reader id: ${readerId}`);
  }
  const stripeReaderId = runSql(`SELECT stripe_reader_id FROM stripe_terminal_readers WHERE id = ${readerId}`).trim();
  if (stripeReaderId === '') {
    throw new Error(`No terminal reader found with id ${readerId}`);
  }
  return stripeReaderId;
}

const DOMAIN = /^[a-z0-9.-]+\.[a-z]{2,}$/;

export function enableFeatureFlag(accountId: number, key: string): void {
  if (!Number.isInteger(accountId) || !/^[a-z_]+$/.test(key)) {
    throw new Error(`Refusing to enable an unexpected feature flag ${key} for account ${accountId}`);
  }
  runSql(
    `INSERT INTO account_feature_flag_overrides (account_id, feature_flag_id, enabled, created_at, updated_at)
     SELECT ${accountId}, id, true, NOW(), NOW() FROM feature_flags WHERE key = '${key}'
     ON CONFLICT (account_id, feature_flag_id) DO UPDATE SET enabled = true`,
  );
}

export function markSsoDomainVerified(accountId: number, domain: string): void {
  if (!Number.isInteger(accountId) || !DOMAIN.test(domain)) {
    throw new Error(`Refusing to verify an unexpected domain ${domain}`);
  }
  const updated = runSql(
    `UPDATE account_sso_domains SET verified_at = NOW(), last_checked_at = NOW() WHERE account_id = ${accountId} AND domain = '${domain}' RETURNING id`,
  ).trim();
  if (updated === '') {
    throw new Error(`No pending domain ${domain} on account ${accountId}`);
  }
}

export function markSsoConnectionTested(accountId: number): void {
  if (!Number.isInteger(accountId)) {
    throw new Error(`Refusing to update an unexpected account ${accountId}`);
  }
  runSql(`UPDATE account_sso_connections SET last_tested_at = NOW() WHERE account_id = ${accountId}`);
}

export function expireApiKey(apiKeyId: number): void {
  if (!Number.isInteger(apiKeyId)) {
    throw new Error(`Refusing to expire an unexpected API key ${apiKeyId}`);
  }
  const updated = runSql(
    `UPDATE personal_access_tokens SET expires_at = NOW() - INTERVAL '2 days' WHERE id = ${apiKeyId} RETURNING id`,
  ).trim();
  if (updated === '') {
    throw new Error(`No API key found with id ${apiKeyId}`);
  }
}

const PROVIDER = /^(PAYU|OFFLINE|STRIPE)$/;

/**
 * Enables payment methods for an event. The organizer's settings form is exercised by its
 * own specs; payment specs only need the event to offer the method they pay with.
 */
export function enablePaymentProviders(eventId: number, providers: string[]): void {
  if (!Number.isInteger(eventId) || eventId <= 0) {
    throw new Error(`invalid event id: ${eventId}`);
  }

  const values = providers.filter((provider) => PROVIDER.test(provider));
  if (values.length !== providers.length) {
    throw new Error(`unsupported provider in ${providers.join(',')}`);
  }

  const json = `'${JSON.stringify(values)}'::jsonb`;
  runSql(`update event_settings set payment_providers = ${json} where event_id = ${eventId}`);
}

/** Attendee statuses of an order, in creation order — used to prove a payment activated them. */
export function attendeeStatuses(shortId: string): string[] {
  if (!SHORT_ID.test(shortId)) {
    throw new Error(`invalid order short id: ${shortId}`);
  }

  const out = runSql(`select status from attendees where order_id = (select id from orders where short_id = '${shortId}') order by id`);
  return out.split('\n').map((line) => line.trim()).filter(Boolean);
}
