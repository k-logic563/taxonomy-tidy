import { execFileSync } from 'node:child_process';
import path from 'node:path';

export type E2EState = {
  terms: Record<string, { exists: boolean; id?: number; name?: string; slug?: string; taxonomy: string }>;
  posts: Record<string, {
    exists: boolean;
    id?: number;
    title?: string;
    status?: string;
    relationships?: Record<'category' | 'post_tag', string[]>;
  }>;
  persistence: {
    operations: Array<{ status: string; count: string }>;
    items: number;
    journals: number;
    duplicate_items: number;
    duplicate_journals: number;
  };
};

const root = path.resolve(__dirname, '../../..');

function run(command: 'reset' | 'state'): string {
  return execFileSync(path.join(root, 'bin/e2e.sh'), [command], {
    cwd: root,
    encoding: 'utf8',
    env: process.env,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

export function resetE2EState(): void {
  run('reset');
}

export function readE2EState(): E2EState {
  const output = run('state');
  const json = output.split('\n').find((line) => line.trim().startsWith('{'));
  if (!json) {
    throw new Error('The E2E state helper did not return JSON.');
  }
  return JSON.parse(json) as E2EState;
}

export function operationCount(state: E2EState, status: string): number {
  return Number(state.persistence.operations.find((row) => row.status === status)?.count ?? 0);
}
