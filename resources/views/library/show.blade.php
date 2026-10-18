@php
  $manifest = app(\Ogunsakin01\VideoDemo\Library\Manifest::class);
  $guide = $manifest->guide($video);
  $language = $video['language'] ?? null;
  $absolute = fn (?string $u) => $u !== null && str_starts_with($u, '/') ? url($u) : $u;
  $description = trim((string) ($video['summary'] ?? '')) ?: $video['title'];
@endphp
<x-video-demo::layout :title="$video['title']" :badge="$area === 'private' ? 'Staff' : null" :home="route('video-demo.'.$area.'.index')">
  <x-slot:head>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($description, 300) }}">
    @if ($area === 'private')
      <meta name="robots" content="noindex">
    @else
      {{-- For search engines and link previews: the poster, the video and what it shows. --}}
      @php($poster = $absolute($manifest->url($video, 'poster')))
      @php($mp4 = $absolute($manifest->url($video)))
      @php($seconds = (int) round($video['duration'] ?? 0))
      <link rel="canonical" href="{{ route('video-demo.public.show', $video['slug']) }}">
      <meta property="og:type" content="video.other">
      <meta property="og:title" content="{{ $video['title'] }}">
      <meta property="og:description" content="{{ \Illuminate\Support\Str::limit($description, 300) }}">
      <meta property="og:url" content="{{ route('video-demo.public.show', $video['slug']) }}">
      @if ($poster)<meta property="og:image" content="{{ $poster }}">@endif
      @if ($mp4)
        <meta property="og:video" content="{{ $mp4 }}">
        <meta property="og:video:type" content="video/mp4">
      @endif
      <meta name="twitter:card" content="summary_large_image">
      @php($schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'VideoObject',
        'name' => $video['title'],
        'description' => $description,
        'thumbnailUrl' => $poster,
        'contentUrl' => $mp4,
        'uploadDate' => $video['published_at'] ?? null,
        'duration' => sprintf('PT%dM%dS', intdiv($seconds, 60), $seconds % 60),
        'inLanguage' => $language,
      ]))
      {{-- Encoded so nothing in a title can end the script tag. --}}
      <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif
  </x-slot:head>
  <x-slot:nav>
    <a class="vd-btn" href="{{ route('video-demo.'.$area.'.index') }}">All videos</a>
  </x-slot:nav>

  <section class="vd-hero">
    @include('video-demo::partials.art', ['name' => 'play'])
    <div>
      <p class="vd-eyebrow">How-to · {{ gmdate('i:s', (int) round($video['duration'] ?? 0)) }}</p>
      <h1>{{ $video['title'] }}</h1>
      @if (! empty($video['summary']))<p class="vd-sub">{{ $video['summary'] }}</p>@endif
    </div>
  </section>

  @php($sources = \Ogunsakin01\VideoDemo\Library\Manifest::sources($video))
  <div class="vd-split">
    <div class="vd-stack">
      <x-video-demo::player :slug="$video['slug']" :area="$area" :language="false" id="vd-player" />
      @if (count($sources) > 1)
        <div class="vd-segments" role="group" aria-label="Version" id="vd-versions">
          @foreach ($sources as $key => $source)
            <button type="button" class="vd-segment" data-device="{{ $key }}">{{ config("video-demo.devices.$key.label", ucfirst($key)) }}</button>
          @endforeach
        </div>
      @endif

      @if ($guide !== [])
        {{-- The video's steps in writing, made from its plan. --}}
        <section class="vd-card vd-guide" aria-labelledby="vd-guide-title" @if ($language) lang="{{ $language }}" @endif>
          <h2 id="vd-guide-title">{{ __('video-demo::guide.heading', [], \Ogunsakin01\VideoDemo\Library\Guide::locale($language)) }}</h2>
          @php($number = 1)
          @foreach ($guide as $section)
            @if ($section['title'])<h3>{{ $section['title'] }}</h3>@endif
            @foreach (\Ogunsakin01\VideoDemo\Library\Guide::blocks($section['items']) as $block)
              @if (isset($block['note']))
                <p class="vd-guide-note">@if ($block['note']['title'])<strong>{{ $block['note']['text'] !== '' && ! preg_match('/[.!?:]$/u', $block['note']['title']) ? $block['note']['title'].'.' : $block['note']['title'] }}</strong>@endif {{ $block['note']['text'] }}</p>
              @else
                <ol start="{{ $number }}">
                  @foreach ($block['steps'] as $item)
                    <li>{{ \Ogunsakin01\VideoDemo\Library\Guide::sentence($item, $language) }}</li>
                  @endforeach
                </ol>
                @php($number += count($block['steps']))
              @endif
            @endforeach
          @endforeach
        </section>
      @endif
    </div>

    <aside class="vd-stack">
      @if ($languages !== [])
        <div class="vd-card">
          <h2>Languages</h2>
          <div class="vd-segments" role="group" aria-label="Language">
            @foreach ($languages as $version)
              <a class="vd-segment" href="{{ route('video-demo.'.$area.'.show', $version['slug']) }}?lang={{ $version['language'] }}" hreflang="{{ $version['language'] }}" lang="{{ $version['language'] }}" aria-current="{{ $version['slug'] === $video['slug'] ? 'true' : 'false' }}" aria-pressed="{{ $version['slug'] === $video['slug'] ? 'true' : 'false' }}">{{ \Ogunsakin01\VideoDemo\Support\Language::native($version['language']) }}</a>
            @endforeach
          </div>
        </div>
      @endif
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
      // Switch between the recorded versions, keeping the place in the video.
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
