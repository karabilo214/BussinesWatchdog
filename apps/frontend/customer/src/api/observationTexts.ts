import { request } from '@bw/api-client';

export interface ObservationTextSet {
  privacy: string;
  lia: string;
}

export interface ObservationTexts {
  provider_set: boolean;
  texts: { de: ObservationTextSet; en: ObservationTextSet };
}

export async function getObservationTexts(): Promise<ObservationTexts> {
  return (await request<ObservationTexts>('GET', '/api/v1/downloads/observation-texts')).data;
}
