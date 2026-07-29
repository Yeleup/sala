/**
 * Сборка итогового mp4: дорожка озвучки под фактический тайминг записи
 * плюс перекодированное видео.
 *
 * Каждый сегмент озвучки добивается тишиной до фактической длительности
 * своей сцены — поэтому реплика всегда начинается ровно тогда, когда в
 * кадре начинается её сцена, независимо от того, насколько действия
 * оказались быстрее или медленнее голоса.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { AUDIO_DIR, FINAL_VIDEO, OUT_DIR, SCENE_DURATIONS_FILE } from './config.mjs';

const ffmpeg = (args) => execFileSync('ffmpeg', ['-y', '-hide_banner', '-loglevel', 'error', ...args]);

const { leadIn, scenes, video } = JSON.parse(readFileSync(SCENE_DURATIONS_FILE, 'utf8'));
const parts = [];

const silence = (seconds, output) => {
    ffmpeg([
        '-f', 'lavfi',
        '-i', 'anullsrc=channel_layout=mono:sample_rate=44100',
        '-t', String(seconds),
        '-c:a', 'pcm_s16le',
        output,
    ]);
};

const leadInFile = path.join(AUDIO_DIR, 'lead-in.wav');
silence(Math.max(leadIn, 0.1), leadInFile);
parts.push(leadInFile);

for (const scene of scenes) {
    const padded = path.join(AUDIO_DIR, `${scene.id}.padded.wav`);

    // apad + -t: голос сцены, дотянутый тишиной до её реальной длины.
    ffmpeg([
        '-i', path.join(AUDIO_DIR, `${scene.id}.mp3`),
        '-af', 'apad',
        '-t', scene.actual.toFixed(3),
        '-ar', '44100',
        '-ac', '1',
        '-c:a', 'pcm_s16le',
        padded,
    ]);

    parts.push(padded);
}

const listFile = path.join(OUT_DIR, 'audio-parts.txt');
writeFileSync(listFile, parts.map((file) => `file '${file}'`).join('\n'));

const track = path.join(OUT_DIR, 'narration.wav');
ffmpeg(['-f', 'concat', '-safe', '0', '-i', listFile, '-c', 'copy', track]);

ffmpeg([
    '-i', video,
    '-i', track,
    '-map', '0:v:0',
    '-map', '1:a:0',
    '-r', '30',
    '-c:v', 'libx264',
    '-preset', 'medium',
    '-crf', '22',
    '-pix_fmt', 'yuv420p',
    '-c:a', 'aac',
    '-b:a', '160k',
    '-shortest',
    '-movflags', '+faststart',
    FINAL_VIDEO,
]);

const probe = execFileSync('ffprobe', [
    '-v', 'error',
    '-show_entries', 'format=duration',
    '-of', 'default=noprint_wrappers=1:nokey=1',
    FINAL_VIDEO,
]).toString().trim();

const seconds = Number.parseFloat(probe);
console.log(`Готово: ${FINAL_VIDEO} — ${Math.floor(seconds / 60)} мин ${Math.round(seconds % 60)} с`);
