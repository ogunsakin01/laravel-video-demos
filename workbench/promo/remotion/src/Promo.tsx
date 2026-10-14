import React from 'react';
import {AbsoluteFill, Easing, interpolate, OffthreadVideo, Sequence, spring, staticFile, useCurrentFrame, useVideoConfig} from 'remotion';
import {TransitionSeries, linearTiming} from '@remotion/transitions';
import {fade} from '@remotion/transitions/fade';
import {loadFont} from '@remotion/google-fonts/Geist';
import {loadFont as loadMono} from '@remotion/google-fonts/GeistMono';

const {fontFamily: sans} = loadFont('normal', {weights: ['400', '500', '600', '700'], subsets: ['latin']});
const {fontFamily: mono} = loadMono('normal', {weights: ['400', '500'], subsets: ['latin']});

// Solid colours only: the package's look, in dark mode.
const C = {
	bg: '#0b0b0a',
	panel: '#151513',
	bar: '#1c1b19',
	border: '#2c2b27',
	text: '#f5f4ef',
	muted: '#a3a199',
	faint: '#5f5d57',
	accent: '#e5693d',
	accentInk: '#0b0b0a',
};

const FPS = 30;
const T = 12; // transition length in frames
const PAD = 64;
const WIN_W = 1080 - PAD * 2;
const BAR = 34;
const WIN_H = BAR + Math.round(WIN_W * 0.625);
const WIN_Y = 288;

type Rect = [number, number, number, number];
type Focus = {at: number; rect?: Rect | null; spot?: Rect};
type Segment = {src: string; from: number; rate: number; frames: number; focus: Focus[]};

const ease = Easing.bezier(0.65, 0, 0.35, 1);
const clamp = (v: number, a: number, b: number) => Math.min(b, Math.max(a, v));

// ---------------------------------------------------------------- Pieces

const Dots: React.FC = () => {
	const frame = useCurrentFrame();
	const shift = (frame * 0.25) % 32;
	return (
		<svg width={1080} height={1080} style={{position: 'absolute', inset: 0, opacity: 0.5}}>
			<defs>
				<pattern id="dots" width={32} height={32} patternUnits="userSpaceOnUse" patternTransform={`translate(${shift} ${shift})`}>
					<circle cx={2} cy={2} r={1.2} fill="#24231f" />
				</pattern>
			</defs>
			<rect width={1080} height={1080} fill="url(#dots)" />
		</svg>
	);
};

const Words: React.FC<{text: string; delay?: number; size?: number; color?: string; accent?: string[]; weight?: number; stagger?: number}> = ({
	text, delay = 0, size = 58, color = C.text, accent = [], weight = 650, stagger = 3,
}) => {
	const frame = useCurrentFrame();
	const {fps} = useVideoConfig();
	return (
		<div style={{display: 'flex', flexWrap: 'wrap', gap: `0 ${size * 0.26}px`, fontSize: size, fontWeight: weight, lineHeight: 1.1, letterSpacing: '-0.03em', color}}>
			{text.split(' ').map((w, i) => {
				const p = spring({frame: frame - delay - i * stagger, fps, config: {damping: 200, mass: 0.6}});
				const isAccent = accent.some(a => w.replace(/[.,]/g, '') === a);
				return (
					<span key={i} style={{display: 'inline-block', overflow: 'hidden', paddingBottom: size * 0.08}}>
						<span style={{display: 'inline-block', transform: `translateY(${(1 - p) * 105}%)`, color: isAccent ? C.accent : undefined}}>{w}</span>
					</span>
				);
			})}
		</div>
	);
};

const Kicker: React.FC<{n: number; label: string}> = ({n, label}) => {
	const frame = useCurrentFrame();
	const o = interpolate(frame, [0, 10], [0, 1], {extrapolateRight: 'clamp'});
	const w = interpolate(frame, [0, 14], [0, 28], {extrapolateRight: 'clamp', easing: ease});
	return (
		<div style={{display: 'flex', alignItems: 'center', gap: 14, opacity: o, fontFamily: mono, fontSize: 20, letterSpacing: '0.12em', textTransform: 'uppercase', color: C.muted}}>
			<span style={{color: C.accent}}>{String(n).padStart(2, '0')}</span>
			<span style={{width: w, height: 2, background: C.accent}} />
			<span>{label}</span>
		</div>
	);
};

const Footer: React.FC<{global: number; total: number}> = ({global, total}) => (
	<div style={{position: 'absolute', left: PAD, right: PAD, bottom: 46, display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontFamily: mono, fontSize: 18, color: C.faint}}>
		<span>laravel-video-demos</span>
		<span style={{width: 220, height: 3, background: C.border, position: 'relative', borderRadius: 2}}>
			<span style={{position: 'absolute', left: 0, top: 0, bottom: 0, width: `${(global / total) * 100}%`, background: C.accent, borderRadius: 2}} />
		</span>
	</div>
);

// One stretch of footage with a camera that eases between focus points and a
// spotlight that dims everything but the part being talked about.
const Footage: React.FC<{seg: Segment; width: number; height: number}> = ({seg, width, height}) => {
	const frame = useCurrentFrame();
	const ft = seg.from + (frame / FPS) * seg.rate;
	const s0 = width / 1280;

	const cam = (f?: Focus) => {
		if (!f || !f.rect) return {z: 1, tx: 0, ty: (height - 800 * s0) / 2};
		const [x, y, w, h] = f.rect;
		const z = clamp(Math.min(width / (w * s0), height / (h * s0)), 1, 2.4);
		const sc = s0 * z;
		const tx = clamp(width / 2 - (x + w / 2) * sc, width - 1280 * sc, 0);
		const ty = clamp(height / 2 - (y + h / 2) * sc, height - 800 * sc, 0);
		return {z, tx, ty};
	};

	let k = 0;
	seg.focus.forEach((f, i) => { if (f.at <= ft) k = i; });
	const cur = seg.focus[k];
	const prev = seg.focus[k - 1] ?? cur;
	const t = ease(clamp((ft - cur.at) / (0.75 * seg.rate), 0, 1));
	const a = cam(prev), b = cam(cur);
	const z = a.z + (b.z - a.z) * t, tx = a.tx + (b.tx - a.tx) * t, ty = a.ty + (b.ty - a.ty) * t;
	const sc = s0 * z;

	const spot = cur.spot;
	const spotOn = spot ? interpolate(ft - cur.at, [0.35 * seg.rate, 0.75 * seg.rate], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}) : 0;
	const pulse = 1 + Math.sin(frame / 6) * 0.004;

	return (
		<AbsoluteFill style={{overflow: 'hidden', background: '#141413'}}>
			<div style={{position: 'absolute', left: 0, top: 0, width: 1280, height: 800, transformOrigin: '0 0', transform: `translate(${tx}px, ${ty}px) scale(${sc})`}}>
				<OffthreadVideo src={staticFile(seg.src)} startFrom={Math.round(seg.from * FPS)} playbackRate={seg.rate} muted style={{width: 1280, height: 800}} />
			</div>
			{spot && spotOn > 0 && (
				<div style={{
					position: 'absolute', borderRadius: 12, border: `3px solid ${C.accent}`,
					left: tx + spot[0] * sc - 8, top: ty + spot[1] * sc - 8, width: spot[2] * sc + 16, height: spot[3] * sc + 16,
					boxShadow: `0 0 0 4000px rgba(6,6,5,${0.6 * spotOn})`, opacity: spotOn, transform: `scale(${pulse})`,
				}} />
			)}
		</AbsoluteFill>
	);
};

const Window: React.FC<{segments: Segment[]; enter?: number}> = ({segments}) => {
	const frame = useCurrentFrame();
	const {fps} = useVideoConfig();
	const p = spring({frame: frame - 4, fps, config: {damping: 200, mass: 0.8}});
	let at = 0;
	return (
		<div style={{
			position: 'absolute', left: PAD, top: WIN_Y, width: WIN_W, height: WIN_H, borderRadius: 18, overflow: 'hidden',
			border: `1px solid ${C.border}`, background: C.panel, opacity: p, transform: `translateY(${(1 - p) * 60}px) scale(${0.96 + 0.04 * p})`,
			boxShadow: '0 30px 60px rgba(0,0,0,.45)',
		}}>
			<div style={{height: BAR, background: C.bar, borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 8, padding: '0 14px'}}>
				{['#3a3935', '#3a3935', '#3a3935'].map((c, i) => <span key={i} style={{width: 11, height: 11, borderRadius: 6, background: c}} />)}
				<span style={{marginLeft: 16, fontFamily: mono, fontSize: 14, color: C.faint}}>localhost</span>
			</div>
			<div style={{position: 'absolute', top: BAR, left: 0, right: 0, bottom: 0}}>
				{segments.map((s, i) => {
					const from = at;
					at += s.frames;
					return (
						<Sequence key={i} from={from} durationInFrames={s.frames} layout="none">
							<Footage seg={s} width={WIN_W} height={WIN_H - BAR} />
						</Sequence>
					);
				})}
			</div>
		</div>
	);
};

const Phone: React.FC<{src: string; delay: number}> = ({src, delay}) => {
	const frame = useCurrentFrame();
	const {fps} = useVideoConfig();
	const p = spring({frame: frame - delay, fps, config: {damping: 16, mass: 0.9}});
	const w = 262, h = Math.round(262 * 844 / 390);
	return (
		<div style={{position: 'absolute', right: PAD - 26, top: WIN_Y + 70, width: w + 20, height: h + 20, borderRadius: 50, background: '#050505',
			border: `2px solid ${C.border}`, padding: 10, transform: `translateX(${(1 - p) * 420}px) rotate(${(1 - p) * 8}deg)`, boxShadow: '0 40px 80px rgba(0,0,0,.6)'}}>
			<div style={{width: w, height: h, borderRadius: 40, overflow: 'hidden', position: 'relative'}}>
				<Sequence from={delay} layout="none">
					<OffthreadVideo src={staticFile(src)} muted style={{width: w, height: h}} />
				</Sequence>
			</div>
		</div>
	);
};

const CodeChip: React.FC<{delay: number; code: React.ReactNode}> = ({delay, code}) => {
	const frame = useCurrentFrame();
	const {fps} = useVideoConfig();
	const p = spring({frame: frame - delay, fps, config: {damping: 14, mass: 0.7}});
	return (
		<div style={{position: 'absolute', left: PAD + 30, bottom: 120, padding: '22px 28px', borderRadius: 16, background: '#191816', border: `1px solid ${C.accent}`,
			fontFamily: mono, fontSize: 27, color: C.text, opacity: p, transform: `translateY(${(1 - p) * 40}px)`, boxShadow: '0 20px 50px rgba(0,0,0,.55)'}}>
			{code}
		</div>
	);
};

// ---------------------------------------------------------------- Scenes

const AppScene: React.FC<{n: number; label: string; lines: [string, string]; accent?: string[]; segments: Segment[]; children?: React.ReactNode}> = ({n, label, lines, accent, segments, children}) => (
	<AbsoluteFill>
		<div style={{position: 'absolute', left: PAD, top: 66, right: PAD}}>
			<Kicker n={n} label={label} />
			<div style={{marginTop: 22, display: 'flex', flexDirection: 'column', gap: 2}}>
				<Words text={lines[0]} delay={4} accent={accent} />
				<Words text={lines[1]} delay={10} accent={accent} color={C.muted} />
			</div>
		</div>
		<Window segments={segments} />
		{children}
	</AbsoluteFill>
);

const Intro: React.FC = () => {
	const frame = useCurrentFrame();
	const {fps} = useVideoConfig();
	const bar = interpolate(frame, [4, 26], [0, 120], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease});
	const logo = spring({frame: frame - 2, fps, config: {damping: 12, mass: 0.7}});
	return (
		<AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', textAlign: 'center'}}>
			<div style={{width: 86, height: 86, borderRadius: 22, background: C.accent, display: 'grid', placeItems: 'center', transform: `scale(${logo}) rotate(${(1 - logo) * -30}deg)`, marginBottom: 40}}>
				<svg width={34} height={34} viewBox="0 0 24 24"><path d="M7 4.5v15l12.5-7.5z" fill={C.accentInk} /></svg>
			</div>
			<div style={{display: 'flex', flexDirection: 'column', alignItems: 'center'}}>
				<Words text="Laravel Video Demos" delay={8} size={86} weight={700} />
				<div style={{width: bar, height: 4, background: C.accent, margin: '26px 0 28px', borderRadius: 2}} />
				<Words text="How-to videos of your app, made by AI." delay={22} size={38} weight={500} color={C.muted} accent={['AI']} stagger={2} />
			</div>
		</AbsoluteFill>
	);
};

const Hook: React.FC = () => {
	const frame = useCurrentFrame();
	const strike = interpolate(frame, [26, 40], [0, 100], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease});
	const fadeOld = interpolate(frame, [26, 44], [1, 0.4], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
	return (
		<AbsoluteFill style={{justifyContent: 'center', padding: `0 ${PAD + 10}px`}}>
			<div style={{position: 'relative', opacity: fadeOld, alignSelf: 'flex-start'}}>
				<Words text="Recording how-to videos by hand?" delay={2} size={56} color={C.muted} />
				<div style={{position: 'absolute', left: 0, top: '50%', height: 5, width: `${strike}%`, background: C.accent}} />
			</div>
			<div style={{marginTop: 30}}>
				<Words text="Describe the flow." delay={40} size={84} weight={700} />
				<Words text="AI records it." delay={50} size={84} weight={700} accent={['AI']} />
			</div>
		</AbsoluteFill>
	);
};

const Terminal: React.FC<{n: number}> = ({n}) => {
	const frame = useCurrentFrame();
	const cmd = 'php artisan video-demo:generate "How a recruiter posts a job" --json';
	const typed = cmd.slice(0, Math.floor(clamp((frame - 22) * 1.6, 0, cmd.length)));
	const done = frame - 22 > cmd.length / 1.6 + 6;
	const out = [
		['{', C.muted],
		['  "status": "ready",', C.text],
		['  "title": "Post a job",', C.text],
		['  "versions": ["desktop", "mobile"],', C.text],
		['  "seconds": 42.6,', C.text],
		['  "library_url": ".../help/videos/post-a-job"', C.text],
		['}', C.muted],
	];
	const start = 22 + Math.ceil(cmd.length / 1.6) + 10;
	const caret = Math.floor(frame / 8) % 2 === 0;
	return (
		<AbsoluteFill>
			<div style={{position: 'absolute', left: PAD, top: 66, right: PAD}}>
				<Kicker n={n} label="AI agents" />
				<div style={{marginTop: 22, display: 'flex', flexDirection: 'column', gap: 2}}>
					<Words text="Your coding agents" delay={4} />
					<Words text="can make them too." delay={10} color={C.muted} />
				</div>
			</div>
			<div style={{position: 'absolute', left: PAD, top: WIN_Y, width: WIN_W, height: WIN_H, borderRadius: 18, overflow: 'hidden', border: `1px solid ${C.border}`, background: '#0f0f0e'}}>
				<div style={{height: BAR, background: C.bar, borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 8, padding: '0 14px'}}>
					{[0, 1, 2].map(i => <span key={i} style={{width: 11, height: 11, borderRadius: 6, background: '#3a3935'}} />)}
					<span style={{marginLeft: 16, fontFamily: mono, fontSize: 14, color: C.faint}}>terminal</span>
				</div>
				<div style={{padding: '30px 34px', fontFamily: mono, fontSize: 27, lineHeight: 1.55, color: C.text, whiteSpace: 'pre-wrap', wordBreak: 'break-word'}}>
					<span style={{color: C.accent}}>$ </span>{typed}{!done && caret ? <span style={{background: C.text}}>{' '}</span> : null}
					<div style={{marginTop: 18}}>
						{out.map(([line, color], i) => (
							<div key={i} style={{color, opacity: interpolate(frame, [start + i * 4, start + i * 4 + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'})}}>{line}</div>
						))}
					</div>
				</div>
			</div>
		</AbsoluteFill>
	);
};

const CodeScene: React.FC<{n: number}> = ({n}) => {
	const frame = useCurrentFrame();
	const code = [
		[['use ', C.muted], ['Ogunsakin01\\VideoDemo\\Facades\\VideoDemo', C.text], [';', C.muted]],
		[],
		[['$video', C.text], [' = ', C.muted], ['VideoDemo', C.accent], ['::make(', C.muted], ["'How a manager approves leave'", '#9fd3a8'], [', [', C.muted]],
		[["    'style'", '#9fd3a8'], [' => ', C.muted], ["'spotlight'", '#9fd3a8'], [',', C.muted]],
		[["    'as'", '#9fd3a8'], [' => ', C.muted], ["'manager'", '#9fd3a8'], [',', C.muted]],
		[["    'max_cost'", '#9fd3a8'], [' => ', C.muted], ['0.50', C.text], [',', C.muted]],
		[[']);', C.muted]],
		[],
		[['VideoDemo', C.accent], ['::publish(', C.muted], ['$video', C.text], [');', C.muted]],
	] as [string, string][][];
	const total = code.reduce((n, l) => n + l.reduce((m, [t]) => m + t.length, 0) + 1, 0);
	const shown = Math.floor(clamp((frame - 20) * 2.6, 0, total));
	let left = shown;
	const caret = Math.floor(frame / 8) % 2 === 0;
	return (
		<AbsoluteFill>
			<div style={{position: 'absolute', left: PAD, top: 66, right: PAD}}>
				<Kicker n={n} label="Your own UI" />
				<div style={{marginTop: 22, display: 'flex', flexDirection: 'column', gap: 2}}>
					<Words text="Build your own screens" delay={4} />
					<Words text="with one facade." delay={10} color={C.muted} />
				</div>
			</div>
			<div style={{position: 'absolute', left: PAD, top: WIN_Y, width: WIN_W, height: WIN_H, borderRadius: 18, overflow: 'hidden', border: `1px solid ${C.border}`, background: '#0f0f0e'}}>
				<div style={{height: BAR, background: C.bar, borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 8, padding: '0 14px'}}>
					{[0, 1, 2].map(i => <span key={i} style={{width: 11, height: 11, borderRadius: 6, background: '#3a3935'}} />)}
					<span style={{marginLeft: 16, fontFamily: mono, fontSize: 14, color: C.faint}}>app/Http/Controllers/HelpVideoController.php</span>
				</div>
				<div style={{padding: '30px 34px', fontFamily: mono, fontSize: 26, lineHeight: 1.6, whiteSpace: 'pre'}}>
					{code.map((line, i) => {
						const parts = line.map(([t, color], k) => {
							const take = Math.max(0, Math.min(t.length, left));
							left -= take;
							return <span key={k} style={{color}}>{t.slice(0, take)}</span>;
						});
						left -= 1;
						return <div key={i} style={{minHeight: '1.6em'}}>{parts}</div>;
					})}
					{shown < total && caret ? <span style={{background: C.text}}>{' '}</span> : null}
				</div>
			</div>
		</AbsoluteFill>
	);
};

// A window that shows lines of mono text, one after another.
const LogWindow: React.FC<{title: string; start: number; every: number; lines: React.ReactNode[]; size?: number; children?: React.ReactNode}> = ({title, start, every, lines, size = 25, children}) => {
	const frame = useCurrentFrame();
	return (
		<div style={{position: 'absolute', left: PAD, top: WIN_Y, width: WIN_W, height: WIN_H, borderRadius: 18, overflow: 'hidden', border: `1px solid ${C.border}`, background: '#0f0f0e'}}>
			<div style={{height: BAR, background: C.bar, borderBottom: `1px solid ${C.border}`, display: 'flex', alignItems: 'center', gap: 8, padding: '0 14px'}}>
				{[0, 1, 2].map(i => <span key={i} style={{width: 11, height: 11, borderRadius: 6, background: '#3a3935'}} />)}
				<span style={{marginLeft: 16, fontFamily: mono, fontSize: 14, color: C.faint}}>{title}</span>
			</div>
			<div style={{padding: '28px 34px', fontFamily: mono, fontSize: size, lineHeight: 1.55, color: C.text}}>
				{children}
				{lines.map((line, i) => {
					const o = interpolate(frame, [start + i * every, start + i * every + 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
					return <div key={i} style={{opacity: o, transform: `translateY(${(1 - o) * 8}px)`}}>{line}</div>;
				})}
			</div>
		</div>
	);
};

const Done: React.FC<{label: string; value?: string}> = ({label, value = 'DONE'}) => (
	<div style={{display: 'flex', gap: 12, alignItems: 'baseline'}}>
		<span style={{whiteSpace: 'nowrap'}}>{label}</span>
		<span style={{flex: 1, borderBottom: `2px dotted ${C.faint}`, transform: 'translateY(-7px)'}} />
		<span style={{color: value === 'DONE' ? '#7fd49a' : C.muted, whiteSpace: 'nowrap'}}>{value}</span>
	</div>
);

const Install: React.FC<{n: number}> = ({n}) => {
	const frame = useCurrentFrame();
	const cmd = 'php artisan video-demo:install';
	const typed = cmd.slice(0, Math.floor(clamp((frame - 20) * 1.4, 0, cmd.length)));
	const caret = Math.floor(frame / 8) % 2 === 0 && typed.length < cmd.length;
	const start = 20 + Math.ceil(cmd.length / 1.4) + 8;
	return (
		<AbsoluteFill>
			<div style={{position: 'absolute', left: PAD, top: 66, right: PAD}}>
				<Kicker n={n} label="One command" />
				<div style={{marginTop: 22, display: 'flex', flexDirection: 'column', gap: 2}}>
					<Words text="Set up in one command." delay={4} />
					<Words text="Run it again to upgrade." delay={10} color={C.muted} />
				</div>
			</div>
			<LogWindow title="terminal" start={start} every={11} size={24} lines={[
				<Done label="Running the package's migrations" />,
				<Done label="Installing Playwright and Chromium" />,
				<Done label="Building the sample database" />,
				<Done label="Sample Recruiter user" value="recruiter@acme.test" />,
				<Done label="Sample Hiring manager user" value="manager@acme.test" />,
				<div style={{marginTop: 14, color: C.muted}}>Your config is kept. Workers restarted.</div>,
			]}>
				<div style={{marginBottom: 16}}><span style={{color: C.accent}}>$ </span>{typed}{caret ? <span style={{background: C.text}}>{' '}</span> : null}</div>
			</LogWindow>
		</AbsoluteFill>
	);
};

// What the AI does on sample data, as the video's progress log shows it.
const SampleData: React.FC<{n: number}> = ({n}) => {
	const t = (s: string) => <span style={{color: C.faint, marginRight: 22}}>{s}</span>;
	const hi = (s: string) => <span style={{color: C.accent}}>{s}</span>;
	return (
		<AbsoluteFill>
			<div style={{position: 'absolute', left: PAD, top: 66, right: PAD}}>
				<Kicker n={n} label="Sample data" />
				<div style={{marginTop: 22, display: 'flex', flexDirection: 'column', gap: 2}}>
					<Words text="Never your real data." delay={4} />
					<Words text="AI seeds what pages need." delay={10} color={C.muted} accent={['AI']} />
				</div>
			</div>
			<LogWindow title="Progress" start={26} every={13} size={23} lines={[
				<>{t('10:41:02')}Copying the sample database</>,
				<>{t('10:41:02')}Signed in as a Recruiter</>,
				<>{t('10:41:09')}Read app/Http/{hi('Middleware')}/EnsureProfileComplete.php</>,
				<>{t('10:41:12')}Read app/{hi('Policies')}/JobPolicy.php</>,
				<>{t('10:41:15')}Looked at the sample jobs</>,
				<>{t('10:41:21')}{hi('Added sample data:')} Job, Applicant ×3</>,
				<>{t('10:41:30')}Plan ready: 12 steps</>,
				<>{t('10:42:41')}<span style={{color: '#7fd49a'}}>Ready.</span> The AI cost $0.22 in total.</>,
			]} />
		</AbsoluteFill>
	);
};

const Providers: React.FC<{n: number}> = ({n}) => {
	const frame = useCurrentFrame();
	const {fps} = useVideoConfig();
	const chips = ['Claude', 'OpenAI', 'Gemini', 'Mistral', 'DeepSeek', 'Ollama (local)'];
	return (
		<AbsoluteFill style={{justifyContent: 'center', padding: `0 ${PAD}px`}}>
			<Kicker n={n} label="Any model" />
			<div style={{marginTop: 26}}>
				<Words text="Bring your own model." delay={4} size={76} weight={700} />
				<Words text="Your key. Recorded on your machine." delay={12} size={40} weight={500} color={C.muted} stagger={2} />
			</div>
			<div style={{display: 'flex', flexWrap: 'wrap', gap: 16, marginTop: 56}}>
				{chips.map((c, i) => {
					const p = spring({frame: frame - 24 - i * 4, fps, config: {damping: 12, mass: 0.6}});
					const first = i === 0;
					return (
						<span key={c} style={{padding: '16px 28px', borderRadius: 999, fontSize: 32, fontWeight: 600, transform: `scale(${p})`, opacity: p,
							background: first ? C.accent : C.panel, color: first ? C.accentInk : C.text, border: `1px solid ${first ? C.accent : C.border}`}}>{c}</span>
					);
				})}
			</div>
		</AbsoluteFill>
	);
};

const Outro: React.FC = () => {
	const frame = useCurrentFrame();
	const {fps} = useVideoConfig();
	const cmd = 'composer require ogunsakin01/laravel-video-demos';
	const cmd2 = 'php artisan video-demo:install';
	const typed = cmd.slice(0, Math.floor(clamp((frame - 24) * 1.5, 0, cmd.length)));
	const after = 24 + Math.ceil(cmd.length / 1.5) + 4;
	const typed2 = cmd2.slice(0, Math.floor(clamp((frame - after) * 1.5, 0, cmd2.length)));
	const box = spring({frame: frame - 16, fps, config: {damping: 200}});
	const url = interpolate(frame, [after + 26, after + 40], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
	const caret = Math.floor(frame / 8) % 2 === 0;
	return (
		<AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', textAlign: 'center'}}>
			<Words text="Free and open source." delay={2} size={80} weight={700} accent={['open', 'source']} />
			<div style={{marginTop: 46, padding: '26px 34px', borderRadius: 16, background: C.panel, border: `1px solid ${C.border}`, fontFamily: mono, fontSize: 29,
				color: C.text, opacity: box, transform: `translateY(${(1 - box) * 30}px)`, minWidth: 860, textAlign: 'left'}}>
				<div><span style={{color: C.accent}}>$ </span>{typed}{caret && !typed2 ? <span style={{background: C.text}}>{' '}</span> : null}</div>
				<div style={{minHeight: '1.4em'}}>{typed2 ? <><span style={{color: C.accent}}>$ </span>{typed2}{caret ? <span style={{background: C.text}}>{' '}</span> : null}</> : null}</div>
			</div>
			<div style={{marginTop: 40, opacity: url, transform: `translateY(${(1 - url) * 16}px)`}}>
				<div style={{fontSize: 34, fontWeight: 600, color: C.text}}>github.com/ogunsakin01/laravel-video-demos</div>
				<div style={{marginTop: 12, fontSize: 24, color: C.muted}}>Docs and setup checklist in the wiki</div>
				<div style={{marginTop: 14, fontFamily: mono, fontSize: 20, color: C.muted, letterSpacing: '0.04em'}}>MIT · PHP 8.3+ · Laravel 12 & 13</div>
			</div>
		</AbsoluteFill>
	);
};

// ---------------------------------------------------------------- Timeline

const seg = (src: string, from: number, to: number, frames: number, focus: Focus[]): Segment => ({src, from, rate: (to - from) / (frames / FPS), frames, focus});

const scenes: {frames: number; render: () => React.ReactNode}[] = [
	{frames: 105, render: () => <Intro />},
	{frames: 96, render: () => <Hook />},
	{frames: 231, render: () => <AppScene n={1} label="Describe it" lines={['Type a few words.', 'AI plans the walkthrough.']} accent={['AI']}
		segments={[seg('describe.mp4', 1.0, 10.3, 231, [
			{at: 0, rect: [96, 262, 730, 200]},
			{at: 4.95, rect: [96, 262, 730, 200], spot: [660, 276, 160, 40]},
			{at: 8.15, rect: [96, 262, 730, 200], spot: [105, 316, 710, 116]},
		])]} />},
	{frames: 176, render: () => <AppScene n={2} label="Every screen" lines={['One plan.', 'Desktop, tablet, mobile, TV.']}
		segments={[seg('screens.mp4', 0.8, 7.8, 176, [{at: 0, rect: [96, 416, 730, 174], spot: [105, 476, 710, 54]}])]} />},
	{frames: 176, render: () => <AppScene n={3} label="Speed" lines={['Real time, or brisk.', 'You set the speed.']}
		segments={[seg('speed.mp4', 0.8, 7.8, 176, [{at: 0, rect: [96, 413, 730, 200], spot: [100, 463, 720, 112]}])]} />},
	{frames: 276, render: () => <AppScene n={4} label="Cost control" lines={['See the cost first.', 'Every video has a limit.']}
		segments={[seg('cost.mp4', 0.5, 11.5, 276, [
			{at: 0, rect: [846, 240, 360, 340], spot: [856, 259, 340, 302]},
			{at: 1.9, rect: null},
			{at: 7.0, rect: [96, 560, 740, 200], spot: [105, 615, 347, 92]},
		])]} />},
	{frames: 180, render: () => <SampleData n={5} />},
	{frames: 200, render: () => <AppScene n={6} label="Record as anyone" lines={['Admin, member, guest.', 'On a fresh sample database.']}
		segments={[seg('sampledata.mp4', 0.6, 8.6, 200, [
			{at: 0, rect: [96, 330, 730, 250], spot: [100, 372, 720, 50]},
			{at: 3.3, rect: [96, 250, 730, 250], spot: [100, 385, 720, 92]},
		])]} />},
	{frames: 150, render: () => <AppScene n={7} label="Checked first" lines={['Broken pages caught', 'before AI spends a cent.']} accent={['Broken']}
		segments={[seg('checks.mp4', 4.0, 10.0, 150, [{at: 0, rect: null}, {at: 5.6, rect: [76, 40, 1128, 420], spot: [84, 240, 1112, 181]}])]} />},
	{frames: 251, render: () => <AppScene n={8} label="Review" lines={['Watch every version', 'before anyone else.']}
		segments={[seg('review.mp4', 1.0, 11.0, 251, [{at: 0, rect: [76, 220, 780, 510]}])]}>
		<Phone src="phone.mp4" delay={40} />
	</AppScene>},
	{frames: 260, render: () => <AppScene n={9} label="Make it again" lines={['Change anything.', 'Same plan? Free.']} accent={['Free.']}
		segments={[seg('again.mp4', 0.5, 10.9, 260, [
			{at: 0, rect: [96, 300, 730, 220], spot: [100, 352, 720, 112]},
			{at: 4.3, rect: [96, 520, 730, 220], spot: [100, 582, 720, 70]},
			{at: 8.9, rect: [96, 520, 730, 220], spot: [100, 665, 720, 48]},
		])]} />},
	{frames: 276, render: () => <AppScene n={10} label="Publish" lines={['Publish one,', 'or publish them all.']}
		segments={[seg('bulk.mp4', 1.5, 12.5, 276, [{at: 0, rect: [76, 140, 780, 440]}, {at: 9.3, rect: [76, 230, 780, 330], spot: [85, 260, 750, 50]}])]} />},
	{frames: 200, render: () => <AppScene n={11} label="Embed" lines={['One Blade tag.', 'Any page.']}
		segments={[seg('embed.mp4', 0.6, 8.6, 200, [{at: 0, rect: [846, 320, 360, 480], spot: [856, 330, 340, 460]}, {at: 4.8, rect: [846, 216, 360, 480], spot: [856, 226, 340, 460]}])]}>
		<CodeChip delay={63} code={<><span style={{color: C.muted}}>&lt;</span><span style={{color: C.accent}}>x-video-demo::player</span> <span style={{color: C.muted}}>slug=</span>"post-a-job" <span style={{color: C.muted}}>/&gt;</span></>} />
	</AppScene>},
	{frames: 202, render: () => <AppScene n={12} label="Help library" lines={['A searchable help', 'library, built in.']}
		segments={[
			seg('library.mp4', 0.6, 5.0, 111, [{at: 0, rect: [600, 100, 620, 280], spot: [731, 131, 356, 39]}]),
			seg('library.mp4', 23.5, 27.1, 91, [{at: 23.5, rect: [60, 40, 1160, 700]}]),
		]} />},
	{frames: 230, render: () => <AppScene n={13} label="Delete with care" lines={['See every page', 'that shows it first.']}
		segments={[
			seg('remove.mp4', 1.0, 5.0, 100, [{at: 0, rect: null}]),
			seg('remove.mp4', 9.5, 13.7, 130, [{at: 9.5, rect: [60, 230, 1160, 300]}, {at: 10.2, rect: [60, 230, 1160, 300], spot: [84, 346, 1112, 158]}]),
		]} />},
	{frames: 215, render: () => <AppScene n={14} label="Ideas" lines={['Not sure what to film?', 'AI suggests it.']} accent={['AI']}
		segments={[seg('ideas.mp4', 1.0, 9.9, 215, [{at: 0, rect: [700, 110, 520, 120], spot: [1016, 155, 155, 36]}, {at: 5.9, rect: [76, 300, 1128, 490]}])]} />},
	{frames: 225, render: () => <AppScene n={15} label="Spotlight style" lines={['A polished reel', 'in one click.']}
		segments={[seg('spotlight-example.mp4', 10.0, 19.0, 225, [{at: 0, rect: null}])]} />},
	{frames: 190, render: () => <Install n={16} />},
	{frames: 165, render: () => <Terminal n={17} />},
	{frames: 190, render: () => <CodeScene n={18} />},
	{frames: 110, render: () => <Providers n={19} />},
	{frames: 210, render: () => <Outro />},
];

export const promoLength = () => scenes.reduce((n, s) => n + s.frames, 0) - T * (scenes.length - 1);

export const Promo: React.FC = () => {
	const frame = useCurrentFrame();
	const total = promoLength();
	return (
		<AbsoluteFill style={{background: C.bg, fontFamily: sans, color: C.text}}>
			<Dots />
			<TransitionSeries>
				{scenes.flatMap((s, i) => [
					<TransitionSeries.Sequence key={`s${i}`} durationInFrames={s.frames}>{s.render()}</TransitionSeries.Sequence>,
					...(i < scenes.length - 1 ? [<TransitionSeries.Transition key={`t${i}`} presentation={fade()} timing={linearTiming({durationInFrames: T})} />] : []),
				])}
			</TransitionSeries>
			<Footer global={frame} total={total} />
		</AbsoluteFill>
	);
};
