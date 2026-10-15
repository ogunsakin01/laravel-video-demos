{{--
  The voice-over and background music fields, on the new-video form and in
  "Make it again". Choosing a style fills in its default voice and music.
  Expects: $prefix (for ids), $voice, $music and $volume. A style select with
  data-defaults-for fills them in from its options' data-voice and data-music.
--}}
@php
  $voiceGroups = \Ogunsakin01\VideoDemo\Voice\Voices::options();
  $tracks = \Ogunsakin01\VideoDemo\Voice\Music::options();
  $volume = (int) round(($volume ?? config('video-demo.music.volume', 0.25)) * 100);
@endphp
<div class="vd-row">
  <div>
    <div class="vd-field-head">
      <label for="{{ $prefix }}-voice">Voice-over</label>
      <button type="button" class="vd-btn vd-btn-sm" data-sample="voice" data-for="{{ $prefix }}-voice" @disabled(! $voice)>Play sample</button>
    </div>
    <select id="{{ $prefix }}-voice" name="voice" data-sound="voice">
      <option value="">No voice</option>
      @foreach ($voiceGroups as $group => $voices)
        <optgroup label="{{ $group }}">
          @foreach ($voices as $key => $label)
            <option value="{{ $key }}" @selected($voice === $key)>{{ $label }}</option>
          @endforeach
        </optgroup>
      @endforeach
      @if ($voice && ! \Ogunsakin01\VideoDemo\Voice\Voices::available($voice))
        <option value="{{ $voice }}" selected>{{ $voice }} (not available here)</option>
      @endif
    </select>
    <span class="vd-hint">
      The AI writes a short line for each step and the voice speaks it; each step waits for its line.
      @unless (\Ogunsakin01\VideoDemo\Voice\Voices::localInstalled())
        For free, natural voices, run <code>php artisan video-demo:install-voice</code>.
      @endunless
    </span>
  </div>
  <div>
    <div class="vd-field-head">
      <label for="{{ $prefix }}-music">Background music</label>
      <button type="button" class="vd-btn vd-btn-sm" data-sample="music" data-for="{{ $prefix }}-music" @disabled(! $music)>Play</button>
    </div>
    <select id="{{ $prefix }}-music" name="music" data-sound="music">
      <option value="">No music</option>
      @foreach ($tracks as $key => $label)
        <option value="{{ $key }}" @selected($music === $key)>{{ $label }}</option>
      @endforeach
    </select>
    <label class="vd-small vd-muted" for="{{ $prefix }}-volume" style="display:flex;align-items:center;gap:10px;margin:8px 0 0;font-weight:400">
      Volume <input type="range" id="{{ $prefix }}-volume" name="music_volume" min="5" max="100" step="5" value="{{ $volume }}" style="flex:1">
    </label>
    <span class="vd-hint">
      @if ($tracks === [])
        Add your own tracks on the <a href="{{ route('video-demo.studio.styles.index') }}#music">Styles page</a>.
      @else
        It fades in and out, and gets quieter while the voice speaks. Add tracks on the <a href="{{ route('video-demo.studio.styles.index') }}#music">Styles page</a>.
      @endif
    </span>
  </div>
</div>

@once
  <script>
    // Play a voice sample or a music track, and fill in a style's default voice and music.
    document.addEventListener('DOMContentLoaded', () => {
      let playing = null;
      document.querySelectorAll('[data-sample]').forEach(button => {
        const select = document.getElementById(button.dataset.for);
        const sync = () => { button.disabled = !select.value; };
        select.addEventListener('change', sync);
        button.addEventListener('click', async () => {
          if (playing) { playing.pause(); playing = null; }
          const label = button.textContent;
          button.disabled = true;
          button.setAttribute('aria-busy', 'true');
          try {
            const url = button.dataset.sample === 'voice'
              ? @json(route('video-demo.studio.voice-sample')) + '?' + new URLSearchParams({ voice: select.value })
              : @json(route('video-demo.studio.music.play', '__KEY__')).replace('__KEY__', encodeURIComponent(select.value));
            const res = await fetch(url, { headers: { Accept: 'audio/*, application/json' } });
            if (!res.ok) throw new Error((await res.json().catch(() => ({}))).error || 'It could not be played.');
            playing = new Audio(URL.createObjectURL(await res.blob()));
            if (button.dataset.sample === 'music') playing.volume = Math.min(1, document.getElementById(button.dataset.for.replace(/-music$/, '-volume')).value / 100);
            await playing.play();
          } catch (e) {
            button.title = e.message;
          } finally {
            button.removeAttribute('aria-busy');
            button.textContent = label;
            sync();
          }
        });
      });

      document.querySelectorAll('select[data-defaults-for]').forEach(style => {
        style.addEventListener('change', () => {
          const option = style.selectedOptions[0];
          const form = style.form;
          for (const kind of ['voice', 'music']) {
            const field = form.querySelector(`[data-sound="${kind}"]`);
            // Only a style's own default changes the field; one without leaves your choice.
            if (field && option.dataset[kind] && [...field.options].some(o => o.value === option.dataset[kind])) {
              field.value = option.dataset[kind];
              field.dispatchEvent(new Event('change'));
            }
          }
        });
      });
    });
  </script>
@endonce
