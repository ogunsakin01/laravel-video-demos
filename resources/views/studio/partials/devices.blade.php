{{-- One switch per version of the video. $devices: the configured devices; $selected: the keys switched on. --}}
@php
  $icons = [
    'desktop' => '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>',
    'tv' => '<rect x="2" y="5" width="20" height="13" rx="1.5"/><path d="M8 21h8"/>',
    'tablet' => '<rect x="5" y="2.5" width="14" height="19" rx="2"/><path d="M11 18.5h2"/>',
    'mobile' => '<rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2"/>',
  ];
@endphp
<div class="vd-toggles" role="group" aria-labelledby="{{ $labelledBy ?? 'vd-devices-label' }}">
  @foreach ($devices as $key => $device)
    <label class="vd-toggle">
      <input type="checkbox" name="devices[]" value="{{ $key }}" @checked(in_array($key, $selected, true))>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$key] ?? $icons['desktop'] !!}</svg>
      <span class="vd-toggle-text">
        <span class="vd-toggle-name">{{ $device['label'] }}</span>
        <span class="vd-toggle-size vd-mono">{{ $device['width'] }}×{{ $device['height'] }}</span>
      </span>
      <span class="vd-switch" aria-hidden="true"></span>
    </label>
  @endforeach
</div>
