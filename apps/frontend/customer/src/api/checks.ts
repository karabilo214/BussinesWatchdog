import { newIdempotencyKey, request } from '@bw/api-client';
import type { CheckRunDetail, CheckRunSummary, CheckScenario, CheckScenarioInput, Page } from './types';

export async function listScenarios(storeId: string): Promise<CheckScenario[]> {
  return (await request<Page<CheckScenario>>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/scenarios`)).data.data;
}

export async function createScenario(storeId: string, input: CheckScenarioInput): Promise<CheckScenario> {
  return (await request<CheckScenario>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/scenarios`, { body: input })).data;
}

export async function updateScenario(scenario: CheckScenario, changes: CheckScenarioInput): Promise<CheckScenario> {
  return (await request<CheckScenario>('PATCH', `/api/v1/scenarios/${encodeURIComponent(scenario.id)}`, { body: changes, ifMatch: scenario.version })).data;
}

export async function listRuns(storeId: string, cursor?: string): Promise<Page<CheckRunSummary>> {
  return (await request<Page<CheckRunSummary>>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/checks`, { query: { cursor, limit: 20 } })).data;
}

export async function getRun(runId: string): Promise<CheckRunDetail> {
  return (await request<CheckRunDetail>('GET', `/api/v1/checks/${encodeURIComponent(runId)}`)).data;
}

export async function startRun(storeId: string, scenarioId: string): Promise<CheckRunSummary> {
  return (await request<CheckRunSummary>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/checks`, { body: { scenario_id: scenarioId }, idempotencyKey: newIdempotencyKey() })).data;
}

export async function cancelRun(runId: string, reason: string): Promise<CheckRunSummary> {
  return (await request<CheckRunSummary>('POST', `/api/v1/checks/${encodeURIComponent(runId)}/cancel`, { body: { reason } })).data;
}
