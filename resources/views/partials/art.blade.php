{{-- Line art for a page heading or an empty state, drawing itself on. Solid strokes only. Expects $name, and optionally $class. --}}
@php
  $drawings = [
    // A clapperboard: the videos page.
    'clapper' => '<rect x="8" y="26" width="60" height="40" rx="5"/><path d="M8 26l4-14 58 6-2 8"/><path class="vd-art-accent" d="M24 14l-4 14M40 16l-4 13M56 17l-3 12"/><path class="vd-art-accent" d="M32 38v16l13-8z"/>',
    // A screen with sparkles: making a new video.
    'spark' => '<rect x="6" y="20" width="52" height="38" rx="5"/><path d="M22 66h20M32 58v8"/><path class="vd-art-accent" d="M58 6l3 8 8 3-8 3-3 8-3-8-8-3 8-3z"/><path class="vd-art-accent" d="M28 32v14l11-7z"/>',
    // A play button in a frame: one video.
    'play' => '<rect x="6" y="12" width="64" height="44" rx="6"/><path d="M6 64h64"/><path class="vd-art-accent" d="M31 25v18l15-9z"/><circle class="vd-art-dot vd-art-accent" cx="22" cy="64" r="3" fill="currentColor"/>',
    // A light bulb: ideas.
    'bulb' => '<path d="M38 8a20 20 0 0 1 12 36c-3 2-4 5-4 8v4H30v-4c0-3-1-6-4-8a20 20 0 0 1 12-36z"/><path d="M30 62h16M33 68h10"/><path class="vd-art-accent" d="M30 34c3-6 5-6 8 0s5 6 8 0"/>',
    // A palette: styles.
    'palette' => '<path d="M38 8C20 8 8 21 8 36c0 16 12 28 26 28 5 0 6-3 6-6s-2-5 0-8 6-2 10-2c9 0 18-5 18-16C68 18 55 8 38 8z"/><circle class="vd-art-accent" cx="24" cy="32" r="4"/><circle class="vd-art-accent" cx="34" cy="20" r="4"/><circle class="vd-art-accent" cx="50" cy="22" r="4"/>',
    // An open book: examples and the library.
    'book' => '<path d="M38 18c-8-6-20-7-30-5v46c10-2 22-1 30 5 8-6 20-7 30-5V13c-10-2-22-1-30 5z"/><path d="M38 18v46"/><path class="vd-art-accent" d="M16 28h14M16 36h14M46 28h14M46 36h10"/>',
    // A bin: deleting.
    'bin' => '<path d="M14 20h48M30 20v-6h16v6"/><path d="M20 20l3 44h30l3-44"/><path class="vd-art-accent" d="M32 32v22M44 32v22"/>',
  ];
@endphp
<svg class="{{ $class ?? 'vd-hero-art' }}" viewBox="0 0 76 76" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $drawings[$name] ?? $drawings['play'] !!}</svg>
