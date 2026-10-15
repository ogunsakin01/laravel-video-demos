@php
  use Ogunsakin01\VideoDemo\Models\VideoStyle;
  $form = $editing ?? new VideoStyle(VideoStyle::defaults());
@endphp
<x-video-demo::layout title="Styles" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => 'styles'])
  </x-slot:nav>

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>Styles</h1>
      <p class="vd-sub">How the AI writes each video and how its title cards and captions look. The first ones come from the <code>styles</code> section of <code>config/video-demo.php</code>.</p>
    </div>
  </section>

  <div class="vd-split">
    <div class="vd-stack">
      @foreach ($styles as $style)
        @php($dark = $style->theme === 'dark')
        <article class="vd-card" style="display:grid;grid-template-columns:132px minmax(0,1fr);gap:18px;align-items:start">
          {{-- A small preview of the style's title card. --}}
          <div aria-hidden="true" style="position:relative;overflow:hidden;aspect-ratio:16/10;border-radius:8px;border:1px solid var(--vd-border);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;background:{{ $dark ? '#141412' : '#faf8f3' }}">
            @if ($style->art === 'abstract')
              <svg viewBox="0 0 160 100" style="position:absolute;inset:0;width:100%;height:100%" fill="none" stroke="{{ $dark ? 'rgba(250,250,248,.22)' : '#d6d2c8' }}" stroke-width="1">
                @foreach ([10, 18, 26, 34, 42] as $r)
                  <ellipse cx="18" cy="50" rx="{{ $r }}" ry="{{ $r * .78 }}" />
                @endforeach
                <circle cx="150" cy="16" r="2.5" fill="{{ $dark && strtolower($style->accent) === '#171717' ? '#fafaf8' : $style->accent }}" stroke="none" />
                <path d="M110 16 L150 16 M130 88 L158 80" />
              </svg>
            @endif
            <span style="position:relative;width:16px;height:2px;border-radius:2px;background:{{ $dark && strtolower($style->accent) === '#171717' ? '#fafaf8' : $style->accent }}"></span>
            <span style="position:relative;width:62%;height:6px;border-radius:3px;background:{{ $dark ? '#fafaf8' : '#171717' }}"></span>
            <span style="position:relative;width:44%;height:4px;border-radius:2px;background:{{ $dark ? 'rgba(250,250,248,.5)' : '#b8b5ae' }}"></span>
          </div>
          <div>
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
              <h2 style="margin:0">{{ $style->name }}</h2>
              <div class="vd-actions">
                <a class="vd-btn vd-btn-sm" href="{{ route('video-demo.studio.styles.index', ['edit' => $style->id]) }}">Edit</a>
                <form method="post" action="{{ route('video-demo.studio.styles.destroy', $style) }}" onsubmit="return confirm('Delete this style?')">@csrf @method('delete')
                  <button class="vd-btn vd-btn-sm vd-btn-ghost vd-btn-danger">Delete</button>
                </form>
              </div>
            </div>
            <p class="vd-small vd-muted" style="margin:6px 0 10px">{{ $style->direction }}</p>
            <div class="vd-actions">
              <span class="vd-pill">{{ VideoStyle::THEMES[$style->theme] ?? $style->theme }} cards</span>
              <span class="vd-pill">Captions at the {{ strtolower(VideoStyle::CAPTIONS[$style->captions] ?? $style->captions) }}</span>
              <span class="vd-pill">{{ VideoStyle::PACES[$style->pace] ?? $style->pace }} pace</span>
              @if ($style->art === 'abstract')<span class="vd-pill">Abstract art</span>@endif
              @if ($style->app_theme !== 'auto')<span class="vd-pill">App in {{ strtolower(VideoStyle::APP_THEMES[$style->app_theme] ?? $style->app_theme) }}</span>@endif
              @if ($style->zoom)<span class="vd-pill">Zooms in on each step</span>@endif
              @if ($style->highlight)<span class="vd-pill">Highlights each step</span>@endif
              @if ($style->voice)<span class="vd-pill">Voice: {{ \Ogunsakin01\VideoDemo\Voice\Voices::label($style->voice) }}</span>@endif
              @if ($style->music)<span class="vd-pill">Music: {{ \Ogunsakin01\VideoDemo\Voice\Music::label($style->music) }}</span>@endif
              <span class="vd-pill" style="display:inline-flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:3px;background:{{ $style->accent }};border:1px solid var(--vd-border)"></span>{{ $style->accent }}</span>
            </div>
          </div>
        </article>
      @endforeach
    </div>

    <form method="post" class="vd-card vd-stack"
      action="{{ $editing ? route('video-demo.studio.styles.update', $editing) : route('video-demo.studio.styles.store') }}">
      @csrf
      @if ($editing) @method('put') @endif
      <h2 style="margin:0">{{ $editing ? 'Edit '.$editing->name : 'Add a style' }}</h2>
      <div>
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required maxlength="80" value="{{ old('name', $form->name) }}" placeholder="Onboarding series">
        @error('name')<span class="vd-error">{{ $message }}</span>@enderror
      </div>
      <div>
        <label for="direction">Direction for the AI</label>
        <textarea id="direction" name="direction" required maxlength="2000" placeholder="Friendly and brief. Address the viewer as “you”. One caption per screen.">{{ old('direction', $form->direction) }}</textarea>
        <span class="vd-hint">Tone, how much to explain, and when to use title cards and captions.</span>
        @error('direction')<span class="vd-error">{{ $message }}</span>@enderror
      </div>
      <div class="vd-row">
        <div>
          <label for="theme">Cards</label>
          <select id="theme" name="theme">
            @foreach (VideoStyle::THEMES as $value => $label)
              <option value="{{ $value }}" @selected(old('theme', $form->theme) === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="captions">Captions</label>
          <select id="captions" name="captions">
            @foreach (VideoStyle::CAPTIONS as $value => $label)
              <option value="{{ $value }}" @selected(old('captions', $form->captions) === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div class="vd-row">
        <div>
          <label for="pace">Pace</label>
          <select id="pace" name="pace">
            @foreach (VideoStyle::PACES as $value => $label)
              <option value="{{ $value }}" @selected(old('pace', $form->pace) === $value)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="accent">Accent</label>
          <input type="text" id="accent" name="accent" required pattern="#[0-9a-fA-F]{6}" value="{{ old('accent', $form->accent) }}" class="vd-mono">
          @error('accent')<span class="vd-error">{{ $message }}</span>@enderror
        </div>
      </div>
      <div>
        <label for="art">Art</label>
        <select id="art" name="art">
          @foreach (VideoStyle::ARTS as $value => $label)
            <option value="{{ $value }}" @selected(old('art', $form->art) === $value)>{{ $label }}</option>
          @endforeach
        </select>
        <span class="vd-hint">Abstract line art draws itself behind title cards, and the words rise into place, like an ad.</span>
      </div>
      <div>
        <label for="app_theme">App appearance</label>
        <select id="app_theme" name="app_theme">
          @foreach (VideoStyle::APP_THEMES as $value => $label)
            <option value="{{ $value }}" @selected(old('app_theme', $form->app_theme ?? 'auto') === $value)>{{ $label }}</option>
          @endforeach
        </select>
        <span class="vd-hint">Records the app in light or dark mode, for apps that follow the device's setting.</span>
      </div>
      <div class="vd-stack-sm">
        <label class="vd-check-row"><input type="checkbox" name="zoom" value="1" @checked(old('zoom', $form->zoom ?? false))> Zoom in on each step</label>
        <label class="vd-check-row"><input type="checkbox" name="highlight" value="1" @checked(old('highlight', $form->highlight ?? false))> Highlight what is being used, dimming the rest</label>
        <span class="vd-hint">Together, these give the video a polished, close-up feel, like the Spotlight style.</span>
      </div>
      <div class="vd-row">
        <div>
          <label for="voice">Voice-over</label>
          <select id="voice" name="voice">
            <option value="">No voice</option>
            @foreach (\Ogunsakin01\VideoDemo\Voice\Voices::options() as $group => $voices)
              <optgroup label="{{ $group }}">
                @foreach ($voices as $key => $label)
                  <option value="{{ $key }}" @selected(old('voice', $form->voice ?? null) === $key)>{{ $label }}</option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
        </div>
        <div>
          <label for="music">Background music</label>
          <select id="music" name="music">
            <option value="">No music</option>
            @foreach (\Ogunsakin01\VideoDemo\Voice\Music::options() as $key => $label)
              <option value="{{ $key }}" @selected(old('music', $form->music ?? null) === $key)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <span class="vd-hint" style="margin-top:-8px">New videos in this style start with these; each video can change them.</span>
      <div class="vd-actions">
        <button class="vd-btn vd-btn-primary">{{ $editing ? 'Save style' : 'Add style' }}</button>
        @if ($editing)<a class="vd-btn vd-btn-ghost" href="{{ route('video-demo.studio.styles.index') }}">Cancel</a>@endif
      </div>
    </form>

    @php($tracks = \Ogunsakin01\VideoDemo\Voice\Music::tracks())
    <section class="vd-card vd-stack" id="music">
      <div>
        <h2 style="margin:0">Music</h2>
        <p class="vd-small vd-muted" style="margin:4px 0 0">Background music for your videos. It loops to the video's length, fades in and out, and gets quieter while the voice speaks. Use music you have the right to publish.</p>
      </div>
      @forelse ($tracks as $key => $track)
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px">
          <strong style="min-width:140px">{{ $track['label'] }}</strong>
          <audio controls preload="none" src="{{ route('video-demo.studio.music.play', $key) }}" style="flex:1;min-width:200px;height:36px"></audio>
          @if ($track['uploaded'])
            <form method="post" action="{{ route('video-demo.studio.music.destroy', $key) }}" onsubmit="return confirm('Delete this track? Videos already made keep their sound.')">@csrf @method('delete')
              <button class="vd-btn vd-btn-sm vd-btn-ghost vd-btn-danger">Delete</button>
            </form>
          @else
            <span class="vd-small vd-muted">From your config</span>
          @endif
        </div>
      @empty
        <p class="vd-small vd-muted" style="margin:0">No tracks yet.</p>
      @endforelse
      <form method="post" action="{{ route('video-demo.studio.music.upload') }}" enctype="multipart/form-data" class="vd-stack-sm">@csrf
        <label for="track">Add a track <span class="vd-optional">MP3, WAV, M4A, OGG or FLAC, up to 50 MB</span></label>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
          <input type="file" id="track" name="track" accept=".mp3,.wav,.m4a,.ogg,.flac,audio/*" required style="flex:1;min-width:200px">
          <button class="vd-btn">Upload</button>
        </div>
        @error('track')<span class="vd-error">{{ $message }}</span>@enderror
      </form>
    </section>
  </div>
</x-video-demo::layout>
