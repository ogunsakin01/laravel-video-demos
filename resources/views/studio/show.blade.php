<x-video-demo::layout :title="$video->title ?: 'Video'" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    <a class="vd-btn" href="{{ route('video-demo.studio.index') }}">All videos</a>
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
        <div class="vd-card" style="border-color:transparent;background:var(--vd-bad-bg)">
          <h2 style="color:var(--vd-bad);margin-bottom:6px">What went wrong</h2>
          <p style="margin:0;color:var(--vd-bad)">{{ $video->error }}</p>
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
    </div>

    <aside class="vd-stack">
      <div class="vd-card">
        <p class="vd-eyebrow">Claude cost</p>
        <div class="vd-figure" id="vd-cost">${{ number_format($video->cost, 4) }}</div>
        <dl style="margin:12px 0 0">
          @if ($video->estimate_low !== null)
            <div class="vd-stat"><dt>Estimate</dt><dd class="vd-num">${{ number_format($video->estimate_low, 2) }} – ${{ number_format($video->estimate_high, 2) }}</dd></div>
          @endif
          @if (! empty($video->style['name']))
            <div class="vd-stat"><dt>Style</dt><dd>{{ $video->style['name'] }}</dd></div>
          @endif
          <div class="vd-stat"><dt>Versions</dt><dd>{{ collect($video->deviceList())->map(fn ($d) => $devices[$d]['label'] ?? $d)->implode(', ') }}</dd></div>
          <div class="vd-stat"><dt>Model</dt><dd>{{ config("video-demo.ai.models.{$video->model}.label", $video->model) }}, {{ $video->effort }}</dd></div>
          <div class="vd-stat"><dt>Length</dt><dd class="vd-num">{{ $video->duration_seconds ? round($video->duration_seconds).'s of '.$video->target_seconds.'s target' : $video->target_seconds.'s target' }}</dd></div>
        </dl>
      </div>

      <div class="vd-card">
        <h2>Progress</h2>
        <ul class="vd-log" id="vd-log">
          @foreach (array_slice($video->log ?? [], -40) as $entry)
            <li><time>{{ \Illuminate\Support\Carbon::parse($entry['at'])->format('H:i:s') }}</time><span>{{ $entry['message'] }}</span></li>
          @endforeach
        </ul>
      </div>

      @unless ($video->status->isWorking())
        <div class="vd-card vd-stack">
          <h2 style="margin:0">Make it again</h2>
          @if (! empty($video->plan['steps']))
            <form method="post" action="{{ route('video-demo.studio.rerecord', $video) }}" class="vd-stack-sm">@csrf
              <span class="vd-label" id="vd-rerecord-devices">Versions</span>
              @include('video-demo::studio.partials.devices', ['devices' => $devices, 'selected' => $video->deviceList(), 'labelledBy' => 'vd-rerecord-devices'])
              <button class="vd-btn vd-btn-block">Record the same plan again</button>
              <span class="vd-hint">Free. Use it after the app's look changes, or to add a version.</span>
            </form>
            <hr class="vd-divider" style="margin:0">
          @endif
          <form method="post" action="{{ route('video-demo.studio.regenerate', $video) }}" class="vd-stack">@csrf
            <div>
              <label for="r-prompt">Description</label>
              <textarea id="r-prompt" name="prompt" required maxlength="4000">{{ $video->prompt }}</textarea>
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
            <button class="vd-btn vd-btn-block">Ask Claude for a new plan</button>
          </form>
        </div>

        <form method="post" action="{{ route('video-demo.studio.destroy', $video) }}" onsubmit="return confirm('Delete this video and its recording?')">@csrf @method('delete')
          <button class="vd-btn vd-btn-ghost vd-btn-danger vd-btn-block">Delete video</button>
        </form>
      @endunless
    </aside>
  </div>

  <script>
    document.querySelectorAll('[data-src]').forEach(b => b.addEventListener('click', () => {
      const v = document.getElementById('vd-preview');
      v.poster = b.dataset.poster; v.src = b.dataset.src; v.style.aspectRatio = b.dataset.ratio;
      document.querySelectorAll('[data-src]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
    }));
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
