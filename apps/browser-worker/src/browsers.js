import { chromium, firefox, webkit } from 'playwright';

export const ENGINES = { chromium, firefox, webkit };

export function browserNames(value) {
  const names = String(value ?? 'chromium,firefox,webkit').split(',').map((name) => name.trim().toLowerCase()).filter((name) => name in ENGINES);
  const unique = [...new Set(names)];

  return unique.includes('chromium') ? ['chromium', ...unique.filter((name) => name !== 'chromium')] : ['chromium', ...unique];
}

export function launchOptions(name, config) {
  return {
    headless: true,
    ...(name === 'chromium' ? { chromiumSandbox: config.chromiumSandbox } : {}),
    ...(name === 'chromium' && config.chromiumExecutablePath !== '' ? { executablePath: config.chromiumExecutablePath } : {}),
    ...(config.egressProxy ? { proxy: { server: config.egressProxy, bypass: name === 'chromium' ? '<-loopback>' : 'localhost,127.0.0.1' } } : {}),
  };
}

export async function launchBrowsers(config, log, engines = ENGINES) {
  const instances = {};
  const versions = {};

  for (const name of config.browsers) {
    try {
      const browser = await engines[name].launch(launchOptions(name, config));
      instances[name] = browser;
      versions[name] = `${name}-${browser.version()}`;
    } catch (error) {
      if (name === 'chromium') {
        throw error;
      }

      log('browser_unavailable', { browser: name, error: error?.name ?? 'Error', message: String(error?.message ?? '').split('\n')[0].slice(0, 200) });
    }
  }

  return {
    versions,
    pick(lease) {
      const name = lease?.browser in instances ? lease.browser : 'chromium';

      return { name, browser: instances[name], version: versions[name] };
    },
    async close() {
      await Promise.all(Object.values(instances).map((browser) => browser.close().catch(() => null)));
    },
  };
}
