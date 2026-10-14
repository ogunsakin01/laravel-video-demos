<x-video-demo::layout title="New video" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    <a class="vd-btn" href="{{ route('video-demo.studio.index') }}">All videos</a>
  </x-slot:nav>

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>New video</h1>
      <p class="vd-sub">Claude reads your app's code, plans the walkthrough, and records it in a browser against this local copy.</p>
    </div>
  </section>

  @unless ($hasKey)
    <div class="vd-flash vd-flash-error" role="alert">Set <code>ANTHROPIC_API_KEY</code> in your <code>.env</code> to generate videos.</div>
  @endunless

  <form method="post" action="{{ route('video-demo.studio.store') }}" class="vd-split" id="vd-form">
    @csrf
    <div class="vd-card vd-stack">
      <div>
        <div class="vd-field-head">
          <label for="prompt">What should the video show?</label>
          <button type="button" class="vd-btn vd-btn-sm" id="vd-complete" @disabled(! $hasKey)>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
            <span>Complete with AI</span>
          </button>
        </div>
        <textarea id="prompt" name="prompt" required maxlength="4000" placeholder="A few words are enough, such as “approve holiday request”. Complete with AI writes the rest.">{{ old('prompt', $defaults['prompt']) }}</textarea>
        <span class="vd-ai-note" id="vd-complete-note" aria-live="polite">Describe the flow the way your users would ask about it. Leave it empty and Complete with AI suggests a video.</span>
        @error('prompt')<span class="vd-error">{{ $message }}</span>@enderror
      </div>

      <div>
        <span class="vd-label" id="vd-length-label">Length</span>
        <input type="hidden" id="target_seconds" name="target_seconds" value="{{ old('target_seconds', $defaults['target_seconds']) }}">
        <div class="vd-segments" role="group" aria-labelledby="vd-length-label">
          @foreach ([30 => '30 sec', 60 => '1 min', 90 => '1½ min', 120 => '2 min', 180 => '3 min'] as $seconds => $label)
            @if ($seconds <= config('video-demo.limits.max_seconds', 300))
              <button type="button" class="vd-segment" data-seconds="{{ $seconds }}">{{ $label }}</button>
            @endif
          @endforeach
        </div>
        <span class="vd-hint">A target. Captions and pauses stretch or shrink to land close to it.</span>
      </div>

      <div>
        <span class="vd-label" id="vd-devices-label">Versions</span>
        @include('video-demo::studio.partials.devices', ['devices' => $devices, 'selected' => old('devices', $defaults['devices'])])
        <span class="vd-hint">Claude writes one plan for all of them. Each extra version only adds recording time on this machine.</span>
        @error('devices')<span class="vd-error">Choose at least one version.</span>@enderror
      </div>

      <div>
        <div class="vd-field-head">
          <label for="style_id">Style</label>
          <a class="vd-small vd-muted" href="{{ route('video-demo.studio.styles.index') }}">Manage styles</a>
        </div>
        <select id="style_id" name="style_id">
          @foreach ($styles as $style)
            <option value="{{ $style->id }}" data-direction="{{ $style->direction }}" @selected((int) old('style_id', $styles->first()->id) === $style->id)>{{ $style->name }}</option>
          @endforeach
        </select>
        <span class="vd-hint" id="vd-style-hint"></span>
        @error('style_id')<span class="vd-error">{{ $message }}</span>@enderror
      </div>

      <div class="vd-row">
        <div>
          <label for="title">Title <span class="vd-optional">optional</span></label>
          <input type="text" id="title" name="title" value="{{ old('title') }}" maxlength="160" placeholder="Claude suggests one">
        </div>
        <div>
          <label for="start_path">Start page <span class="vd-optional">optional</span></label>
          <input type="text" id="start_path" name="start_path" value="{{ old('start_path') }}" placeholder="/dashboard">
        </div>
      </div>

      <hr class="vd-divider">

      <div class="vd-row">
        <div>
          <label for="model">Model</label>
          <select id="model" name="model">
            @foreach ($models as $id => $label)
              <option value="{{ $id }}" @selected(old('model', $defaults['model']) === $id)>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="effort">Effort</label>
          <select id="effort" name="effort">
            @foreach ($efforts as $effort)
              <option value="{{ $effort }}" @selected(old('effort', $defaults['effort']) === $effort)>{{ ucfirst($effort) }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label for="visibility">Library</label>
          <select id="visibility" name="visibility">
            <option value="public" @selected(old('visibility') !== 'private')>Public</option>
            <option value="private" @selected(old('visibility') === 'private')>Private (staff)</option>
          </select>
        </div>
      </div>
    </div>

    <aside class="vd-card vd-stack" aria-live="polite">
      <div>
        <p class="vd-eyebrow">Estimated Claude cost</p>
        <div class="vd-figure" id="vd-estimate">—</div>
        <div class="vd-small vd-muted" id="vd-estimate-note">&nbsp;</div>
      </div>
      <p class="vd-small vd-muted" style="margin:0">Charged to your Anthropic API key. Recording and encoding run on this machine and cost nothing. Generation stops if it passes ${{ number_format(config('video-demo.limits.max_cost_per_video'), 2) }}.</p>
      <button class="vd-btn vd-btn-primary vd-btn-block" type="submit" @disabled(! $hasKey)>Generate video</button>
      <p class="vd-small vd-muted" style="margin:0">Needs a queue worker:<br><code>php artisan queue:work --timeout=1800</code></p>
    </aside>
  </form>

  <x-slot:scripts>
    <script>
      (() => {
        const form = document.getElementById('vd-form');
        const token = form.elements._token.value;
        const seconds = document.getElementById('target_seconds');
        const segments = form.querySelectorAll('[data-seconds]');
        const out = document.getElementById('vd-estimate');
        const note = document.getElementById('vd-estimate-note');
        const money = n => '$' + (n < 0.1 ? n.toFixed(3) : n.toFixed(2));
        let seq = 0;

        const mark = () => segments.forEach(s => s.setAttribute('aria-pressed', String(s.dataset.seconds === seconds.value)));
        const setSeconds = value => {
          // Snap to the nearest length on offer
          const options = [...segments].map(s => Number(s.dataset.seconds));
          seconds.value = String(options.reduce((a, b) => Math.abs(b - value) < Math.abs(a - value) ? b : a));
          mark();
        };
        segments.forEach(s => s.addEventListener('click', () => { seconds.value = s.dataset.seconds; mark(); estimate(); }));
        mark();

        const post = (url, data) => {
          const body = new FormData();
          for (const [k, v] of Object.entries(data)) body.append(k, v);
          body.append('_token', token);
          return fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' } });
        };

        async function estimate() {
          const mine = ++seq;
          try {
            const res = await post(@json(route('video-demo.studio.estimate')), { model: form.elements.model.value, effort: form.elements.effort.value, target_seconds: seconds.value });
            const e = await res.json();
            if (mine !== seq) return;
            out.textContent = money(e.low) + ' – ' + money(e.high);
            note.textContent = 'Most likely about ' + money(e.expected) + ', over roughly ' + e.turns + ' requests.';
          } catch {
            out.textContent = '—';
          }
        }
        for (const k of ['model', 'effort']) form.elements[k].addEventListener('change', estimate);
        estimate();

        // Show what the chosen style asks Claude to do
        const styleSelect = form.elements.style_id;
        const styleHint = document.getElementById('vd-style-hint');
        const showStyle = () => { styleHint.textContent = styleSelect.selectedOptions[0]?.dataset.direction || ''; };
        styleSelect.addEventListener('change', showStyle);
        showStyle();

        // Complete with AI: one short request turns a rough note into the fields
        const complete = document.getElementById('vd-complete');
        const label = complete.querySelector('span');
        const status = document.getElementById('vd-complete-note');
        complete.addEventListener('click', async () => {
          complete.disabled = true;
          label.textContent = 'Writing…';
          complete.querySelector('svg').classList.add('vd-spin');
          status.removeAttribute('data-error');
          try {
            const res = await post(@json(route('video-demo.studio.complete')), { prompt: form.elements.prompt.value, model: form.elements.model.value });
            const d = await res.json();
            if (!res.ok) throw new Error(d.error || d.message || 'Something went wrong.');
            form.elements.prompt.value = d.prompt;
            if (d.title) form.elements.title.value = d.title;
            if (d.start_path) form.elements.start_path.value = d.start_path;
            if (d.target_seconds) setSeconds(d.target_seconds);
            status.textContent = 'Written by Claude for ' + money(d.cost) + '. Edit anything before you generate.';
            estimate();
          } catch (e) {
            status.textContent = e.message;
            status.setAttribute('data-error', '');
          } finally {
            complete.disabled = false;
            label.textContent = 'Complete with AI';
            complete.querySelector('svg').classList.remove('vd-spin');
          }
        });
      })();
    </script>
  </x-slot:scripts>
</x-video-demo::layout>
