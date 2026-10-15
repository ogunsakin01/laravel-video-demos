<x-video-demo::layout title="Studio" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => 'index'])
  </x-slot:nav>

  <section class="vd-hero">
    @include('video-demo::partials.art', ['name' => 'clapper'])
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>Videos</h1>
      <p class="vd-sub">Describe a flow; the AI records it. Publish the ones you like.</p>
    </div>
    <dl style="margin:0;min-width:180px">
      <dt class="vd-eyebrow">Spent on AI</dt>
      <dd class="vd-figure" style="margin:0" id="vd-total" aria-live="polite">${{ number_format($totalCost, 2) }}</dd>
      <dd class="vd-small vd-muted" style="margin:2px 0 0">Every AI request, including Complete with AI and ideas</dd>
    </dl>
  </section>

  <div class="vd-split">
    <div class="vd-card vd-card-flush vd-scroll">
      @if ($videos->isEmpty())
        <div class="vd-empty">
          @include('video-demo::partials.art', ['name' => 'clapper', 'class' => 'vd-empty-art'])
          <strong>No videos yet</strong>
          <p style="margin:0 0 16px">Describe a flow and the AI will plan and record it.</p>
          <div class="vd-actions" style="justify-content:center">
            <a class="vd-btn vd-btn-primary" href="{{ route('video-demo.studio.create') }}">Make the first one</a>
            <a class="vd-btn" href="{{ route('video-demo.studio.suggestions.index') }}">Suggest videos to make</a>
            @if (\Ogunsakin01\VideoDemo\Studio\Examples::enabled())
              <a class="vd-btn" href="{{ route('video-demo.studio.examples') }}">See examples</a>
            @endif
          </div>
        </div>
      @else
        <form method="post" action="{{ route('video-demo.studio.bulk') }}" id="vd-bulk-form">
        @csrf
        <div class="vd-bulk" id="vd-bulk" hidden>
          <span class="vd-small" id="vd-bulk-count" aria-live="polite" style="flex:1;min-width:0">0 selected</span>
          <button class="vd-btn vd-btn-sm" name="action" value="unpublish">Unpublish</button>
          <button class="vd-btn vd-btn-sm vd-btn-primary" name="action" value="publish">Publish</button>
          {{-- Opens a page that says where the videos are used before anything is deleted. --}}
          <button class="vd-btn vd-btn-sm vd-btn-ghost vd-btn-danger" formaction="{{ route('video-demo.studio.delete') }}" formmethod="get" id="vd-bulk-delete">Delete…</button>
        </div>
        <table class="vd-table">
          <thead><tr>
            <th class="vd-check"><input type="checkbox" id="vd-select-all" aria-label="Select all videos"></th>
            <th>Video</th><th>Status</th><th>Length</th><th>Cost</th><th>Library</th>
          </tr></thead>
          <tbody>
            @foreach ($videos as $video)
              <tr data-video="{{ $video->id }}" data-working="{{ $video->status->isWorking() ? 1 : 0 }}">
                <td class="vd-check"><input type="checkbox" name="videos[]" value="{{ $video->id }}" data-row
                  aria-label="Select {{ $video->title ?: \Illuminate\Support\Str::limit($video->prompt, 60) }}"
                  data-ready="{{ $video->status === \Ogunsakin01\VideoDemo\Enums\VideoStatus::Ready ? 1 : 0 }}" data-published="{{ $video->isPublished() ? 1 : 0 }}"></td>
                <td>
                  <a href="{{ route('video-demo.studio.show', $video) }}">{{ $video->title ?: \Illuminate\Support\Str::limit($video->prompt, 60) }}</a>
                  <div class="vd-small vd-muted">{{ $video->updated_at->diffForHumans() }}</div>
                </td>
                <td>@include('video-demo::studio.partials.status', ['status' => $video->status])</td>
                <td class="vd-num vd-mono vd-small" data-length>{{ $video->duration_seconds ? gmdate('i:s', (int) round($video->duration_seconds)) : '—' }}</td>
                <td class="vd-num vd-mono vd-small" data-cost>${{ number_format($video->cost, 2) }}</td>
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
        </form>
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
      // Bulk publish: tick videos, then press Publish or Unpublish.
      (() => {
        const form = document.getElementById('vd-bulk-form');
        if (!form) return;
        const all = document.getElementById('vd-select-all');
        const rows = [...form.querySelectorAll('[data-row]')];
        const bar = document.getElementById('vd-bulk');
        const count = document.getElementById('vd-bulk-count');
        const [unpublish, publish] = bar.querySelectorAll('button');
        // Open the delete page with only the ticked videos in the address, not the form's token.
        document.getElementById('vd-bulk-delete').addEventListener('click', e => {
          e.preventDefault();
          const ids = rows.filter(r => r.checked).map(r => 'videos[]=' + encodeURIComponent(r.value));
          location.href = e.currentTarget.formAction + '?' + ids.join('&');
        });
        const sync = () => {
          const ticked = rows.filter(r => r.checked);
          const ready = ticked.filter(r => r.dataset.ready === '1').length;
          const live = ticked.filter(r => r.dataset.published === '1').length;
          bar.hidden = ticked.length === 0;
          count.textContent = ticked.length + ' selected' + (ticked.length && ready < ticked.length ? ` · ${ready} ready to publish` : '');
          publish.disabled = ready === 0;
          unpublish.disabled = live === 0;
          all.checked = ticked.length === rows.length;
          all.indeterminate = ticked.length > 0 && ticked.length < rows.length;
        };
        all.addEventListener('change', () => { rows.forEach(r => { r.checked = all.checked; }); sync(); });
        rows.forEach(r => r.addEventListener('change', sync));
        form.addEventListener('submit', e => {
          const b = e.submitter;
          const keep = document.createElement('input');
          keep.type = 'hidden'; keep.name = b.name; keep.value = b.value;
          form.append(keep);
          bar.querySelectorAll('button').forEach(x => { x.disabled = true; });
          b.setAttribute('aria-busy', 'true');
          b.textContent = b.value === 'publish' ? 'Publishing…' : 'Unpublishing…';
        });
        sync();
      })();

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

  <script>
    // Keep the total spent, and videos being made, up to date without reloading.
    (() => {
      const total = document.getElementById('vd-total');
      const rows = [...document.querySelectorAll('tr[data-video]')];
      const ids = rows.map(r => r.dataset.video).join(',');
      const money = n => '$' + Number(n).toFixed(2);
      async function refresh() {
        let working = false;
        try {
          const res = await fetch(@json(route('video-demo.studio.spending')) + '?' + new URLSearchParams({ videos: ids }), { headers: { Accept: 'application/json' } });
          const d = await res.json();
          total.textContent = money(d.total);
          rows.forEach(row => {
            const v = d.videos[row.dataset.video];
            if (!v) return;
            row.querySelector('[data-cost]').textContent = money(v.cost);
            if (v.length) row.querySelector('[data-length]').textContent = v.length;
            const status = row.querySelector('[data-status]');
            status.textContent = v.label;
            status.className = 'vd-status vd-status-' + v.class;
            row.dataset.working = v.working ? 1 : 0;
            working = working || v.working;
          });
        } catch (e) {}
        // Every few seconds while videos are being made; otherwise now and then, for AI used elsewhere.
        setTimeout(refresh, working ? 4000 : 15000);
      }
      setTimeout(refresh, rows.some(r => r.dataset.working === '1') ? 4000 : 15000);
    })();
  </script>
</x-video-demo::layout>
