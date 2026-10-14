<x-video-demo::layout title="Studio" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    <a class="vd-btn vd-btn-ghost" href="{{ route('video-demo.studio.styles.index') }}">Styles</a>
    @if (config('video-demo.library.public.enabled'))
      <a class="vd-btn" href="{{ route('video-demo.public.index') }}">View library</a>
    @endif
    <a class="vd-btn vd-btn-primary" href="{{ route('video-demo.studio.create') }}">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      New video
    </a>
  </x-slot:nav>

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>Videos</h1>
      <p class="vd-sub">Describe a flow in your app and AI records a walkthrough of it. Publish the ones you like to the library.</p>
    </div>
    <dl style="margin:0;min-width:180px">
      <dt class="vd-eyebrow">Spent on AI</dt>
      <dd class="vd-figure" style="margin:0">${{ number_format($totalCost, 2) }}</dd>
    </dl>
  </section>

  <div class="vd-split">
    <div class="vd-card vd-card-flush vd-scroll">
      @if ($videos->isEmpty())
        <div class="vd-empty">
          <strong>No videos yet</strong>
          <p style="margin:0 0 16px">Describe a flow and the AI will plan and record it.</p>
          <a class="vd-btn vd-btn-primary" href="{{ route('video-demo.studio.create') }}">Make the first one</a>
        </div>
      @else
        <table class="vd-table">
          <thead><tr><th>Video</th><th>Status</th><th>Length</th><th>Cost</th><th>Library</th></tr></thead>
          <tbody>
            @foreach ($videos as $video)
              <tr>
                <td>
                  <a href="{{ route('video-demo.studio.show', $video) }}">{{ $video->title ?: \Illuminate\Support\Str::limit($video->prompt, 60) }}</a>
                  <div class="vd-small vd-muted">{{ $video->updated_at->diffForHumans() }}</div>
                </td>
                <td>@include('video-demo::studio.partials.status', ['status' => $video->status])</td>
                <td class="vd-num vd-mono vd-small">{{ $video->duration_seconds ? gmdate('i:s', (int) round($video->duration_seconds)) : '—' }}</td>
                <td class="vd-num vd-mono vd-small">${{ number_format($video->cost, 2) }}</td>
                <td>
                  @if ($video->isPublished())
                    <span class="vd-pill">{{ $video->visibility === 'public' ? 'Public' : 'Private' }}</span>
                  @else
                    <span class="vd-small vd-muted">Not published</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>

    <aside class="vd-card">
      <h2>Requested by users</h2>
      <p class="vd-small vd-muted" id="vd-requests-note" style="margin:0 0 8px">Loading…</p>
      <ul class="vd-log" id="vd-requests"></ul>
    </aside>
  </div>

  <x-slot:scripts>
    <script>
      (async () => {
        const list = document.getElementById('vd-requests');
        const note = document.getElementById('vd-requests-note');
        const create = @json(route('video-demo.studio.create'));
        try {
          const res = await fetch(@json(route('video-demo.studio.requests')), { headers: { Accept: 'application/json' } });
          const data = await res.json();
          if (data.error) { note.textContent = data.error; return; }
          const from = data.source === 'local' ? 'this app' : data.source;
          note.textContent = data.requests.length ? `Searches in ${from} that no video answered. Pick one to start a video.` : `No open requests from ${from}.`;
          for (const r of data.requests) {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.href = create + '?prompt=' + encodeURIComponent(r.question);
            a.textContent = r.question;
            li.appendChild(a);
            list.appendChild(li);
          }
        } catch {
          note.textContent = 'Could not load requests.';
        }
      })();
    </script>
  </x-slot:scripts>
</x-video-demo::layout>
