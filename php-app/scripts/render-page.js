#!/usr/bin/env node

const { chromium } = require('playwright');

const url = process.argv[2];
const timeoutMs = Math.max(5000, Number(process.argv[3] || 45000));

function trimText(value, max = 60000) {
  return String(value || '').replace(/\s+/g, ' ').trim().slice(0, max);
}

async function main() {
  if (!url || !/^https?:\/\//i.test(url)) {
    throw new Error('Usage: node scripts/render-page.js <http-url> [timeoutMs]');
  }

  const networkJson = [];
  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });

  try {
    const context = await browser.newContext({
      locale: 'ja-JP',
      userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
        '(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
      viewport: { width: 1366, height: 768 },
      extraHTTPHeaders: {
        'Accept-Language': 'ja-JP,ja;q=0.9,en-US;q=0.7,en;q=0.6',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Upgrade-Insecure-Requests': '1',
      },
    });

    // Stealth: mask headless/automation signals that trigger bot-detection
    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => false });
      Object.defineProperty(navigator, 'plugins', {
        get: () => Object.assign([], { length: 3, item: () => null, namedItem: () => null, refresh: () => {} }),
      });
      Object.defineProperty(navigator, 'languages', { get: () => ['ja-JP', 'ja', 'en-US', 'en'] });
      if (!window.chrome) {
        window.chrome = { runtime: {}, loadTimes: () => ({}), csi: () => ({}) };
      }
      // Prevent fingerprinting via permissions API
      const origQuery = window.navigator.permissions && window.navigator.permissions.query.bind(window.navigator.permissions);
      if (origQuery) {
        window.navigator.permissions.query = (params) =>
          params.name === 'notifications'
            ? Promise.resolve({ state: Notification.permission })
            : origQuery(params);
      }
    });

    const page = await context.newPage();
    page.setDefaultTimeout(timeoutMs);

    page.on('response', async (response) => {
      if (networkJson.length >= 12) return;
      const contentType = response.headers()['content-type'] || '';
      if (!contentType.includes('application/json')) return;
      try {
        const data = await response.json();
        networkJson.push({
          url: response.url(),
          data,
        });
      } catch {
        // Ignore non-JSON bodies with misleading content-type.
      }
    });

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 15000) }).catch(() => {});

    for (let i = 0; i < 5; i += 1) {
      await page.mouse.wheel(0, 1800);
      await page.waitForTimeout(500);
    }
    await page.evaluate(() => window.scrollTo(0, 0)).catch(() => {});

    const result = await page.evaluate(() => {
      const imageAttrs = ['src', 'currentSrc', 'data-src', 'data-original', 'data-lazy'];
      const images = [];
      for (const img of Array.from(document.images || [])) {
        for (const attr of imageAttrs) {
          const value = attr === 'currentSrc' ? img.currentSrc : img.getAttribute(attr);
          if (value) images.push(new URL(value, document.baseURI).href);
        }
        const srcset = img.getAttribute('srcset') || img.getAttribute('data-srcset');
        if (srcset) {
          for (const part of srcset.split(',')) {
            const value = part.trim().split(/\s+/)[0];
            if (value) images.push(new URL(value, document.baseURI).href);
          }
        }
      }
      for (const source of Array.from(document.querySelectorAll('source[srcset], source[data-srcset]'))) {
        const srcset = source.getAttribute('srcset') || source.getAttribute('data-srcset') || '';
        for (const part of srcset.split(',')) {
          const value = part.trim().split(/\s+/)[0];
          if (value) images.push(new URL(value, document.baseURI).href);
        }
      }

      return {
        title: document.title || '',
        text: document.body ? document.body.innerText : '',
        html: document.documentElement ? document.documentElement.outerHTML : '',
        images: Array.from(new Set(images)),
      };
    });

    console.log(
      JSON.stringify({
        status: 'ok',
        url,
        finalUrl: page.url(),
        title: result.title,
        text: trimText(result.text, 60000),
        html: result.html,
        images: result.images,
        networkJson,
      })
    );
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error && error.stack ? error.stack : String(error));
  console.log(JSON.stringify({ status: 'error', error: error.message || String(error) }));
  process.exit(1);
});
