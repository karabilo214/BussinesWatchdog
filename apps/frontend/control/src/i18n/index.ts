import { createLocalization } from '@bw/i18n';
import de from './locales/de.json';
import en from './locales/en.json';
import ru from './locales/ru.json';

export const localization = createLocalization({ messages: { ru, en, de }, storageKey: 'bw.control.locale' });
