/**
 * Общие настройки прогона. Всё, что зависит от окружения, приходит
 * через переменные среды — скрипты одинаково работают и из разового
 * контейнера Playwright, и из любого другого Node-рантайма.
 */
import { mkdirSync } from 'node:fs';
import path from 'node:path';

export const BASE_URL = process.env.DEMO_BASE_URL ?? 'http://app:8000';

export const CREDENTIALS = {
    email: process.env.DEMO_EMAIL ?? 'test@example.com',
    password: process.env.DEMO_PASSWORD ?? 'password',
};

export const OUT_DIR = process.env.DEMO_OUT_DIR ?? '/work/storage/app/demo-video';
export const AUDIO_DIR = path.join(OUT_DIR, 'audio');
export const VIDEO_DIR = path.join(OUT_DIR, 'video');
export const TIMINGS_FILE = path.join(OUT_DIR, 'timings.json');
export const SCENE_DURATIONS_FILE = path.join(OUT_DIR, 'scene-durations.json');
export const FINAL_VIDEO = path.join(OUT_DIR, 'operator-flow.mp4');

/** Размер кадра. 1280×720 читается на телефоне: интерфейс Filament не мельчит. */
export const VIEWPORT = { width: 1280, height: 720 };

/** Пауза после того, как отработали действия сцены, — кадр не обрывается на полуслове. */
export const SCENE_TAIL_MS = 600;

export const TTS = {
    model: process.env.DEMO_TTS_MODEL ?? 'gpt-4o-mini-tts',
    voice: process.env.DEMO_TTS_VOICE ?? 'onyx',
    instructions:
        'Спокойный деловой темп, дружелюбно, без пафоса. Это закадровый голос обучающего видео ' +
        'для операторов колл-центра. Термины и названия кнопок произноси отчётливо.',
};

export function ensureDirs() {
    for (const dir of [OUT_DIR, AUDIO_DIR, VIDEO_DIR]) {
        mkdirSync(dir, { recursive: true });
    }
}
