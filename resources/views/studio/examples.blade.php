<x-video-demo::layout title="Examples" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => 'examples'])
  </x-slot:nav>

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>Examples</h1>
      <p class="vd-sub">Videos made with this package. Yours are made the same way: the AI reads your app's code, plans the walkthrough, and it's recorded in a browser.</p>
    </div>
  </section>

  <div class="vd-stack">
    @foreach ($examples as $example)
      @php($first = array_key_first($example['versions']))
      <article class="vd-card vd-split" style="align-items:center" data-example>
        <div class="vd-stack-sm">
          <video class="vd-video" controls preload="none" playsinline data-player
            poster="{{ $example['versions'][$first]['poster'] }}" src="{{ $example['versions'][$first]['video'] }}"
            style="aspect-ratio:{{ $devices[$first]['width'] ?? 16 }}/{{ $devices[$first]['height'] ?? 10 }};max-height:70vh;margin:0 auto;background:#0e0e0d"></video>
          @if (count($example['versions']) > 1)
            <div class="vd-segments" role="group" aria-label="Version of {{ $example['title'] }}">
              @foreach ($example['versions'] as $key => $version)
                <button type="button" class="vd-segment" aria-pressed="{{ $key === $first ? 'true' : 'false' }}"
                  data-src="{{ $version['video'] }}" data-poster="{{ $version['poster'] }}"
                  data-ratio="{{ $devices[$key]['width'] ?? 16 }}/{{ $devices[$key]['height'] ?? 10 }}">{{ $devices[$key]['label'] ?? ucfirst($key) }}</button>
              @endforeach
            </div>
          @endif
        </div>
        <div class="vd-stack">
          <div>
            <h2 style="font-size:19px;margin:0 0 6px">{{ $example['title'] }}</h2>
            <p class="vd-muted" style="margin:0">{{ $example['about'] }}</p>
          </div>
          <dl style="margin:0">
            <div class="vd-stat"><dt>Style</dt><dd>{{ $example['style'] }}</dd></div>
            <div class="vd-stat"><dt>Length</dt><dd class="vd-num">{{ gmdate('i:s', $example['versions'][$first]['seconds']) }}</dd></div>
            <div class="vd-stat"><dt>Versions</dt><dd>{{ collect(array_keys($example['versions']))->map(fn ($d) => $devices[$d]['label'] ?? $d)->implode(', ') }}</dd></div>
          </dl>
          <div>
            <a class="vd-btn vd-btn-primary" href="{{ route('video-demo.studio.create', ['style' => $example['style_key']]) }}">Make one like this</a>
          </div>
        </div>
      </article>
    @endforeach
  </div>

  <p class="vd-small vd-muted" style="margin-top:20px">These videos come from the package's demo app and are streamed from its GitHub repository. Turn this page off with <code>VIDEO_DEMO_EXAMPLES=false</code>.</p>

  <x-slot:scripts>
    <script>
      // Switch an example between its versions.
      document.querySelectorAll('[data-example]').forEach(example => {
        const player = example.querySelector('[data-player]');
        example.querySelectorAll('[data-src]').forEach(b => b.addEventListener('click', () => {
          const playing = !player.paused;
          player.poster = b.dataset.poster;
          player.src = b.dataset.src;
          player.style.aspectRatio = b.dataset.ratio;
          if (playing) player.play();
          example.querySelectorAll('[data-src]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
        }));
      });
    </script>
  </x-slot:scripts>
</x-video-demo::layout>
