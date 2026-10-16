{{--
  Code to show a published video on another page, with copy buttons.
  Needs $entry (the video's manifest entry).
--}}
@php
  $manifest = app(\Ogunsakin01\VideoDemo\Library\Manifest::class);
  $public = ($entry['visibility'] ?? 'public') === 'public';
  $blade = '<x-video-demo::player slug="'.$entry['slug'].'"'.($public ? '' : ' area="private"').' />';
  $snippets = ['Blade, in this app' => $blade];
  if ($public) {
      $first = array_key_first(\Ogunsakin01\VideoDemo\Library\Manifest::sources($entry));
      $source = \Ogunsakin01\VideoDemo\Library\Manifest::sources($entry)[$first];
      $snippets['HTML, anywhere'] = sprintf(
          '<video src="%s" poster="%s" controls playsinline preload="metadata" title="%s" style="width:100%%;aspect-ratio:%d/%d"></video>',
          url($manifest->url($entry, 'video', $first)), url($manifest->url($entry, 'poster', $first)),
          e($entry['title'] ?? ''), $source['width'], $source['height'],
      );
  }
@endphp
<div class="vd-card vd-stack-sm">
  <h2 style="margin:0">Embed</h2>
  @foreach ($snippets as $label => $code)
    <div class="vd-embed">
      <div class="vd-field-head">
        <span class="vd-label" style="margin:0">{{ $label }}</span>
        <button type="button" class="vd-btn vd-btn-sm" data-copy="{{ $code }}">Copy</button>
      </div>
      @if ($loop->first)
        <pre class="vd-code"><code>{{ $code }}</code></pre>
      @else
        {{-- Long, and copied more than read: shown on request. --}}
        <details class="vd-settings" style="margin:0;border:0">
          <summary style="padding:0">Show the code</summary>
          <pre class="vd-code" style="margin-top:8px"><code>{{ $code }}</code></pre>
        </details>
      @endif
    </div>
  @endforeach
  <span class="vd-hint">{{ $public ? 'The Blade component picks the version that suits each screen. The HTML plays the '.config('video-demo.devices.'.$first.'.label', $first).' version.' : 'Private videos can only be shown inside this app.' }}</span>
</div>
@include('video-demo::partials.copy-script')
