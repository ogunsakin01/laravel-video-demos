{{--
  "Where to put it": spots in the app where the video would help, each with a
  screenshot of the video in place on a desktop and a phone screen, the view
  and line to paste it at, and the tag to paste. Expects $video.
--}}
@php
  $spots = $video->spots ?? [];
  $state = $spots['status'] ?? null;
  $items = $spots['items'] ?? [];
  $tag = '<x-video-demo::player slug="'.$video->slug.'"'.($video->visibility === 'private' ? ' area="private"' : '').' />';
  $who = $video->record_as_label ?: \Ogunsakin01\VideoDemo\Recording\RecordAs::label($video->record_as);
  $hasKey = \Ogunsakin01\VideoDemo\Ai\Drivers\Drivers::anyKey();
@endphp
<section class="vd-card vd-stack" id="vd-spots" @if ($state === 'working') data-spots-working @endif>
  <div>
    <h2 style="margin:0">Where to put it</h2>
    <p class="vd-small vd-muted" style="margin:4px 0 0">Places in your app where people doing this task would see the video, with a picture of each.</p>
  </div>

  @if ($state === 'working')
    <p class="vd-small" style="margin:0;display:flex;gap:10px;align-items:center">
      <span class="vd-btn vd-btn-sm" aria-busy="true" style="pointer-events:none">Working</span>
      Opening your pages and picking spots. This takes a minute or two.
    </p>
  @elseif ($state === 'failed')
    <p class="vd-small" style="margin:0;color:var(--vd-bad);overflow-wrap:anywhere">{{ $spots['error'] ?? 'It did not finish.' }}</p>
  @endif

  @if (! empty($spots['note']) && $state === 'ready')
    <p class="vd-small vd-muted" style="margin:0">{{ $spots['note'] }}</p>
  @endif

  @foreach ($items as $i => $spot)
    <article class="vd-spot">
      <div class="vd-spot-head">
        <span class="vd-spot-num">{{ $i + 1 }}</span>
        <div style="min-width:0">
          <h3 style="margin:0">{{ $spot['label'] }}</h3>
          <p class="vd-small vd-muted" style="margin:2px 0 0">On <a href="{{ rtrim((string) (config('video-demo.recorder.base_url') ?: config('app.url')), '/').$spot['path'] }}" target="_blank" rel="noopener" class="vd-mono">{{ $spot['path'] }}</a>{{ $spot['why'] ? ' · '.$spot['why'] : '' }}</p>
        </div>
      </div>

      @if (count($spot['shots']) > 1)
        <div class="vd-segments" role="group" aria-label="Screen">
          @foreach ($spot['shots'] as $screen => $file)
            <button type="button" class="vd-segment" data-spot-shot="{{ $i }}-{{ $screen }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ config("video-demo.devices.$screen.label", ucfirst($screen)) }}</button>
          @endforeach
        </div>
      @endif
      @foreach ($spot['shots'] as $screen => $file)
        <a href="{{ route('video-demo.studio.spot-shot', [$video, $file]) }}?v={{ urlencode($spots['at'] ?? '') }}" target="_blank" rel="noopener" class="vd-spot-shot vd-spot-shot-{{ $screen }}" data-spot-shot-img="{{ $i }}-{{ $screen }}" @unless ($loop->first) hidden @endunless>
          <img src="{{ route('video-demo.studio.spot-shot', [$video, $file]) }}?v={{ urlencode($spots['at'] ?? '') }}" alt="The video shown at {{ $spot['label'] }}, on {{ config("video-demo.devices.$screen.label", $screen) }}" loading="lazy">
        </a>
      @endforeach

      <div class="vd-embed">
        <div class="vd-field-head">
          <span class="vd-label" style="margin:0">
            @if ($spot['code'])
              Paste {{ $spot['position'] === 'after' ? 'after' : ($spot['position'] === 'before' ? 'before' : 'inside, at the top of') }} the section at <span class="vd-mono">{{ $spot['code']['file'] }}:{{ $spot['code']['line'] }}</span>
            @else
              Paste {{ $spot['position'] === 'after' ? 'after' : ($spot['position'] === 'before' ? 'before' : 'at the top of') }} the {{ $spot['heading'] ? '“'.$spot['heading'].'” section' : 'section shown' }}
            @endif
          </span>
          <button type="button" class="vd-btn vd-btn-sm" data-copy="{{ $tag }}">Copy</button>
        </div>
        <pre class="vd-code"><code>{{ $tag }}</code></pre>
        @if ($spot['code'])
          <span class="vd-hint vd-mono" style="overflow-wrap:anywhere">{{ $spot['code']['line'] }} │ {{ $spot['code']['text'] }}</span>
        @endif
      </div>
    </article>
  @endforeach

  @if ($items !== [] && ! $video->isPublished())
    <p class="vd-small vd-muted" style="margin:0">Publish the video first: until then the tag shows nothing.</p>
  @endif

  @unless ($state === 'working')
    <form method="post" action="{{ route('video-demo.studio.spots', $video) }}" data-busy-form>@csrf
      <button class="vd-btn {{ $items === [] ? 'vd-btn-primary' : '' }}">{{ $items === [] ? ($state === 'failed' ? 'Try again' : 'Suggest where to put it') : 'Suggest again' }}</button>
      <span class="vd-hint">
        Opens the video's pages as {{ \Illuminate\Support\Str::lcfirst($who) }}{{ $video->sample_data ? ', on its sample data' : '' }}.
        {{ $hasKey ? 'The AI picks two or three spots, for about a cent.' : 'With no AI key set, it shows the top of the page the video starts on.' }}
      </span>
    </form>
  @endunless
</section>
@include('video-demo::partials.copy-script')

@once
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Desktop or phone screenshot.
      document.querySelectorAll('[data-spot-shot]').forEach(button => {
        button.addEventListener('click', () => {
          const [spot] = button.dataset.spotShot.split('-');
          button.parentElement.querySelectorAll('[data-spot-shot]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
          document.querySelectorAll(`[data-spot-shot-img^="${spot}-"]`).forEach(img => { img.hidden = img.dataset.spotShotImg !== button.dataset.spotShot; });
        });
      });

      document.querySelectorAll('[data-busy-form]').forEach(form => form.addEventListener('submit', () => {
        const b = form.querySelector('button');
        b.setAttribute('aria-busy', 'true');
        b.disabled = true;
      }));

      // While spots are being found, check now and then, and show them once ready.
      const panel = document.querySelector('[data-spots-working]');
      if (panel) {
        const poll = setInterval(async () => {
          try {
            const s = await (await fetch(@json(route('video-demo.studio.status', $video)), { headers: { Accept: 'application/json' } })).json();
            if (s.spots !== 'working') {
              clearInterval(poll);
              location.hash = 'vd-spots';
              location.reload();
            }
          } catch {}
        }, 3000);
      }
    });
  </script>
@endonce
