{{-- The <video> of a published video. Expects $attributes, $video (its manifest entry), $sources and $first; $device is optional. --}}
<video {{ $attributes->except('style')->merge(['class' => 'vd-video', 'controls' => true, 'preload' => 'metadata', 'playsinline' => true]) }}
  data-vd-sources='@json($sources)' @if ($device ?? null) data-vd-device="{{ $device }}" @endif
  @if ($stats ?? null) data-vd-stats="{{ $stats }}" data-vd-token="{{ rescue(fn () => csrf_token(), '', false) }}" @endif
  poster="{{ $first['poster'] }}" src="{{ $first['video'] }}" title="{{ $video['title'] }}"
  style="{{ $attributes->get('style', 'width:100%;border-radius:12px;background:#0e0e0d;display:block;max-height:82vh') }};aspect-ratio:{{ $first['width'] }}/{{ $first['height'] }}">
  @if ($first['subtitles'] ?? null)<track kind="subtitles" srclang="{{ $video['language'] ?? 'en' }}" label="Captions" src="{{ $first['subtitles'] }}">@endif
</video>
