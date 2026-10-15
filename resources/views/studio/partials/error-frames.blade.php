{{-- A stack trace, as Laravel's error page shows it: the app's own frames with their code, runs of vendor frames folded. Expects $frames. --}}
@php
  $groups = [];
  foreach ($frames as $i => $frame) {
      $vendor = $frame['vendor'] && $i > 0;
      if ($vendor && $groups && end($groups)['vendor']) {
          $groups[array_key_last($groups)]['frames'][] = $frame;
      } else {
          $groups[] = ['vendor' => $vendor, 'frames' => [$frame]];
      }
  }
@endphp
<ol class="vd-frames">
  @foreach ($groups as $g => $group)
    @if ($group['vendor'] && count($group['frames']) > 1)
      <li>
        <details class="vd-frame vd-frame-vendor">
          <summary>{{ count($group['frames']) }} vendor frames</summary>
          <ol class="vd-frames">
            @foreach ($group['frames'] as $frame)
              <li class="vd-frame-line"><span class="vd-frame-call">{{ $frame['call'] }}</span>@if ($frame['file'])<span class="vd-frame-file">{{ $frame['file'] }}:{{ $frame['line'] }}</span>@endif</li>
            @endforeach
          </ol>
        </details>
      </li>
    @else
      @foreach ($group['frames'] as $frame)
        <li>
          <details class="vd-frame{{ $frame['vendor'] ? ' vd-frame-vendor' : '' }}" @if ($g === 0) open @endif>
            <summary><span class="vd-frame-call">{{ $frame['call'] }}</span>@if ($frame['file'])<span class="vd-frame-file">{{ $frame['file'] }}:{{ $frame['line'] }}</span>@endif</summary>
            @if (! empty($frame['code']))
              <pre class="vd-code">@foreach ($frame['code']['lines'] as $n => $code)<span @class(['vd-code-hit' => $frame['code']['start'] + $n === $frame['line']])><b>{{ $frame['code']['start'] + $n }}</b>{{ $code }}
</span>@endforeach</pre>
            @endif
          </details>
        </li>
      @endforeach
    @endif
  @endforeach
</ol>
