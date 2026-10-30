import { request } from '@bw/api-client';

export type SalesMode = 'live' | 'test';
export type ManualExclusionKind = 'promotion' | 'holiday';
export interface SalesExclusion {
  date: string;
  kind: ManualExclusionKind | 'pause' | 'data_gap';
  timezone: string;
  from: string;
  to: string;
  editable: boolean;
}
export interface SalesExclusions {
  timezone: string;
  min_date: string;
  max_date: string;
  mode: SalesMode;
  entries: SalesExclusion[];
  calendar: SalesCalendar;
  holidays: CalendarHoliday[];
  calendar_state: CalendarState;
}
export interface SalesExclusionInput {
  date: string;
  kind: ManualExclusionKind;
  mode: SalesMode;
  excluded: boolean;
}

export async function getSalesExclusions(storeId: string, mode: SalesMode): Promise<SalesExclusions> {
  return (await request<SalesExclusions>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/sales-exclusions`, { query: { mode } })).data;
}

export async function setSalesExclusion(storeId: string, input: SalesExclusionInput): Promise<SalesExclusions> {
  return (await request<SalesExclusions>('PUT', `/api/v1/stores/${encodeURIComponent(storeId)}/sales-exclusions`, { body: input })).data;
}

export const SALES_CALENDAR_REGIONS = ['DE-BW', 'DE-BY', 'DE-BE', 'DE-BB', 'DE-HB', 'DE-HH', 'DE-HE', 'DE-MV', 'DE-NI', 'DE-NW', 'DE-RP', 'DE-SL', 'DE-SN', 'DE-ST', 'DE-SH', 'DE-TH'] as const;
export type SalesCalendarRegion = (typeof SALES_CALENDAR_REGIONS)[number];
export type LocalHoliday = 'assumption' | 'augsburg_peace' | 'corpus_christi';
export interface SalesCalendar {
  region: SalesCalendarRegion | null;
  local_holidays: LocalHoliday[];
  version: number;
  dataset_version: string;
  supported_years: number[];
}
export interface CalendarHoliday {
  date: string;
  codes: string[];
  comparison_weekday: 7;
}
export type CalendarState = 'not_configured' | 'ready' | 'out_of_range';

export async function setSalesCalendar(storeId: string, configuration: SalesCalendar, region: SalesCalendarRegion | null, localHolidays: LocalHoliday[]): Promise<SalesCalendar> {
  return (await request<SalesCalendar>('PUT', `/api/v1/stores/${encodeURIComponent(storeId)}/sales-calendar`, { ifMatch: configuration.version, body: { region, local_holidays: localHolidays } })).data;
}
