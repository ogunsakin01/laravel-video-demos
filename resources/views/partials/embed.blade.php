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
      <pre class="vd-code"><code>{{ $code }}</code></pre>
    </div>
  @endforeach
  <span class="vd-hint">{{ $public ? 'The Blade component picks the version that suits each screen. The HTML plays the '.config('video-demo.devices.'.$first.'.label', $first).' version.' : 'Private videos can only be shown inside this app.' }}</span>
</div>
@once
  <script>
    document.addEventListener('click', async e => {
      const b = e.target.closest('[data-copy]');
      if (!b) return;
      try {
        await navigator.clipboard.writeText(b.dataset.copy);
      } catch {
        const t = document.createElement('textarea');
        t.value = b.dataset.copy; document.body.append(t); t.select(); document.execCommand('copy'); t.remove();
      }
      const was = b.textContent; b.textContent = 'Copied'; setTimeout(() => { b.textContent = was; }, 1500);
    });
  </script>
@endonce
