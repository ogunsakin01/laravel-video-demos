{{--
  "Watching": how a published video is watched, read from production when it
  is set up: plays, how far viewers get, and where most of them stop.
  Expects $video. Filled in by the script below, so the page never waits.
--}}
<section class="vd-card vd-stack-sm" id="vd-watching" data-url="{{ route('video-demo.studio.video-stats', $video) }}">
  <div>
    <h2 style="margin:0">Watching</h2>
    <p class="vd-small vd-muted" style="margin:4px 0 0" data-note>Loading…</p>
  </div>
  <div class="vd-watch" data-bars hidden>
    @foreach (['play' => 'Started', '25' => 'A quarter', '50' => 'Half', '75' => 'Three quarters', '100' => 'To the end'] as $event => $label)
      <div class="vd-watch-row" data-event="{{ $event }}">
        <span class="vd-small">{{ $label }}</span>
        <span class="vd-watch-track"><span></span></span>
        <span class="vd-small vd-num vd-mono" data-count></span>
      </div>
    @endforeach
  </div>
  <p class="vd-small" style="margin:0" data-drop hidden></p>
</section>

@once
  <script>
    document.addEventListener('DOMContentLoaded', async () => {
      const card = document.getElementById('vd-watching');
      if (!card) return;
      const note = card.querySelector('[data-note]');
      try {
        const s = await (await fetch(card.dataset.url, { headers: { Accept: 'application/json' } })).json();
        const from = s.source === 'local' ? 'this app' : s.source;
        if (s.error) { note.textContent = s.error; return; }
        if (!s.counts || !s.counts.play) {
          note.textContent = s.enabled
            ? `No plays counted in ${from} in the last ${s.days} days.`
            : `Counting is off in ${from}. Set VIDEO_DEMO_STATS=true there to see how this video is watched.`;
          return;
        }
        const plays = s.counts.play;
        note.textContent = `${plays} ${plays === 1 ? 'play' : 'plays'} in ${from} in the last ${s.days} days; ${Math.round(100 * (s.counts['100'] || 0) / plays)}% watched to the end.`;
        card.querySelectorAll('[data-event]').forEach(row => {
          const n = s.counts[row.dataset.event] || 0;
          row.querySelector('.vd-watch-track span').style.width = Math.round(100 * n / plays) + '%';
          row.querySelector('[data-count]').textContent = n;
        });
        card.querySelector('[data-bars]').hidden = false;
        if (s.drop_off) {
          const drop = card.querySelector('[data-drop]');
          drop.textContent = s.drop_off;
          drop.hidden = false;
        }
      } catch {
        note.textContent = 'The counts could not be loaded.';
      }
    });
  </script>
@endonce
