/**
 * Прогон сценария с записью экрана.
 *
 * Сцена держится не меньше, чем звучит её озвучка (timings.json), а
 * фактическая длительность каждой сцены пишется в scene-durations.json —
 * по нему сборка расставляет реплики точно под картинку, даже если
 * действия заняли больше времени, чем голос.
 */
import { readFileSync, renameSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import {
    SCENE_DURATIONS_FILE,
    SCENE_TAIL_MS,
    TIMINGS_FILE,
    VIDEO_DIR,
    VIEWPORT,
    ensureDirs,
} from './config.mjs';
import { scenes } from './scenario.mjs';

ensureDirs();

const timings = JSON.parse(readFileSync(TIMINGS_FILE, 'utf8'));
const overlay = readFileSync(new URL('./cursor-overlay.js', import.meta.url), 'utf8');

// Chromium сам переводит http:// на https:// для любого хоста, кроме
// localhost, — приложение внутри сети отвечает по http, поэтому
// автоапгрейд выключаем, иначе первый же переход падает на SSL.
const browser = await chromium.launch({
    args: [
        '--force-device-scale-factor=1',
        '--disable-features=HttpsUpgrades,HttpsFirstBalancedModeAutoEnable',
    ],
});
const context = await browser.newContext({
    viewport: VIEWPORT,
    ignoreHTTPSErrors: true,
    locale: 'ru-RU',
    timezoneId: 'Asia/Almaty',
    recordVideo: { dir: VIDEO_DIR, size: VIEWPORT },
    reducedMotion: 'no-preference',
});

await context.addInitScript(overlay);

const page = await context.newPage();
page.setDefaultTimeout(30000);

const durations = [];
const startedAt = Date.now();

// Запись стартует раньше первой сцены — этот зазор станет тишиной в начале дорожки.
await page.waitForTimeout(500);
const leadIn = (Date.now() - startedAt) / 1000;

for (const scene of scenes) {
    const sceneStart = Date.now();
    const voice = timings[scene.id]?.duration ?? 0;

    console.log(`▶ ${scene.id} (озвучка ${voice.toFixed(1)} с)`);

    try {
        await scene.run(page);
    } catch (error) {
        console.error(`✖ Сцена «${scene.id}» упала: ${error.message}`);
        await page.screenshot({ path: path.join(VIDEO_DIR, `failed-${scene.id}.png`) }).catch(() => {});
        throw error;
    }

    const target = voice * 1000 + SCENE_TAIL_MS;
    const spent = Date.now() - sceneStart;

    if (spent < target) {
        await page.waitForTimeout(target - spent);
    }

    const actual = (Date.now() - sceneStart) / 1000;
    durations.push({ id: scene.id, voice, actual });
    console.log(`  длительность сцены: ${actual.toFixed(1)} с`);
}

const videoPath = await page.video().path();
await context.close();
await browser.close();

const finalVideo = path.join(VIDEO_DIR, 'raw.webm');
renameSync(videoPath, finalVideo);

writeFileSync(SCENE_DURATIONS_FILE, JSON.stringify({ leadIn, scenes: durations, video: finalVideo }, null, 2));

const total = leadIn + durations.reduce((sum, scene) => sum + scene.actual, 0);
console.log(`Запись готова: ${finalVideo} (${Math.floor(total / 60)} мин ${Math.round(total % 60)} с)`);
