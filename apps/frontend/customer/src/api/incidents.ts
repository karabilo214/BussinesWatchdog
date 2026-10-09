import { newIdempotencyKey, request } from '@bw/api-client';
import type { ArtifactLink, CheckRun, Incident, IncidentActivity, IncidentDetail, IncidentSeverity, IncidentState, Page, Suppression } from './types';

export interface IncidentFilters {
  store_id?: string;
  state?: IncidentState[];
  severity?: IncidentSeverity;
  cursor?: string;
  limit?: number;
}

export async function listIncidents(filters: IncidentFilters): Promise<Page<Incident>> {
  return (
    await request<Page<Incident>>('GET', '/api/v1/incidents', {
      query: {
        store_id: filters.store_id,
        state: filters.state?.join(','),
        severity: filters.severity,
        cursor: filters.cursor,
        limit: filters.limit,
      },
    })
  ).data;
}

export async function getIncident(id: string): Promise<IncidentDetail> {
  return (await request<IncidentDetail>('GET', `/api/v1/incidents/${encodeURIComponent(id)}`)).data;
}

export async function acknowledgeIncident(incident: Incident): Promise<Incident> {
  return (await request<Incident>('POST', `/api/v1/incidents/${encodeURIComponent(incident.id)}/acknowledge`, { ifMatch: incident.revision })).data;
}

export async function resolveIncident(incident: Incident, reason: string): Promise<Incident> {
  return (await request<Incident>('POST', `/api/v1/incidents/${encodeURIComponent(incident.id)}/resolve`, { ifMatch: incident.revision, body: { reason } })).data;
}

export async function commentIncident(incidentId: string, text: string): Promise<IncidentActivity> {
  return (await request<IncidentActivity>('POST', `/api/v1/incidents/${encodeURIComponent(incidentId)}/comments`, { body: { text } })).data;
}

export async function snoozeIncident(incidentId: string, until: Date, reason: string): Promise<Suppression> {
  return (await request<Suppression>('POST', `/api/v1/incidents/${encodeURIComponent(incidentId)}/snooze`, { body: { until: until.toISOString(), reason } })).data;
}

export async function revokeSuppression(suppressionId: string): Promise<Suppression> {
  return (await request<Suppression>('POST', `/api/v1/suppressions/${encodeURIComponent(suppressionId)}/revoke`)).data;
}

/** Re-runs reconciliation for the given orders, or the store-wide unmatched-payment scan without orders. */
export async function recheckMoney(storeId: string, orderIds: string[]): Promise<void> {
  await request('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/reconciliations`, {
    body: orderIds.length > 0 ? { order_ids: orderIds } : {},
    idempotencyKey: newIdempotencyKey(),
  });
}

export async function getCheckRun(runId: string): Promise<CheckRun> {
  return (await request<CheckRun>('GET', `/api/v1/checks/${encodeURIComponent(runId)}`)).data;
}

export async function startManualCheck(storeId: string, scenarioId: string): Promise<CheckRun> {
  return (await request<CheckRun>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/checks`, { body: { scenario_id: scenarioId }, idempotencyKey: newIdempotencyKey() })).data;
}

export async function artifactLink(artifactId: string): Promise<ArtifactLink> {
  return (await request<ArtifactLink>('GET', `/api/v1/artifacts/${encodeURIComponent(artifactId)}/download`)).data;
}
