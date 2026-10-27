import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as pluginApi from '@/api/plugin';
import PluginDownload from '@/components/PluginDownload.vue';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/plugin', () => ({ PLUGIN_DOWNLOAD_URL: '/api/v1/downloads/woocommerce-plugin/file', getPluginPackage: vi.fn() }));

describe('plugin download', () => {
  it('offers the newest plugin ZIP with its version, size and checksum', async () => {
    setLocale('ru');
    vi.mocked(pluginApi.getPluginPackage).mockResolvedValue({ available: true, version: '0.10.2', file: 'business-watchdog-0.10.2.zip', size_bytes: 52_000, sha256: 'abc123' });
    const wrapper = mount(PluginDownload, { global: { plugins: [localization] } });
    await flushPromises();

    const link = wrapper.get('[data-action="download-plugin"]');
    expect(link.attributes('href')).toBe('/api/v1/downloads/woocommerce-plugin/file');
    expect(link.attributes('download')).toBe('business-watchdog-0.10.2.zip');
    expect(wrapper.text()).toContain('версия 0.10.2, 51 КБ');
    expect(wrapper.text()).toContain('abc123');
  });

  it('points to the WordPress.org directory and opens the connection guide from there too', async () => {
    setLocale('ru');
    for (const pack of [{ available: true, version: '0.4.0', file: 'business-watchdog-0.4.0.zip', size_bytes: 62_000, sha256: 'abc' }, { available: false }]) {
      vi.mocked(pluginApi.getPluginPackage).mockResolvedValue(pack);
      const wrapper = await mountAndWait();
      const link = wrapper.get('[data-action="plugin-directory"]');
      expect(link.attributes('href')).toBe('https://wordpress.org/plugins/business-watchdog/');
      expect(link.attributes('target')).toBe('_blank');
      expect(wrapper.get('[data-plugin-directory]').text()).toContain('найдите «Business Watchdog»');
      await link.trigger('click');
      expect(wrapper.emitted('downloaded')).toHaveLength(1);
    }
  });

  it('explains when no package is published or the request fails', async () => {
    vi.mocked(pluginApi.getPluginPackage).mockResolvedValue({ available: false });
    expect((await mountAndWait()).find('[data-plugin-missing]').exists()).toBe(true);
    vi.mocked(pluginApi.getPluginPackage).mockRejectedValue(new Error('offline'));
    expect((await mountAndWait()).find('[data-plugin-missing]').exists()).toBe(true);
  });
});

async function mountAndWait() {
  const wrapper = mount(PluginDownload, { global: { plugins: [localization] } });
  await flushPromises();

  return wrapper;
}
