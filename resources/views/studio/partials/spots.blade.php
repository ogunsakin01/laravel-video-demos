{{--
  "Where to put it": spots in the app where the video would help. Each shows
  the video in place (minimised and open, on a desktop and a phone screen),
  whether it fits without upsetting the page, and how to add it: the file and
  line, the code around it with the tag pasted in, and publishing. Expects $video.
--}}
@php
  use Ogunsakin01\VideoDemo\Jobs\FindSpots;
  $spots = $video->spots ?? [];
  $state = $spots['status'] ?? null;
  $items = $spots['items'] ?? [];
  $tag = FindSpots::tag($video);
  $who = $video->record_as_label ?: \Ogunsakin01\VideoDemo\Recording\RecordAs::label($video->record_as);
  $hasKey = \Ogunsakin01\VideoDemo\Ai\Drivers\Drivers::anyKey();
  $model = \Ogunsakin01\VideoDemo\Ai\Pricing::defaultModel();
  $estimate = \Ogunsakin01\VideoDemo\Ai\Placer::estimate($model);
  $appUrl = rtrim((string) (config('video-demo.recorder.base_url') ?: config('app.url')), '/');
  $shot = fn (string $file) => route('video-demo.studio.spot-shot', [$video, $file]).'?v='.urlencode($spots['at'] ?? '');
  $where = fn (string $position) => match ($position) { 'before' => 'just above the section', 'after' => 'just below the section', default => 'at the top of the section, inside it' };
  $screenName = fn (string $s) => $s === 'mobile' ? 'phone' : strtolower((string) config("video-demo.devices.$s.label", $s));
@endphp
<section class="vd-card vd-stack" id="vd-spots" @if ($state === 'working') data-spots-working @endif>
  <div class="vd-spots-head">
    <div>
      <h2 style="margin:0">Where to put it</h2>
      <p class="vd-small vd-muted" style="margin:4px 0 0">Places in your app where people doing this task would find the video, checked to fit without upsetting the page.</p>
    </div>
    @if ($items !== [] && $state !== 'working')
      <form method="post" action="{{ route('video-demo.studio.spots', $video) }}" data-busy-form>@csrf
        <button class="vd-btn vd-btn-sm">Suggest again</button>
      </form>
    @endif
  </div>

  {{-- Before the first suggestion: what it does, and what it costs. --}}
  @if ($items === [] && $state !== 'working')
    <ol class="vd-spots-intro">
      <li><span>1</span><div><strong>Opens your pages</strong> the video visits, as {{ \Illuminate\Support\Str::lcfirst($who) }}{{ $video->sample_data ? ', on its sample data' : '' }}.</div></li>
      <li><span>2</span><div><strong>Picks two or three spots</strong> where people doing this task would look: the form it teaches, an empty state, a help panel.</div></li>
      <li><span>3</span><div><strong>Checks the video fits</strong>, minimised and open, on a desktop and a phone, and shows you each one.</div></li>
    </ol>
  @endif

  @if ($state === 'working' && ($progress = FindSpots::progress($video)))
    @php
      $stages = array_keys(array_filter(FindSpots::STAGES, fn ($label, $key) => $key !== 'data' || $video->sample_data, ARRAY_FILTER_USE_BOTH));
      $current = array_search($spots['stage'] ?? null, $stages, true);
    @endphp
    <div class="vd-progress" id="vd-spots-progress" style="margin-top:0;max-width:none" data-percent="{{ $progress['percent'] }}" data-remaining="{{ $progress['remaining'] }}">
      <div class="vd-progress-track" role="progressbar" aria-label="Finding spots" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}"><span style="width:{{ $progress['percent'] }}%"></span></div>
      <span class="vd-small vd-muted"><strong data-percent>{{ $progress['percent'] }}%</strong> · <span data-stage>{{ $progress['stage'] }}</span> · <span data-left></span></span>
    </div>
    <ol class="vd-stages" data-stages>
      @foreach ($stages as $n => $key)
        <li data-stage-key="{{ $key }}" class="{{ $current === false ? '' : ($n < $current ? 'is-done' : ($n === $current ? 'is-now' : '')) }}">{{ FindSpots::STAGES[$key] }}</li>
      @endforeach
    </ol>
  @elseif ($state === 'failed')
    <div class="vd-flash vd-flash-error" role="alert" style="margin:0">{{ $spots['error'] ?? 'It did not finish.' }}</div>
  @endif

  @if (! empty($spots['note']) && $state === 'ready')
    <p class="vd-small vd-muted" style="margin:0">{{ $spots['note'] }}</p>
  @endif

  @foreach ($items as $i => $spot)
    @php
      // Spots found before the layout check have one picture per screen and no check.
      $shots = collect($spot['shots'])->map(fn ($s) => is_array($s) ? $s : ['closed' => $s])->all();
      $states = array_keys(reset($shots) ?: []);
      $checked = array_key_exists('fits', $spot);
      $problems = collect($spot['problems'] ?? []);
      $code = $spot['code'] ?? null;
      $exact = is_array($code) && ! empty($code['exact']);
    @endphp
    <article class="vd-spot" data-spot="{{ $i }}">
      <header class="vd-spot-head">
        <span class="vd-spot-num">{{ $i + 1 }}</span>
        <div style="min-width:0;flex:1">
          <h3>{{ $spot['label'] }}</h3>
          <div class="vd-spot-meta">
            <a class="vd-pill vd-mono" href="{{ $appUrl.$spot['path'] }}" target="_blank" rel="noopener">{{ $spot['path'] }}</a>
            @if ($checked && $spot['fits'])
              <span class="vd-pill vd-pill-ok">Fits on {{ collect(array_keys($shots))->map($screenName)->implode(' and ') }}</span>
            @elseif ($checked)
              <span class="vd-pill vd-pill-warn">May upset the layout</span>
            @endif
          </div>
          @if ($spot['why'])<p class="vd-small vd-muted" style="margin:6px 0 0">{{ $spot['why'] }}</p>@endif
        </div>
      </header>

      @if ($problems->isNotEmpty())
        <div class="vd-flash vd-flash-warn" style="margin:0">
          <strong>What the check found</strong>
          <ul style="margin:6px 0 0;padding-left:18px">
            @foreach ($problems->groupBy('problem') as $problem => $seenOn)
              <li>{{ $problem }} <span class="vd-muted">({{ $seenOn->map(fn ($w) => $screenName($w['screen']).', '.($w['state'] === 'open' ? 'open' : 'minimised'))->unique()->implode('; ') }})</span></li>
            @endforeach
          </ul>
        </div>
      @endif

      {{-- The page with the video in place. --}}
      <div class="vd-spot-preview">
        <div class="vd-spot-tools">
          @if (count($shots) > 1)
            <div class="vd-segments" role="group" aria-label="Screen">
              @foreach (array_keys($shots) as $screen)
                <button type="button" class="vd-segment" data-spot-screen="{{ $screen }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ ucfirst($screenName($screen)) }}</button>
              @endforeach
            </div>
          @endif
          @if (count($states) > 1)
            <div class="vd-segments" role="group" aria-label="Video">
              <button type="button" class="vd-segment" data-spot-state="closed" aria-pressed="true">Minimised</button>
              <button type="button" class="vd-segment" data-spot-state="open" aria-pressed="false">Open</button>
            </div>
          @endif
        </div>
        @foreach ($shots as $screen => $byState)
          @foreach ($byState as $st => $file)
            <figure class="vd-frame vd-frame-{{ $screen }}" data-spot-img="{{ $screen }}-{{ $st }}" @unless ($loop->parent->first && $loop->first) hidden @endunless>
              <div class="vd-frame-bar" aria-hidden="true"><i></i><i></i><i></i><span>{{ $spot['path'] }}</span></div>
              <a href="{{ $shot($file) }}" target="_blank" rel="noopener">
                <img src="{{ $shot($file) }}" alt="{{ $spot['label'] }} on {{ $screenName($screen) }}, with the video {{ $st === 'open' ? 'open' : 'minimised' }}" loading="lazy">
              </a>
            </figure>
          @endforeach
        @endforeach
      </div>

      {{-- How to add it. --}}
      <ol class="vd-howto">
        <li>
          <div class="vd-howto-title">
            @if ($code)
              Open <span class="vd-mono">{{ $code['file'] }}</span> at line {{ $code['line'] }}
            @else
              Open the view for <span class="vd-mono">{{ $spot['path'] }}</span>{{ $spot['heading'] ? ' and find “'.$spot['heading'].'”' : '' }}
            @endif
          </div>
          @if ($code)
            <button type="button" class="vd-btn vd-btn-sm" data-copy="{{ $code['file'] }}:{{ $code['line'] }}">Copy path</button>
          @endif
        </li>
        <li>
          <div class="vd-howto-title">
            Paste the player {{ $exact ? 'on that line' : $where($spot['position']) }}{{ $exact ? ', '.$where($spot['position']) : '' }}
          </div>
          <button type="button" class="vd-btn vd-btn-sm" data-copy="{{ $tag }}">Copy tag</button>
          @if ($code && ! empty($code['excerpt']))
            <pre class="vd-diff" aria-label="The code with the tag pasted in">@foreach ($code['excerpt'] as $line)<span class="{{ $line['added'] ? 'is-added' : '' }}"><b>{{ $line['added'] ? '+' : $line['n'] }}</b>{{ $line['text'] }}</span>@endforeach</pre>
            @unless ($exact)
              <span class="vd-hint">The section's opening tag couldn't be told apart in this file, so this is just above its heading. Move it {{ $where($spot['position']) }} if that reads better.</span>
            @endunless
          @else
            <pre class="vd-code"><code>{{ $tag }}</code></pre>
            <span class="vd-hint">The heading isn't written in a view (it may come from the database or a translation), so find the section by eye.</span>
          @endif
          <span class="vd-hint">It shows as a slim “How-to video” bar people can open and minimise again; each viewer's choice is remembered.</span>
        </li>
        <li>
          @if ($video->isPublished())
            <div class="vd-howto-title">Published: reload <a href="{{ $appUrl.$spot['path'] }}" target="_blank" rel="noopener" class="vd-mono">{{ $spot['path'] }}</a> to see it</div>
          @else
            <div class="vd-howto-title">Publish the video, or the tag shows nothing</div>
            <form method="post" action="{{ route('video-demo.studio.publish', $video) }}">@csrf
              <button class="vd-btn vd-btn-sm vd-btn-primary">Publish</button>
            </form>
          @endif
        </li>
      </ol>
    </article>
  @endforeach

  @if ($items !== [] && in_array($state, ['ready', 'failed'], true))
    <p class="vd-small vd-muted" style="margin:0">
      Suggested {{ \Illuminate\Support\Carbon::parse($spots['at'])->diffForHumans() }}
      @if (isset($spots['cost']))
        · AI cost <span class="vd-num">${{ number_format($spots['cost'], 4) }}</span>, added to the video's
      @endif
    </p>
  @endif

  @if ($items === [] && $state !== 'working')
    <form method="post" action="{{ route('video-demo.studio.spots', $video) }}" data-busy-form class="vd-stack-sm">@csrf
      <div><button class="vd-btn vd-btn-primary">{{ $state === 'failed' ? 'Try again' : 'Suggest where to put it' }}</button></div>
      <span class="vd-hint" style="margin:0">
        @if (! $hasKey)
          With no AI key set, it suggests the top of the page the video starts on.
        @elseif ($video->cost >= $video->spendLimit())
          The video has reached its spending limit, so it suggests the top of the page the video starts on, without AI.
        @elseif (\Ogunsakin01\VideoDemo\Ai\Pricing::known($model))
          Takes a minute or two. The AI's part costs about ${{ number_format(max(0.001, $estimate), 3) }}, added to the video's cost.
        @else
          Takes a minute or two. The AI's cost is added to the video's.
        @endif
        @if ($state === 'failed' && isset($spots['cost']) && $spots['cost'] > 0)
          The last try cost ${{ number_format($spots['cost'], 4) }}.
        @endif
      </span>
    </form>
  @endif
</section>
@include('video-demo::partials.copy-script')

@once
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Each spot's picture: which screen, and the video minimised or open.
      document.querySelectorAll('[data-spot]').forEach(spot => {
        const pick = () => {
          const screen = spot.querySelector('[data-spot-screen][aria-pressed=true]')?.dataset.spotScreen;
          const state = spot.querySelector('[data-spot-state][aria-pressed=true]')?.dataset.spotState || 'closed';
          const figures = [...spot.querySelectorAll('[data-spot-img]')];
          const wanted = figures.find(f => f.dataset.spotImg === `${screen || figures[0].dataset.spotImg.split('-')[0]}-${state}`) || figures[0];
          figures.forEach(f => { f.hidden = f !== wanted; });
        };
        spot.querySelectorAll('[data-spot-screen], [data-spot-state]').forEach(button => button.addEventListener('click', () => {
          const attr = button.hasAttribute('data-spot-screen') ? 'data-spot-screen' : 'data-spot-state';
          spot.querySelectorAll(`[${attr}]`).forEach(b => b.setAttribute('aria-pressed', String(b === button)));
          pick();
        }));
      });

      document.querySelectorAll('[data-busy-form]').forEach(form => form.addEventListener('submit', () => {
        const b = form.querySelector('button');
        b.setAttribute('aria-busy', 'true');
        b.disabled = true;
      }));

      // While spots are being found: the bar and the stages follow the
      // server, the time left counts down in between, and the page shows the
      // spots once they're ready.
      const panel = document.querySelector('[data-spots-working]');
      if (panel) {
        const bar = document.getElementById('vd-spots-progress');
        const cost = document.getElementById('vd-cost');
        let shown = bar ? Number(bar.dataset.percent) : 0, remaining = bar ? Number(bar.dataset.remaining) : 0;
        const left = secs => secs < 45 ? 'less than a minute left' : secs < 90 ? 'about a minute left' : `about ${Math.round(secs / 60)} min left`;
        const paint = () => {
          if (!bar) return;
          bar.querySelector('[role=progressbar] span').style.width = shown + '%';
          bar.querySelector('[role=progressbar]').setAttribute('aria-valuenow', shown);
          bar.querySelector('[data-percent]').textContent = shown + '%';
          bar.querySelector('[data-left]').textContent = left(Math.max(0, remaining));
        };
        paint();
        setInterval(() => { if (remaining > 3) { remaining--; paint(); } }, 1000);
        const poll = setInterval(async () => {
          try {
            const s = await (await fetch(@json(route('video-demo.studio.status', $video)), { headers: { Accept: 'application/json' } })).json();
            if (cost) cost.textContent = '$' + Number(s.cost).toFixed(4);
            if (s.spots_progress && bar) {
              shown = Math.max(shown, s.spots_progress.percent);
              remaining = s.spots_progress.remaining;
              bar.querySelector('[data-stage]').textContent = s.spots_progress.stage;
              const items = [...document.querySelectorAll('[data-stage-key]')];
              const now = items.findIndex(li => li.textContent.trim() === s.spots_progress.stage);
              if (now >= 0) items.forEach((li, n) => { li.classList.toggle('is-done', n < now); li.classList.toggle('is-now', n === now); });
              paint();
            }
            if (s.spots !== 'working') {
              clearInterval(poll);
              location.hash = 'vd-spots';
              location.reload();
            }
          } catch {}
        }, 2000);
      }
    });
  </script>
@endonce
