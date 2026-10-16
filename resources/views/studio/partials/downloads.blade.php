{{--
  "Downloads": the video's files, and copies made for other places: a GIF,
  and square and vertical MP4s for social posts. Expects $video and $devices.
--}}
@php
  use Ogunsakin01\VideoDemo\Recording\Exporter;
  $exports = $video->exports ?? [];
  $working = $video->isExporting();
  $size = fn (?int $bytes) => $bytes === null ? '' : ($bytes >= 1_048_576 ? number_format($bytes / 1_048_576, 1).' MB' : max(1, round($bytes / 1024)).' KB');
  $uses = ['gif' => 'For READMEs, changelogs, emails and chat, which don’t play videos.', 'square' => 'For social feeds, with the title above the app.', 'vertical' => 'For Stories, Reels and Shorts, with the title above the app.'];
  $made = collect(Exporter::FORMATS)->filter(fn ($info, $format) => Exporter::isCurrent($video, $format))->map(fn ($info) => $info['label'])->values();
  $summary = collect($video->recordedDevices())->filter(fn ($d) => $video->hasRecording($d))->map(fn ($d) => $devices[$d]['label'] ?? $d)->implode(', ').' MP4'
      .($working ? ' · making a copy…' : ($made->isNotEmpty() ? ' · '.$made->implode(', ') : ' · GIF, square and vertical copies on request'));
@endphp
<details class="vd-card vd-fold" id="vd-downloads" data-fold="downloads" @if ($working) open data-fold-keep data-exports-working @endif>
  <summary>
    <span class="vd-fold-title">
      <h2>Downloads</h2>
      <span class="vd-fold-hint">{{ $summary }}</span>
    </span>
  </summary>
  <div class="vd-fold-body vd-stack-sm">
    <p class="vd-small vd-muted" style="margin:0">The video's files, and copies for places that don’t play them as they are. Made on this machine: no AI is used.</p>

  <ul class="vd-files">
    @foreach ($video->recordedDevices() as $device)
      @if ($video->hasRecording($device))
        <li><span class="vd-num">MP4</span><span style="flex:1">{{ $devices[$device]['label'] ?? $device }}</span><a class="vd-btn vd-btn-sm" href="{{ route('video-demo.studio.file', [$video, $device, 'video']) }}?v={{ $video->version }}" download="{{ $video->slug }}-{{ $device }}.mp4">Download</a></li>
      @endif
    @endforeach

    @foreach (Exporter::FORMATS as $format => $info)
      @php($export = $exports[$format] ?? null)
      @php($current = Exporter::isCurrent($video, $format))
      <li>
        <div style="display:contents">
          <span class="vd-num">{{ strtoupper($info['ext']) }}</span>
          <span style="flex:1;min-width:160px">
            <span style="color:var(--vd-strong)">{{ $info['label'] }}</span>
            <span class="vd-small vd-muted" style="display:block">{{ $uses[$format] }}</span>
            @if (($export['status'] ?? null) === 'failed')
              <span class="vd-small" style="display:block;color:var(--vd-bad);overflow-wrap:anywhere">{{ $export['error'] ?? 'It could not be made.' }}</span>
            @elseif (($export['status'] ?? null) === 'ready' && ! $current)
              <span class="vd-small vd-muted" style="display:block">Made from an older recording.</span>
            @endif
          </span>
          @if (($export['status'] ?? null) === 'working')
            <span class="vd-btn vd-btn-sm" aria-busy="true" style="pointer-events:none">Making</span>
          @else
            @if ($current)
              <a class="vd-btn vd-btn-sm vd-btn-primary" href="{{ route('video-demo.studio.download', [$video, $format]) }}?v={{ $video->version }}">Download · {{ $size($export['bytes'] ?? null) }}</a>
            @endif
            <form method="post" action="{{ route('video-demo.studio.export', $video) }}" data-busy-form>@csrf
              <input type="hidden" name="format" value="{{ $format }}">
              <button class="vd-btn vd-btn-sm">{{ $current ? 'Make again' : 'Make it' }}</button>
            </form>
          @endif
        </div>
      </li>
    @endforeach
  </ul>
  </div>
</details>

@once
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // While a copy is being made, look now and then, and show it once it's done.
      if (document.querySelector('[data-exports-working]')) {
        const poll = setInterval(async () => {
          try {
            const s = await (await fetch(@json(route('video-demo.studio.status', $video)), { headers: { Accept: 'application/json' } })).json();
            if (!Object.values(s.exports || {}).includes('working')) {
              clearInterval(poll);
              location.hash = 'vd-downloads';
              location.reload();
            }
          } catch {}
        }, 3000);
      }
    });
  </script>
@endonce
