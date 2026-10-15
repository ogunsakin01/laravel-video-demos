<x-video-demo::layout title="New video" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => 'create'])
  </x-slot:nav>

  <section class="vd-hero">
    @include('video-demo::partials.art', ['name' => 'spark'])
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>New video</h1>
      <p class="vd-sub">Describe a flow. The AI plans it from your code, and a browser records it.</p>
    </div>
  </section>

  @unless ($hasKey)
    <div class="vd-flash vd-flash-error" role="alert">Set <code>ANTHROPIC_API_KEY</code> (or <a href="https://github.com/ogunsakin01/laravel-video-demos/wiki/AI-Providers-and-Costs#providers" target="_blank" rel="noopener">another provider's key</a>) in your <code>.env</code> to generate videos.</div>
  @endunless

  @include('video-demo::studio.partials.worker-warning')
  @if ($queueWarning)
    <div class="vd-flash vd-flash-warn" role="status">{{ $queueWarning }}</div>
  @endif

  <form method="post" action="{{ route('video-demo.studio.store') }}" class="vd-split" id="vd-form">
    @csrf
    @if ($defaults['idea'])<input type="hidden" name="idea" value="{{ $defaults['idea'] }}">@endif
    <div class="vd-card">
      <section class="vd-section">
        <div class="vd-section-head"><span class="vd-section-num">1</span><h2 class="vd-section-title">What should it show?</h2></div>
        <div class="vd-field">
          <div class="vd-field-head">
            <label for="prompt">Description</label>
            <button type="button" class="vd-btn vd-btn-sm" id="vd-complete" @disabled(! $hasKey)>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
              <span>Complete with AI</span>
            </button>
          </div>
          <textarea id="prompt" name="prompt" required maxlength="4000" placeholder="“Approve a holiday request” is enough">{{ old('prompt', $defaults['prompt']) }}</textarea>
          <span class="vd-ai-note" id="vd-complete-note" aria-live="polite">A few words are enough. Complete with AI writes the rest, or suggests a video when it's empty.</span>
          @error('prompt')<span class="vd-error">{{ $message }}</span>@enderror
        </div>

        <div class="vd-field">
          <span class="vd-label" id="vd-length-label">Length</span>
          <input type="hidden" id="target_seconds" name="target_seconds" value="{{ old('target_seconds', $defaults['target_seconds']) }}">
          <div class="vd-segments" role="group" aria-labelledby="vd-length-label">
            @foreach ([30 => '30 sec', 60 => '1 min', 90 => '1½ min', 120 => '2 min', 180 => '3 min'] as $seconds => $label)
              @if ($seconds <= config('video-demo.limits.max_seconds', 300))
                <button type="button" class="vd-segment" data-seconds="{{ $seconds }}">{{ $label }}</button>
              @endif
            @endforeach
          </div>
        </div>

        <div class="vd-field">
          <div class="vd-label-line"><span class="vd-label" id="vd-devices-label">Versions</span>
            <details class="vd-tip"><summary aria-label="About versions">?</summary><div class="vd-tip-body">The AI writes one plan; it is recorded on each screen you pick. Extra versions add recording time on this machine, not AI cost.</div></details>
          </div>
          @include('video-demo::studio.partials.devices', ['devices' => $devices, 'selected' => old('devices', $defaults['devices'])])
          @error('devices')<span class="vd-error">Choose at least one version.</span>@enderror
        </div>
      </section>

      <section class="vd-section">
        <div class="vd-section-head"><span class="vd-section-num">2</span><h2 class="vd-section-title">Who, and where?</h2></div>
        <div class="vd-row">
          <div class="vd-field">
            <div class="vd-label-line"><label for="record_as">Record as</label>
              <details class="vd-tip"><summary aria-label="About record as">?</summary><div class="vd-tip-body">
                @if (collect(array_keys($recordAs))->contains(fn ($k) => str_starts_with((string) $k, 'type:')))
                  The video shows what this type of user sees: each role has its own menus and pages. A user of that type is signed in for the recording.
                @else
                  No user types were found in your app's roles. List them in <code>video-demo.recorder.user_types</code> to choose between them here.
                @endif
              </div></details>
            </div>
            <select id="record_as" name="record_as">
              @foreach ($recordAs as $value => $label)
                <option value="{{ $value }}" @selected((string) old('record_as', $defaults['record_as']) === (string) $value)>{{ $label }}</option>
              @endforeach
            </select>
            @error('record_as')<span class="vd-error">{{ $message }}</span>@enderror
          </div>

          <div class="vd-field">
            <label for="start_path">Start page <span class="vd-optional">optional</span></label>
            <div class="vd-combo">
              <input type="text" id="start_path" name="start_path" value="{{ old('start_path', $defaults['start_path']) }}" placeholder="Search, or type /jobs/5"
                role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="vd-pages" autocomplete="off" spellcheck="false">
              <ul class="vd-combo-list" id="vd-pages" role="listbox" aria-label="Pages" hidden></ul>
            </div>
            <span class="vd-hint" id="vd-pages-note">Click to list the pages this user can open.</span>
            @error('start_path')<span class="vd-error">{{ $message }}</span>@enderror
          </div>
        </div>

        <div class="vd-field">
          <input type="hidden" name="sample_data" value="0">
          <label class="vd-toggle vd-toggle-wide">
            <input type="checkbox" name="sample_data" value="1" @checked(old('sample_data', $defaults['sample_data']))>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5.5" rx="7.5" ry="2.5"/><path d="M4.5 5.5v13c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5v-13M4.5 12c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5"/></svg>
            <span class="vd-toggle-text"><span class="vd-toggle-name">Sample data</span><span class="vd-toggle-size">A throwaway copy of your database, filled for the video. Yours is never touched.</span></span>
            <span class="vd-switch" aria-hidden="true"></span>
          </label>
          @error('sample_data')<span class="vd-error">{{ $message }}</span>@enderror
        </div>
      </section>

      <section class="vd-section">
        <div class="vd-section-head"><span class="vd-section-num">3</span><h2 class="vd-section-title">Look and sound</h2></div>
        <div class="vd-field">
          <div class="vd-field-head">
            <label for="style_id">Style</label>
            <a class="vd-small vd-muted" href="{{ route('video-demo.studio.styles.index') }}">Manage styles</a>
          </div>
          <select id="style_id" name="style_id" data-defaults-for="sound">
            @foreach ($styles as $style)
              <option value="{{ $style->id }}" data-direction="{{ $style->direction }}" data-voice="{{ $style->voice }}" data-music="{{ $style->music }}" data-accent="{{ $style->accent }}" data-theme="{{ $style->theme }}" @selected((int) old('style_id', $styles->firstWhere('key', $defaults['style'])?->id ?? $styles->first()->id) === $style->id)>{{ $style->name }}</option>
            @endforeach
          </select>
          <div class="vd-swatch" aria-hidden="true"><span class="vd-swatch-frame" id="vd-swatch-frame"><span id="vd-swatch-bar"></span></span><span id="vd-style-hint" style="min-width:0"></span></div>
          @error('style_id')<span class="vd-error">{{ $message }}</span>@enderror
        </div>

        @include('video-demo::studio.partials.sound', ['prefix' => 'new', 'voice' => old('voice', $defaults['voice']), 'music' => old('music', $defaults['music']), 'volume' => null, 'language' => old('language', $defaults['language'])])
      </section>

      <section class="vd-section">
        <details class="vd-more" @if ($errors->hasAny(['max_cost', 'max_steps', 'speed', 'title'])) open @endif>
          <summary>More options <small>speed, model, spending limit, title</small></summary>
          <div class="vd-field">
            <div class="vd-label-line"><span class="vd-label" id="vd-speed-label">Playback speed</span>
              <details class="vd-tip"><summary aria-label="About playback speed">?</summary><div class="vd-tip-body">At 1× the cursor, typing and scrolling move at a person's pace. Faster speeds play those quicker; captions and title cards stay readable either way. Waiting on your app is cut out at any speed.</div></details>
            </div>
            <input type="hidden" id="speed" name="speed" value="{{ old('speed', $defaults['speed']) }}">
            <div class="vd-segments" role="group" aria-labelledby="vd-speed-label">
              @foreach (['1' => '1×', '1.25' => '1.25×', '1.5' => '1.5×', '2' => '2×', '3' => '3×'] as $value => $label)
                <button type="button" class="vd-segment" data-speed="{{ $value }}">{{ $label }}</button>
              @endforeach
            </div>
            @error('speed')<span class="vd-error">{{ $message }}</span>@enderror
          </div>

          <div class="vd-row">
            <div class="vd-field">
              <label for="model">Model</label>
              <select id="model" name="model">
                @foreach ($models as $id => $label)
                  <option value="{{ $id }}" data-note="{{ $modelNotes[$id] ?? '' }}" data-effort="{{ \Ogunsakin01\VideoDemo\Ai\Drivers\Drivers::supportsEffort($id) ? '1' : '0' }}" @selected(old('model', $defaults['model']) === $id)>{{ $label }}</option>
                @endforeach
              </select>
              <span class="vd-hint" id="vd-model-note"></span>
            </div>
            <div class="vd-field">
              <label for="effort">Effort</label>
              <select id="effort" name="effort">
                @foreach ($efforts as $effort)
                  <option value="{{ $effort }}" @selected(old('effort', $defaults['effort']) === $effort)>{{ ucfirst($effort) }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <div class="vd-row">
            <div class="vd-field">
              <div class="vd-label-line"><label for="max_cost">Spending limit <span class="vd-optional">US $</span></label>
                <details class="vd-tip"><summary aria-label="About the spending limit">?</summary><div class="vd-tip-body">The AI is told its budget, plans within it and stops before going over.</div></details>
              </div>
              <input type="number" id="max_cost" name="max_cost" step="0.01" min="0.01" max="100" inputmode="decimal" value="{{ old('max_cost', $defaults['max_cost']) }}">
              @error('max_cost')<span class="vd-error">{{ $message }}</span>@enderror
            </div>
            <div class="vd-field">
              <label for="max_steps">Max steps <span class="vd-optional">optional</span></label>
              <input type="number" id="max_steps" name="max_steps" min="3" max="250" inputmode="numeric" value="{{ old('max_steps', $defaults['max_steps']) }}" placeholder="No limit">
              @error('max_steps')<span class="vd-error">{{ $message }}</span>@enderror
            </div>
          </div>

          <div class="vd-row">
            <div class="vd-field">
              <label for="title">Title <span class="vd-optional">optional</span></label>
              <input type="text" id="title" name="title" value="{{ old('title', $defaults['title']) }}" maxlength="160" placeholder="The AI suggests one">
            </div>
            <div class="vd-field">
              <label for="visibility">Library</label>
              <select id="visibility" name="visibility">
                <option value="public" @selected(old('visibility') !== 'private')>Public</option>
                <option value="private" @selected(old('visibility') === 'private')>Private (staff)</option>
              </select>
            </div>
          </div>
        </details>
      </section>
    </div>

    <aside class="vd-card vd-stack vd-sticky" aria-live="polite">
      <div>
        <p class="vd-eyebrow">Your video</p>
        <ul class="vd-recipe" id="vd-recipe">
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span data-recipe="length"></span></li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg><span data-recipe="versions"></span></li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg><span data-recipe="who"></span></li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 0 0 0 18c1.5 0 2-1 2-2s-1-2 0-3 3-1 4-1c2 0 3-2 3-4 0-4.5-4-8-9-8z"/></svg><span data-recipe="style"></span></li>
          <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V6l11-2v12"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/></svg><span data-recipe="sound"></span></li>
        </ul>
      </div>
      <hr class="vd-divider" style="margin:0">
      <div>
        <p class="vd-eyebrow">Estimated AI cost</p>
        <div class="vd-figure" id="vd-estimate">—</div>
        <div class="vd-small vd-muted" id="vd-estimate-note">&nbsp;</div>
      </div>
      <div class="vd-small vd-error" id="vd-limit-warn" hidden></div>
      <button class="vd-btn vd-btn-primary vd-btn-block" type="submit" @disabled(! $hasKey)>Generate video</button>
      <div class="vd-field vd-small vd-muted">Recording is free and runs here. Stop it any time.
        <details class="vd-tip"><summary aria-label="About costs and the queue">?</summary><div class="vd-tip-body">Charged to your AI provider's key. Pages are checked for errors before any AI is used. Videos are made by a queue worker: <code>php artisan video-demo:work</code> makes several at once.</div></details>
      </div>
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
        let last = null;

        // Warn when even a cheap run would pass the spending limit.
        const limit = form.elements.max_cost;
        const warn = document.getElementById('vd-limit-warn');
        function checkLimit() {
          const max = Number(limit.value);
          warn.hidden = !(last && last.known && max > 0 && last.low > max);
          if (!warn.hidden) warn.textContent = 'The limit of ' + money(max) + ' is below the lowest estimate, so the AI will likely stop before the plan is done. Raise it, pick a cheaper model or a shorter length.';
        }
        limit.addEventListener('input', checkLimit);

        const mark = () => segments.forEach(s => s.setAttribute('aria-pressed', String(s.dataset.seconds === seconds.value)));
        function setSeconds(value) {
          // Snap to the nearest length on offer.
          const options = [...segments].map(s => Number(s.dataset.seconds));
          seconds.value = String(options.reduce((a, b) => Math.abs(b - value) < Math.abs(a - value) ? b : a));
          mark();
        }
        segments.forEach(s => s.addEventListener('click', () => { seconds.value = s.dataset.seconds; mark(); estimate(); }));

        // Playback speed: the nearest option is marked
        const speed = document.getElementById('speed');
        const speeds = form.querySelectorAll('[data-speed]');
        const markSpeed = () => {
          const options = [...speeds].map(b => Number(b.dataset.speed));
          const nearest = options.reduce((a, b) => Math.abs(b - speed.value) < Math.abs(a - speed.value) ? b : a);
          speeds.forEach(b => b.setAttribute('aria-pressed', String(Number(b.dataset.speed) === nearest)));
        };
        speeds.forEach(b => b.addEventListener('click', () => { speed.value = b.dataset.speed; markSpeed(); }));
        markSpeed();
        setSeconds(Number(seconds.value));

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
            if (!e.known) {
              out.textContent = 'Unknown';
              note.textContent = 'This model has no prices in config/video-demo.php, so its cost can\'t be shown.';
              return;
            }
            const text = money(e.low) + ' – ' + money(e.high);
            if (out.textContent !== text) { out.textContent = text; out.classList.remove('vd-pop'); void out.offsetWidth; out.classList.add('vd-pop'); }
            note.textContent = 'Most likely about ' + money(e.expected) + ', over roughly ' + e.turns + ' requests.';
            last = e;
            checkLimit();
          } catch {
            out.textContent = '—';
          }
        }
        for (const k of ['model', 'effort']) form.elements[k].addEventListener('change', estimate);

        // Effort is a Claude setting; other providers ignore it.
        const effort = form.elements.effort;
        const modelNote = document.getElementById('vd-model-note');
        const syncEffort = () => {
          const option = form.elements.model.selectedOptions[0];
          effort.disabled = option?.dataset.effort === '0';
          modelNote.textContent = option?.dataset.note ? 'This model is the ' + option.dataset.note + '.' : '';
        };
        form.elements.model.addEventListener('change', syncEffort);
        syncEffort();
        form.addEventListener('submit', e => {
          const submit = form.querySelector('button[type=submit]');
          // Ignore repeat clicks, so one click starts one video.
          if (submit.getAttribute('aria-busy')) { e.preventDefault(); return; }
          effort.disabled = false;
          submit.setAttribute('aria-busy', 'true');
          submit.textContent = 'Starting…';
        });
        estimate();

        // Start page: a searchable list of the pages the chosen user can open,
        // found by opening each GET route in a browser. Any path can be typed too.
        (() => {
          const input = form.elements.start_path;
          const list = document.getElementById('vd-pages');
          const pagesNote = document.getElementById('vd-pages-note');
          const who = form.elements.record_as;
          const endpoint = @json(route('video-demo.studio.pages'));
          const cache = {};
          let pages = null, shown = [], active = -1, loading = null;

          const esc = s => s.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
          const setNote = (html) => { pagesNote.innerHTML = html; };

          async function load(fresh = false) {
            const key = who.value;
            if (cache[key] && !fresh) { pages = cache[key].pages; describe(cache[key]); return; }
            pages = null;
            setNote('Checking which pages this user can open…');
            render();
            const mine = loading = fetch(endpoint + '?' + new URLSearchParams({ record_as: key, fresh: fresh ? 1 : 0 }), { headers: { Accept: 'application/json' } })
              .then(r => r.json());
            try {
              const d = await mine;
              if (loading !== mine) return;
              if (d.error) throw new Error(d.error);
              cache[key] = d;
              pages = d.pages;
              describe(d);
            } catch (e) {
              pages = [];
              setNote(esc(e.message || 'Could not check the pages.') + ' You can still type a path.');
            }
            render();
          }

          function describe(d) {
            setNote(d.pages.length + ' pages this user can open' + (d.skipped ? ', ' + d.skipped + ' other routes left out (they redirect, fail or aren’t pages)' : '')
              + '. Pages that need an id, such as /jobs/5, can be typed. <button type="button" class="vd-link" id="vd-pages-refresh">Check again</button>');
            document.getElementById('vd-pages-refresh').addEventListener('click', () => load(true));
          }

          function render() {
            list.replaceChildren();
            if (pages === null) {
              list.innerHTML = '<li class="vd-combo-empty" aria-disabled="true"><span class="vd-combo-spinner" aria-hidden="true"></span>Checking pages…</li>';
              return;
            }
            const q = input.value.trim().toLowerCase();
            shown = pages.filter(p => !q || p.path.toLowerCase().includes(q) || (p.title || '').toLowerCase().includes(q) || (p.name || '').toLowerCase().includes(q)).slice(0, 80);
            if (!shown.length) {
              list.innerHTML = '<li class="vd-combo-empty" aria-disabled="true">' + (q.startsWith('/') ? 'No listed page matches. ' + esc(q) + ' will be used as typed.' : 'No page matches.') + '</li>';
              active = -1;
              return;
            }
            active = Math.min(active, shown.length - 1);
            shown.forEach((p, i) => {
              const li = document.createElement('li');
              li.id = 'vd-page-' + i;
              li.setAttribute('role', 'option');
              li.setAttribute('aria-selected', String(i === active));
              li.innerHTML = '<span class="vd-combo-title">' + esc(p.title || p.path) + '</span><span class="vd-combo-path">' + esc(p.path) + '</span>';
              li.addEventListener('mousedown', e => { e.preventDefault(); choose(i); });
              list.append(li);
            });
          }

          const open = () => { list.hidden = false; input.setAttribute('aria-expanded', 'true'); render(); };
          const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
          function choose(i) {
            input.value = shown[i].path;
            close();
          }
          function move(by) {
            if (list.hidden) open();
            if (!shown.length) return;
            active = (active + by + shown.length) % shown.length;
            render();
            const el = document.getElementById('vd-page-' + active);
            input.setAttribute('aria-activedescendant', el.id);
            el.scrollIntoView({ block: 'nearest' });
          }

          input.addEventListener('focus', () => { if (pages === null && !loading) load(); open(); });
          input.addEventListener('input', () => { active = -1; open(); });
          input.addEventListener('blur', () => setTimeout(close, 100));
          input.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Enter' && !list.hidden && active >= 0) { e.preventDefault(); choose(active); }
            else if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); close(); }
          });
          who.addEventListener('change', () => { loading = null; pages = null; if (document.activeElement === input) load(); else setNote('The pages this user can open are listed when you click the field.'); });
        })();

        // Show what the chosen style asks the AI model to do.
        const styleSelect = form.elements.style_id;
        const styleHint = document.getElementById('vd-style-hint');
        const swatch = document.getElementById('vd-swatch-frame'), swatchBar = document.getElementById('vd-swatch-bar');
        const showStyle = () => {
          const o = styleSelect.selectedOptions[0];
          styleHint.textContent = o?.dataset.direction || '';
          swatch.style.background = o?.dataset.theme === 'dark' ? '#171717' : '#faf8f3';
          swatchBar.style.background = o?.dataset.accent || '#171717';
        };
        styleSelect.addEventListener('change', showStyle);
        showStyle();

        // Complete with AI: one short request turns a rough note into the form fields.
        const complete = document.getElementById('vd-complete');
        const label = complete.querySelector('span');
        const status = document.getElementById('vd-complete-note');
        complete.addEventListener('click', async () => {
          complete.disabled = true;
          label.textContent = 'Writing…';
          complete.setAttribute('aria-busy', 'true');
          status.removeAttribute('data-error');
          try {
            const res = await post(@json(route('video-demo.studio.complete')), { prompt: form.elements.prompt.value, model: form.elements.model.value });
            const d = await res.json();
            if (!res.ok) throw new Error(d.error || d.message || 'Something went wrong.');
            form.elements.prompt.value = d.prompt;
            if (d.title) form.elements.title.value = d.title;
            if (d.start_path) form.elements.start_path.value = d.start_path;
            if (d.target_seconds) setSeconds(d.target_seconds);
            status.textContent = (d.cost_known ? 'Written by AI for ' + money(d.cost) : 'Written by AI') + '. Edit anything before you generate.';
            estimate();
          } catch (e) {
            status.textContent = e.message;
            status.setAttribute('data-error', '');
          } finally {
            complete.disabled = false;
            label.textContent = 'Complete with AI';
            complete.removeAttribute('aria-busy');
          }
        });

        // "Your video": a live summary of what is being set up.
        const recipe = document.getElementById('vd-recipe');
        const put = (key, text) => {
          const el = recipe.querySelector(`[data-recipe="${key}"]`);
          if (el.textContent === text) return;
          el.textContent = text;
          el.title = text;
          el.classList.remove('vd-changed'); void el.offsetWidth; el.classList.add('vd-changed');
        };
        const optionText = el => el?.selectedOptions[0]?.textContent.trim() || '';
        function summarise() {
          const length = form.querySelector('[data-seconds][aria-pressed=true]');
          put('length', (length ? length.textContent.trim() : seconds.value + ' sec') + ' at ' + speed.value + '×');
          const versions = [...form.querySelectorAll('input[name="devices[]"]:checked')].map(i => i.closest('label').querySelector('.vd-toggle-name').textContent.trim());
          put('versions', versions.length ? versions.join(', ') : 'No version chosen');
          put('who', optionText(form.elements.record_as) + (form.querySelector('input[type=checkbox][name=sample_data]').checked ? ', sample data' : ', your data'));
          put('style', optionText(styleSelect) + ' style');
          const voice = form.elements.voice.value ? optionText(form.elements.voice) : 'No voice';
          const music = form.elements.music.value ? optionText(form.elements.music) : 'no music';
          put('sound', voice + ', ' + music.charAt(0).toLowerCase() + music.slice(1));
        }
        form.addEventListener('change', summarise);
        form.addEventListener('click', e => { if (e.target.closest('.vd-segment')) setTimeout(summarise); });
        summarise();
      })();
    </script>
  </x-slot:scripts>
</x-video-demo::layout>
