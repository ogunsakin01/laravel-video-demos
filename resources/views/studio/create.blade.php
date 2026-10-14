<x-video-demo::layout title="New video" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => 'create'])
  </x-slot:nav>

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>New video</h1>
      <p class="vd-sub">The AI model reads your app's code, plans the walkthrough, and it is recorded in a browser against this local copy.</p>
    </div>
  </section>

  @unless ($hasKey)
    <div class="vd-flash vd-flash-error" role="alert">Set <code>ANTHROPIC_API_KEY</code> (or your provider's key, see the README) in your <code>.env</code> to generate videos.</div>
  @endunless

  @if ($queueWarning)
    <div class="vd-flash vd-flash-warn" role="status">{{ $queueWarning }}</div>
  @endif

  <form method="post" action="{{ route('video-demo.studio.store') }}" class="vd-split" id="vd-form">
    @csrf
    @if ($defaults['idea'])<input type="hidden" name="idea" value="{{ $defaults['idea'] }}">@endif
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
        <span class="vd-hint">The AI writes one plan for all of them. Each extra version only adds recording time on this machine.</span>
        @error('devices')<span class="vd-error">Choose at least one version.</span>@enderror
      </div>

      <div>
        <div class="vd-field-head">
          <label for="style_id">Style</label>
          <a class="vd-small vd-muted" href="{{ route('video-demo.studio.styles.index') }}">Manage styles</a>
        </div>
        <select id="style_id" name="style_id">
          @foreach ($styles as $style)
            <option value="{{ $style->id }}" data-direction="{{ $style->direction }}" @selected((int) old('style_id', $styles->firstWhere('key', $defaults['style'])?->id ?? $styles->first()->id) === $style->id)>{{ $style->name }}</option>
          @endforeach
        </select>
        <span class="vd-hint" id="vd-style-hint"></span>
        @error('style_id')<span class="vd-error">{{ $message }}</span>@enderror
      </div>

      <div>
        <label for="title">Title <span class="vd-optional">optional</span></label>
        <input type="text" id="title" name="title" value="{{ old('title', $defaults['title']) }}" maxlength="160" placeholder="The AI suggests one">
      </div>

      <div>
        <label for="record_as">Record as</label>
        <select id="record_as" name="record_as">
          @foreach ($recordAs as $value => $label)
            <option value="{{ $value }}" @selected((string) old('record_as', $defaults['record_as']) === (string) $value)>{{ $label }}</option>
          @endforeach
        </select>
        <span class="vd-hint">
          @if (collect(array_keys($recordAs))->contains(fn ($k) => str_starts_with((string) $k, 'type:')))
            The video shows what this type of user sees: each role has its own menus and pages. A user of that type is signed in for the recording.
          @else
            No user types were found in your app's roles. List them in <code>video-demo.recorder.user_types</code> to choose between them here.
          @endif
        </span>
        @error('record_as')<span class="vd-error">{{ $message }}</span>@enderror
      </div>

      <div>
        <label for="start_path">Start page <span class="vd-optional">optional</span></label>
        <div class="vd-combo">
          <input type="text" id="start_path" name="start_path" value="{{ old('start_path', $defaults['start_path']) }}" placeholder="Search your pages, or type a path such as /jobs/5"
            role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="vd-pages" autocomplete="off" spellcheck="false">
          <ul class="vd-combo-list" id="vd-pages" role="listbox" aria-label="Pages" hidden></ul>
        </div>
        <span class="vd-hint" id="vd-pages-note">The pages this user can open are listed when you click the field.</span>
        @error('start_path')<span class="vd-error">{{ $message }}</span>@enderror
      </div>

      <hr class="vd-divider">

      <div class="vd-row">
        <div>
          <label for="model">Model</label>
          <select id="model" name="model">
            @foreach ($models as $id => $label)
              <option value="{{ $id }}" data-note="{{ $modelNotes[$id] ?? '' }}" data-effort="{{ \Ogunsakin01\VideoDemo\Ai\Drivers\Drivers::supportsEffort($id) ? '1' : '0' }}" @selected(old('model', $defaults['model']) === $id)>{{ $label }}</option>
            @endforeach
          </select>
          <span class="vd-hint" id="vd-model-note"></span>
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

      <div class="vd-row">
        <div>
          <label for="max_cost">Spending limit <span class="vd-optional">US $</span></label>
          <input type="number" id="max_cost" name="max_cost" step="0.01" min="0.01" max="100" inputmode="decimal" value="{{ old('max_cost', $defaults['max_cost']) }}">
          <span class="vd-hint">The AI plans within it and stops before going over.</span>
          @error('max_cost')<span class="vd-error">{{ $message }}</span>@enderror
        </div>
        <div>
          <label for="max_steps">Max steps <span class="vd-optional">optional</span></label>
          <input type="number" id="max_steps" name="max_steps" min="3" max="250" inputmode="numeric" value="{{ old('max_steps', $defaults['max_steps']) }}" placeholder="No limit">
          <span class="vd-hint">Fewer steps make a shorter, cheaper plan.</span>
          @error('max_steps')<span class="vd-error">{{ $message }}</span>@enderror
        </div>
      </div>
    </div>

    <aside class="vd-card vd-stack" aria-live="polite">
      <div>
        <p class="vd-eyebrow">Estimated AI cost</p>
        <div class="vd-figure" id="vd-estimate">—</div>
        <div class="vd-small vd-muted" id="vd-estimate-note">&nbsp;</div>
      </div>
      <div class="vd-small vd-error" id="vd-limit-warn" hidden></div>
      <p class="vd-small vd-muted" style="margin:0">Charged to your AI provider's key. Pages are checked for errors before any AI is used. Recording and encoding run on this machine and cost nothing. You can stop a video at any time.</p>
      <button class="vd-btn vd-btn-primary vd-btn-block" type="submit" @disabled(! $hasKey)>Generate video</button>
      <p class="vd-small vd-muted" style="margin:0">Needs a queue worker:<br><code>php artisan queue:work {{ config('video-demo.queue.connection') ?: 'video-demo' }}{{ config('video-demo.queue.connection') ? ' --queue='.config('video-demo.queue.name') : '' }}</code></p>
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

        // Warn when even a cheap run would pass the spending limit
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
          // Snap to the nearest length on offer
          const options = [...segments].map(s => Number(s.dataset.seconds));
          seconds.value = String(options.reduce((a, b) => Math.abs(b - value) < Math.abs(a - value) ? b : a));
          mark();
        }
        segments.forEach(s => s.addEventListener('click', () => { seconds.value = s.dataset.seconds; mark(); estimate(); }));
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
            out.textContent = money(e.low) + ' – ' + money(e.high);
            note.textContent = 'Most likely about ' + money(e.expected) + ', over roughly ' + e.turns + ' requests.';
            last = e;
            checkLimit();
          } catch {
            out.textContent = '—';
          }
        }
        for (const k of ['model', 'effort']) form.elements[k].addEventListener('change', estimate);

        // Effort is a Claude setting; other providers ignore it
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
          // One video per click
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

        // Show what the chosen style asks the AI to do
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
      })();
    </script>
  </x-slot:scripts>
</x-video-demo::layout>
