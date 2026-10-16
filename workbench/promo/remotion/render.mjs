// Renders the promo, or single frames to check it.
//   node render.mjs out.mp4            the whole video
//   node render.mjs --stills 60,300    PNG frames into ./stills
import path from 'node:path';
import fs from 'node:fs';
import {fileURLToPath} from 'node:url';
import {bundle} from '@remotion/bundler';
import {renderFrames, renderMedia, renderStill, selectComposition} from '@remotion/renderer';

const here = path.dirname(fileURLToPath(import.meta.url));
const browserExecutable = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const serveUrl = await bundle({entryPoint: path.join(here, 'src/index.ts'), publicDir: path.join(here, '../footage')});
// Software rendering: Chrome's GPU compositing now and then tiled a frame or dropped a layer.
const chromiumOptions = {gl: 'swangle'};
const composition = await selectComposition({serveUrl, id: 'Promo', browserExecutable, chromiumOptions, logLevel: 'error'});
const frameRange = process.env.RANGE ? process.env.RANGE.split('-').map(Number) : null;

if (process.argv[2] === '--frames') {
	// Every frame as a JPEG, so single bad frames can be found and rendered again.
	const dir = process.argv[3];
	fs.mkdirSync(dir, {recursive: true});
	let last = -1;
	await renderFrames({
		composition, serveUrl, outputDir: dir, imageFormat: 'jpeg', jpegQuality: 95, browserExecutable, chromiumOptions, logLevel: 'error',
		concurrency: Number(process.env.CONCURRENCY) || 3, timeoutInMilliseconds: 120000, inputProps: {}, frameRange,
		onStart: () => {}, onFrameUpdate: n => { const p = Math.floor((n / composition.durationInFrames) * 10) * 10; if (p !== last) { last = p; console.log(`frames ${p}%`); } },
	});
	console.log('frames done');
} else if (process.argv[2] === '--redo') {
	// Render the listed frames again, one at a time, over the bad ones.
	const dir = process.argv[3];
	for (const f of process.argv[4].split(',').filter(Boolean).map(Number)) {
		const name = fs.readdirSync(dir).find(n => n.endsWith(`-${String(f).padStart(4, '0')}.jpeg`));
		await renderStill({composition, serveUrl, frame: f, output: path.join(dir, name), imageFormat: 'jpeg', jpegQuality: 95, browserExecutable, chromiumOptions, logLevel: 'error'});
	}
	console.log('redone');
} else if (process.argv[2] === '--stills') {
	fs.mkdirSync(path.join(here, 'stills'), {recursive: true});
	for (const f of process.argv[3].split(',').map(Number)) {
		await renderStill({composition, serveUrl, frame: f, output: path.join(here, 'stills', `f${f}.png`), browserExecutable, chromiumOptions, logLevel: 'error', scale: 0.5});
		console.log('still', f);
	}
} else {
	let last = -1;
	await renderMedia({
		composition, serveUrl, codec: 'h264', crf: 16, outputLocation: process.argv[2], browserExecutable, logLevel: 'error',
		pixelFormat: 'yuv420p', concurrency: Number(process.env.CONCURRENCY) || 3, timeoutInMilliseconds: 120000, chromiumOptions, frameRange,
		onProgress: ({progress}) => { const p = Math.floor(progress * 10) * 10; if (p !== last) { last = p; console.log(`render ${p}%`); } },
	});
	console.log(`done ${composition.durationInFrames} frames`);
}
