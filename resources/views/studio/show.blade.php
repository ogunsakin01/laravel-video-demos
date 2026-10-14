<x-video-demo::layout :title="$video->title ?: 'Video'" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => null])
  </x-slot:nav>

  <section class="vd-hero">
    <div style="min-width:0">
      <p class="vd-eyebrow">Video</p>
      <h1>{{ $video->title ?: \Illuminate\Support\Str::limit($video->prompt, 70) }}</h1>
      <p class="vd-sub" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
        <span id="vd-status">@include('video-demo::studio.partials.status', ['status' => $video->status])</span>
        @if ($video->isPublished())
          <span class="vd-pill">{{ $video->visibility === 'public' ? 'Public' : 'Private' }} · published {{ $video->published_at->diffForHumans() }}</span>
          @if (config('video-demo.library.public.enabled') && $video->visibility === 'public')
            <a class="vd-small" href="{{ route('video-demo.public.show', $video->slug) }}">Open in library</a>
          @endif
        @endif
      </p>
    </div>
    @if (in_array($video->status, [\Ogunsakin01\VideoDemo\Enums\VideoStatus::Failed, \Ogunsakin01\VideoDemo\Enums\VideoStatus::Stopped], true))
        @php($hasPlan = ! empty($video->plan['steps']))
        <div class="vd-actions">
          <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
            <input type="hidden" name="mode" value="plan">
            <button class="vd-btn vd-btn-primary">{{ $hasPlan ? 'Try again with a new plan' : 'Try again' }}</button>
          </form>
          @if ($hasPlan)
            <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
              <input type="hidden" name="mode" value="record">
              <button class="vd-btn">Record the same plan again</button>
            </form>
          @endif
        </div>
    @endif
    @if ($video->status->isWorking())
      <form method="post" action="{{ route('video-demo.studio.stop', $video) }}" class="vd-actions" id="vd-stop">@csrf
        <button class="vd-btn vd-btn-danger" @disabled($video->stop_requested)>{{ $video->stop_requested ? 'Stopping…' : 'Stop' }}</button>
      </form>
    @endif
    @if ($video->status === \Ogunsakin01\VideoDemo\Enums\VideoStatus::Ready)
      <div class="vd-actions">
        @if ($video->isPublished())
          <form method="post" action="{{ route('video-demo.studio.unpublish', $video) }}">@csrf
            <button class="vd-btn">Unpublish</button>
          </form>
        @endif
        <form method="post" action="{{ route('video-demo.studio.publish', $video) }}">@csrf
          <button class="vd-btn vd-btn-primary">{{ $video->isPublished() ? 'Publish this version' : 'Approve and publish' }}</button>
        </form>
      </div>
    @endif
  </section>

  @if ($queueWarning)
    <div class="vd-flash vd-flash-warn" role="status">{{ $queueWarning }}</div>
  @endif

  @if (! empty($video->issues))
    <div class="vd-flash vd-flash-error vd-stack-sm" role="alert" id="vd-issues">
      <strong>{{ count($video->issues) === 1 ? 'A page in your app has a problem' : count($video->issues).' pages in your app have problems' }}, so the video wasn't recorded</strong>
      <ul class="vd-issues">
        @foreach ($video->issues as $issue)
          <li>
            <span><span class="vd-mono">{{ $issue['path'] }}</span>: {{ $issue['problem'] }}</span>
            @if (! empty($issue['detail']))<span class="vd-detail">{{ $issue['detail'] }}</span>@endif
          </li>
        @endforeach
      </ul>
      @if ($video->sample_data)
        <span class="vd-small">The AI couldn't add the data {{ count($video->issues) === 1 ? 'this page needs' : 'these pages need' }} to the sample database. Fix {{ count($video->issues) === 1 ? 'it' : 'them' }} in your app, or say in the description what the user needs, then try again.</span>
      @else
        <span class="vd-small">No AI was spent on the broken {{ count($video->issues) === 1 ? 'page' : 'pages' }}. If {{ count($video->issues) === 1 ? 'it works' : 'they work' }} for users with the right data, such as a role, a permission or a finished profile, record on sample data instead: a copy of your database where the AI adds what the pages need. Your own data isn't touched. Otherwise, fix {{ count($video->issues) === 1 ? 'it' : 'them' }} in your app and try again.</span>
        @unless ($video->status->isWorking())
          <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
            <input type="hidden" name="mode" value="plan">
            <input type="hidden" name="sample_data" value="1">
            <button class="vd-btn vd-btn-primary">Try again on sample data</button>
          </form>
        @endunless
      @endif
    </div>
  @endif

  <div class="vd-split">
    <div class="vd-stack">
      @if ($video->hasRecording())
        @php($recorded = array_values(array_filter($video->recordedDevices(), fn ($d) => isset($devices[$d]))))
        @php($first = $video->primaryDevice())
        <video class="vd-video" id="vd-preview" controls preload="metadata"
          style="aspect-ratio:{{ $devices[$first]['width'] }}/{{ $devices[$first]['height'] }};max-height:78vh;margin:0 auto"
          poster="{{ route('video-demo.studio.file', [$video, $first, 'poster']) }}?v={{ $video->version }}"
          src="{{ route('video-demo.studio.file', [$video, $first, 'video']) }}?v={{ $video->version }}"></video>
        @if (count($recorded) > 1)
          <div class="vd-segments" role="group" aria-label="Version">
            @foreach ($recorded as $key)
              <button type="button" class="vd-segment" aria-pressed="{{ $key === $first ? 'true' : 'false' }}"
                data-src="{{ route('video-demo.studio.file', [$video, $key, 'video']) }}?v={{ $video->version }}"
                data-poster="{{ route('video-demo.studio.file', [$video, $key, 'poster']) }}?v={{ $video->version }}"
                data-ratio="{{ $devices[$key]['width'] }}/{{ $devices[$key]['height'] }}">{{ $devices[$key]['label'] }} · {{ gmdate('i:s', (int) round($video->recordings[$key]['seconds'] ?? 0)) }}</button>
            @endforeach
          </div>
        @endif
      @else
        <div class="vd-card vd-empty">
          @if ($video->status->isWorking())
            <strong>Making the video</strong>
            This page updates as it goes.
          @else
            <strong>No recording yet</strong>
          @endif
        </div>
      @endif

      @if ($video->error)
        <div class="vd-card vd-stack-sm" style="border-color:transparent;background:var(--vd-bad-bg)">
          <h2 style="color:var(--vd-bad);margin:0">What went wrong</h2>
          <p style="margin:0;color:var(--vd-bad);overflow-wrap:anywhere">{{ $video->error }}</p>
          @if ($video->status === \Ogunsakin01\VideoDemo\Enums\VideoStatus::Failed)
            <p class="vd-small" style="margin:0;color:var(--vd-bad)">Trying again uses the same description, style, versions and model. To change any of them, use "Make it again" below.</p>
            @php($hasPlan = ! empty($video->plan['steps']))
        <div class="vd-actions">
          <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
            <input type="hidden" name="mode" value="plan">
            <button class="vd-btn vd-btn-primary">{{ $hasPlan ? 'Try again with a new plan' : 'Try again' }}</button>
          </form>
          @if ($hasPlan)
            <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
              <input type="hidden" name="mode" value="record">
              <button class="vd-btn">Record the same plan again</button>
            </form>
          @endif
        </div>
          @endif
        </div>
      @endif

      @if (! empty($video->plan['steps']))
        <details class="vd-card">
          <summary>Plan · {{ count($video->plan['steps']) }} steps</summary>
          @if (! empty($video->plan['summary']))<p class="vd-small vd-muted" style="margin:10px 0 0">{{ $video->plan['summary'] }}</p>@endif
          <ol class="vd-steps">
            @foreach ($video->plan['steps'] as $step)
              <li>
                <code>{{ $step['action'] }}</code>
                <span>
                  @if (in_array($step['action'], ['caption', 'card']))
                    “{{ $step['title'] }}”
                  @elseif ($step['action'] === 'goto')
                    <span class="vd-mono">{{ $step['url'] }}</span>
                  @else
                    {{ collect($step['target'] ?? [])->filter()->map(fn ($v, $k) => "$k: $v")->implode(', ') }}
                    @if (! empty($step['value'])) → “{{ $step['value'] }}” @endif
                  @endif
                  @if (! empty($step['why']))<span class="vd-muted"> · {{ $step['why'] }}</span>@endif
                </span>
              </li>
            @endforeach
          </ol>
        </details>
      @endif

      <form method="post" action="{{ route('video-demo.studio.update', $video) }}" class="vd-card vd-stack">
        @csrf @method('patch')
        <h2 style="margin:0">Details</h2>
        <div class="vd-row">
          <div>
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required maxlength="160" value="{{ old('title', $video->title) }}">
          </div>
          <div>
            <label for="visibility">Library</label>
            <select id="visibility" name="visibility">
              <option value="public" @selected($video->visibility === 'public')>Public</option>
              <option value="private" @selected($video->visibility === 'private')>Private (staff)</option>
            </select>
          </div>
        </div>
        <div><button class="vd-btn">Save details</button></div>
      </form>

      @unless ($video->status->isWorking())
      <div class="vd-card vd-stack">
        <h2 style="margin:0">Make it again</h2>
        @if (! empty($video->plan['steps']))
          <form method="post" action="{{ route('video-demo.studio.rerecord', $video) }}" class="vd-stack-sm">@csrf
            <span class="vd-label" id="vd-rerecord-devices">Versions</span>
            @include('video-demo::studio.partials.devices', ['devices' => $devices, 'selected' => $video->deviceList(), 'labelledBy' => 'vd-rerecord-devices'])
            <div>
              <label for="rr-speed">Playback speed</label>
              <select name="speed" id="rr-speed">
                @foreach (['1' => '1× real time', '1.25' => '1.25×', '1.5' => '1.5×', '2' => '2×', '3' => '3×'] as $value => $label)
                  <option value="{{ $value }}" @selected(abs($video->playbackSpeed() - $value) < 0.01)>{{ $label }}</option>
                @endforeach
              </select>
            </div>
            <button class="vd-btn vd-btn-block">Record the same plan again</button>
            <span class="vd-hint">Free. Use it after the app's look changes, to add a version, or to change the speed.</span>
          </form>
          <hr class="vd-divider" style="margin:0">
        @endif
        <form method="post" action="{{ route('video-demo.studio.regenerate', $video) }}" class="vd-stack">@csrf
          <div>
            <label for="r-prompt">Description</label>
            <textarea id="r-prompt" name="prompt" required maxlength="4000">{{ $video->prompt }}</textarea>
          </div>
          <div>
            <label for="r-style">Style</label>
            @php($currentStyle = $styles->first(fn ($s) => $s->key === ($video->style['key'] ?? null)) ?? $styles->first(fn ($s) => $s->name === ($video->style['name'] ?? null)))
            <select id="r-style" name="style_id">
              @unless ($currentStyle)
                <option value="" selected>{{ $video->style['name'] ?? 'Its current style' }} (as it was)</option>
              @endunless
              @foreach ($styles as $style)
                <option value="{{ $style->id }}" data-direction="{{ $style->direction }}" @selected($currentStyle?->is($style))>{{ $style->name }}</option>
              @endforeach
            </select>
            <span class="vd-hint" id="r-style-hint">{{ $currentStyle?->direction }}</span>
          </div>
          <div class="vd-row">
            <div>
              <label for="r-seconds">Seconds</label>
              <input type="number" id="r-seconds" name="target_seconds" min="10" max="{{ config('video-demo.limits.max_seconds', 300) }}" value="{{ $video->target_seconds }}">
            </div>
            <div>
              <label for="r-effort">Effort</label>
              <select id="r-effort" name="effort">
                @foreach ($efforts as $effort)
                  <option value="{{ $effort }}" @selected($video->effort === $effort)>{{ ucfirst($effort) }}</option>
                @endforeach
              </select>
            </div>
          </div>
          <div class="vd-row">
            <div>
              <label for="r-max-cost">Spending limit ($)</label>
              <input type="number" id="r-max-cost" name="max_cost" step="0.01" min="0.01" max="100" value="{{ $video->spendLimit() }}">
            </div>
            <div>
              <label for="r-max-steps">Max steps</label>
              <input type="number" id="r-max-steps" name="max_steps" min="3" max="250" value="{{ $video->max_steps }}" placeholder="No limit">
            </div>
          </div>
          <div>
            <label for="r-speed">Playback speed</label>
            <select name="speed" id="r-speed">
                @foreach (['1' => '1× real time', '1.25' => '1.25×', '1.5' => '1.5×', '2' => '2×', '3' => '3×'] as $value => $label)
                  <option value="{{ $value }}" @selected(abs($video->playbackSpeed() - $value) < 0.01)>{{ $label }}</option>
                @endforeach
              </select>
          </div>
          <div class="vd-stack-sm">
            <input type="hidden" name="sample_data" value="0">
            <label class="vd-check-row"><input type="checkbox" name="sample_data" value="1" @checked($video->sample_data)> Record on sample data</label>
            <span class="vd-hint">A copy of your database where the AI adds what the pages need, instead of your own data.</span>
          </div>
          <button class="vd-btn vd-btn-block">Ask the AI for a new plan</button>
        </form>
      </div>
      @endunless
    </div>

    <aside class="vd-stack">
      <div class="vd-card">
        <p class="vd-eyebrow">AI cost</p>
        @if (\Ogunsakin01\VideoDemo\Ai\Pricing::known($video->model))
          <div class="vd-figure" id="vd-cost">${{ number_format($video->cost, 4) }}</div>
        @else
          <div class="vd-figure">Unknown</div>
          <p class="vd-small vd-muted" style="margin:4px 0 0">No prices for this model in the config.</p>
        @endif
        <dl style="margin:12px 0 0">
          @if ($video->estimate_low !== null)
            <div class="vd-stat"><dt>Estimate</dt><dd class="vd-num">${{ number_format($video->estimate_low, 2) }} – ${{ number_format($video->estimate_high, 2) }}</dd></div>
          @endif
          @if (! empty($video->style['name']))
            <div class="vd-stat"><dt>Style</dt><dd>{{ $video->style['name'] }}</dd></div>
          @endif
          <div class="vd-stat"><dt>Versions</dt><dd>{{ collect($video->deviceList())->map(fn ($d) => $devices[$d]['label'] ?? $d)->implode(', ') }}</dd></div>
          <div class="vd-stat"><dt>Speed</dt><dd class="vd-num">{{ rtrim(rtrim(number_format($video->playbackSpeed(), 2), '0'), '.') }}×</dd></div>
          <div class="vd-stat"><dt>Spending limit</dt><dd class="vd-num">${{ number_format($video->spendLimit(), 2) }}</dd></div>
          @if ($video->max_steps)
            <div class="vd-stat"><dt>Max steps</dt><dd class="vd-num">{{ $video->max_steps }}</dd></div>
          @endif
          <div class="vd-stat"><dt>Recorded as</dt><dd>{{ $video->record_as_label ?: \Ogunsakin01\VideoDemo\Recording\RecordAs::label($video->record_as) }}</dd></div>
          <div class="vd-stat"><dt>Data</dt><dd>{{ $video->sample_data ? 'Sample data' : 'Your local database' }}</dd></div>
          <div class="vd-stat"><dt>Model</dt><dd>{{ \Ogunsakin01\VideoDemo\Ai\Pricing::model($video->model)['label'] ?? $video->model }}, {{ $video->effort }}</dd></div>
          <div class="vd-stat"><dt>Length</dt><dd class="vd-num">{{ $video->duration_seconds ? round($video->duration_seconds).'s of '.$video->target_seconds.'s target' : $video->target_seconds.'s target' }}</dd></div>
        </dl>
      </div>

      @if ($video->isPublished() && ($entry = app(\Ogunsakin01\VideoDemo\Library\Manifest::class)->find($video->slug, 'private')))
        @include('video-demo::partials.embed', ['entry' => $entry])
      @elseif ($video->status === \Ogunsakin01\VideoDemo\Enums\VideoStatus::Ready)
        <div class="vd-card">
          <h2>Embed</h2>
          <p class="vd-small vd-muted" style="margin:0">Publish the video to get code that shows it on any page.</p>
        </div>
      @endif

      <div class="vd-card">
        <h2>Progress</h2>
        <ul class="vd-log" id="vd-log">
          @foreach (array_slice($video->log ?? [], -40) as $entry)
            <li><time datetime="{{ $entry['at'] }}">{{ \Illuminate\Support\Carbon::parse($entry['at'])->format('H:i:s') }}</time><span>{{ $entry['message'] }}</span></li>
          @endforeach
        </ul>
      </div>

      @unless ($video->status->isWorking())
        <form method="post" action="{{ route('video-demo.studio.destroy', $video) }}" onsubmit="return confirm('Delete this video and its recording?')">@csrf @method('delete')
          <button class="vd-btn vd-btn-ghost vd-btn-danger vd-btn-block">Delete video</button>
        </form>
      @endunless
    </aside>
  </div>

  <script>
    // Log times in the viewer's own time zone, as the live updates show them.
    document.querySelectorAll('#vd-log time[datetime]').forEach(t => { t.textContent = new Date(t.dateTime).toTimeString().slice(0, 8); });
    document.querySelectorAll('[data-src]').forEach(b => b.addEventListener('click', () => {
      const v = document.getElementById('vd-preview');
      v.poster = b.dataset.poster; v.src = b.dataset.src; v.style.aspectRatio = b.dataset.ratio;
      document.querySelectorAll('[data-src]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
    }));
    // Show the chosen style's direction under the style picker in "Make it again".
    const styleSelect = document.getElementById('r-style');
    styleSelect?.addEventListener('change', () => {
      document.getElementById('r-style-hint').textContent = styleSelect.selectedOptions[0]?.dataset.direction || '';
    });
  </script>

  @if ($video->status->isWorking())
    <x-slot:scripts>
      <script>
        (() => {
          const url = @json(route('video-demo.studio.status', $video));
          const log = document.getElementById('vd-log');
          const status = document.querySelector('#vd-status [data-status]');
          const cost = document.getElementById('vd-cost');
          const time = iso => new Date(iso).toTimeString().slice(0, 8);
          const tick = async () => {
            try {
              const s = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
              status.textContent = s.label;
              const stop = document.querySelector('#vd-stop button');
              if (stop && s.stopping) { stop.disabled = true; stop.textContent = 'Stopping…'; }
              cost.textContent = '$' + Number(s.cost).toFixed(4);
              log.replaceChildren(...s.log.map(e => {
                const li = document.createElement('li');
                const t = document.createElement('time'); t.textContent = time(e.at);
                const m = document.createElement('span'); m.textContent = e.message;
                li.append(t, m);
                return li;
              }));
              log.scrollTop = log.scrollHeight;
              if (!s.working) { location.reload(); return; }
            } catch {}
            setTimeout(tick, 2000);
          };
          setTimeout(tick, 1500);
        })();
      </script>
    </x-slot:scripts>
  @endif
</x-video-demo::layout>
