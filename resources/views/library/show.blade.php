<x-video-demo::layout :title="$video['title']" :badge="$area === 'private' ? 'Staff' : null" :home="route('video-demo.'.$area.'.index')">
  <x-slot:nav>
    <a class="vd-btn" href="{{ route('video-demo.'.$area.'.index') }}">All videos</a>
  </x-slot:nav>

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">How-to · {{ gmdate('i:s', (int) round($video['duration'] ?? 0)) }}</p>
      <h1>{{ $video['title'] }}</h1>
      @if (! empty($video['summary']))<p class="vd-sub">{{ $video['summary'] }}</p>@endif
    </div>
  </section>

  @php($sources = \Ogunsakin01\VideoDemo\Library\Manifest::sources($video))
  <div class="vd-split">
    <div class="vd-stack">
      <x-video-demo::player :slug="$video['slug']" :area="$area" id="vd-player" />
      @if (count($sources) > 1)
        <div class="vd-segments" role="group" aria-label="Version" id="vd-versions">
          @foreach ($sources as $key => $source)
            <button type="button" class="vd-segment" data-device="{{ $key }}">{{ config("video-demo.devices.$key.label", ucfirst($key)) }}</button>
          @endforeach
        </div>
      @endif
    </div>

    <aside class="vd-stack">
      @if (! empty($video['chapters']))
        <div class="vd-card">
          <h2>Chapters</h2>
          <ol class="vd-chapters">
            @foreach ($video['chapters'] as $chapter)
              <li><button type="button" data-seek="{{ $chapter['at'] }}"><span class="vd-num">{{ gmdate('i:s', (int) $chapter['at']) }}</span>{{ $chapter['title'] }}</button></li>
            @endforeach
          </ol>
        </div>
      @endif

      @if ($area === 'private')
        @include('video-demo::partials.embed', ['entry' => $video])
      @endif

      @if ($more->isNotEmpty())
        <div class="vd-card">
          <h2>More videos</h2>
          <ul class="vd-chapters">
            @foreach ($more as $other)
              <li><a href="{{ route('video-demo.'.$area.'.show', $other['slug']) }}"><span class="vd-num">{{ gmdate('i:s', (int) round($other['duration'] ?? 0)) }}</span>{{ $other['title'] }}</a></li>
            @endforeach
          </ul>
        </div>
      @endif
    </aside>
  </div>

  <x-slot:scripts>
    <script>
      // Switch between the recorded versions, keeping the place in the video
      document.querySelectorAll('#vd-versions [data-device]').forEach(b => b.addEventListener('click', () => {
        const v = document.getElementById('vd-player');
        const at = v.currentTime, playing = !v.paused;
        window.videoDemoShow(v, b.dataset.device);
        v.addEventListener('loadedmetadata', () => { v.currentTime = at; if (playing) v.play(); }, { once: true });
        mark();
      }));
      const mark = () => document.querySelectorAll('#vd-versions [data-device]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.device === document.getElementById('vd-player').dataset.vdShowing)));
      document.addEventListener('DOMContentLoaded', () => setTimeout(mark));
      document.querySelectorAll('[data-seek]').forEach(b => b.addEventListener('click', () => {
        const v = document.getElementById('vd-player');
        v.currentTime = Number(b.dataset.seek);
        v.play();
      }));
    </script>
  </x-slot:scripts>
</x-video-demo::layout>
