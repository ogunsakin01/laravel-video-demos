{{-- The full error behind a failed video, in a modal laid out like Laravel's error page. Expects $video with error_details. --}}
@php
  $report = $video->error_details;
  $e = $report['exception'];
  $step = $report['step'] ?? null;
  $env = $report['context']['Environment'] ?? [];
@endphp
<dialog class="vd-modal" id="vd-error-details" aria-labelledby="vd-error-title">
  <header class="vd-modal-head">
    <div style="min-width:0">
      <p class="vd-eyebrow" style="margin:0 0 6px">Full error · {{ \Illuminate\Support\Carbon::parse($report['at'])->format('j M Y, H:i:s') }}</p>
      <p class="vd-error-class">{{ $e['class'] }}</p>
      <h2 id="vd-error-title" class="vd-error-message">{{ $e['message'] }}</h2>
      <p class="vd-error-file">{{ $e['file'] }}:{{ $e['line'] }}</p>
      <p class="vd-error-pills">
        @foreach (['PHP', 'Laravel', 'laravel-video-demos'] as $key)
          @if (! empty($env[$key]))<span>{{ $key }} {{ $env[$key] }}</span>@endif
        @endforeach
      </p>
    </div>
    <div class="vd-actions" style="flex-shrink:0">
      <button type="button" class="vd-btn" data-copy-error>Copy as Markdown</button>
      <button type="button" class="vd-btn" data-close-error aria-label="Close">Close</button>
    </div>
  </header>

  <div class="vd-modal-body">
    @if ($step)
      <section class="vd-error-section">
        <h3>Where the recording stopped</h3>
        <p class="vd-small vd-muted" style="margin:0 0 10px">Step {{ $step['number'] }}@if ($step['device']) of the {{ $step['device'] }} version @endif on <a href="{{ $step['url'] }}" target="_blank" rel="noopener" style="overflow-wrap:anywhere">{{ $step['url'] }}</a>@if ($step['title']) ({{ $step['title'] }})@endif</p>
        <div class="vd-error-grid">
          @if ($step['screenshot'])
            <a href="{{ route('video-demo.studio.error-screenshot', $video) }}" target="_blank" rel="noopener" class="vd-error-shot">
              <img src="{{ route('video-demo.studio.error-screenshot', $video) }}" alt="The page when step {{ $step['number'] }} failed" loading="lazy">
            </a>
          @endif
          <div class="vd-stack-sm" style="min-width:0">
            @if ($step['plan'])
              <p class="vd-error-label">The step</p>
              <pre class="vd-code vd-code-plain">{{ json_encode($step['plan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
            <p class="vd-error-label">What the browser said</p>
            <pre class="vd-code vd-code-plain">{{ $step['error'] }}</pre>
          </div>
        </div>
      </section>
    @endif

    @foreach ($report['app_errors'] ?? [] as $error)
      <section class="vd-error-section">
        <h3>Error in your app</h3>
        <p class="vd-error-class">{{ $error['class'] }}</p>
        <p class="vd-error-app-message">{{ $error['message'] }}</p>
        <p class="vd-small vd-muted" style="margin:0 0 10px;overflow-wrap:anywhere">{{ $error['method'] ?? '' }} {{ $error['url'] ?? '' }} · {{ $error['file'] }}:{{ $error['line'] }}</p>
        @include('video-demo::studio.partials.error-frames', ['frames' => $error['frames'] ?? []])
      </section>
    @endforeach

    @if (! empty($report['issues']))
      <section class="vd-error-section">
        <h3>Pages that failed the check</h3>
        <ul class="vd-error-list">
          @foreach ($report['issues'] as $issue)
            <li><code>{{ $issue['path'] }}</code> {{ $issue['problem'] }}@if (! empty($issue['detail'])) <span class="vd-muted">· {{ $issue['detail'] }}</span>@endif</li>
          @endforeach
        </ul>
      </section>
    @endif

    <section class="vd-error-section">
      <h3>Stack trace</h3>
      @include('video-demo::studio.partials.error-frames', ['frames' => $e['frames']])
      @foreach ($e['previous'] ?? [] as $previous)
        <p class="vd-small" style="margin:12px 0 0">Caused by <code>{{ $previous['class'] }}</code>: {{ $previous['message'] }} <span class="vd-muted">({{ $previous['file'] }}:{{ $previous['line'] }})</span></p>
      @endforeach
    </section>

    @if (! empty($step['browser']))
      <section class="vd-error-section">
        <h3>Browser</h3>
        <p class="vd-small vd-muted" style="margin:0 0 8px">Console errors and warnings, and requests that failed, while it was recorded. Times are seconds into the recording.</p>
        <ul class="vd-error-list vd-browser-log">
          @foreach ($step['browser'] as $line)
            <li><span class="vd-log-kind vd-log-{{ $line['kind'] }}">{{ $line['kind'] === 'response' ? 'HTTP '.($line['status'] ?? '') : $line['kind'] }}</span><span class="vd-muted">{{ number_format(($line['t'] ?? 0) / 1000, 1) }}s</span><code>{{ $line['text'] }}@if (! empty($line['reason'])) ({{ $line['reason'] }})@endif</code></li>
          @endforeach
        </ul>
      </section>
    @endif

    @if (! empty($step['snapshot']))
      <section class="vd-error-section">
        <details>
          <summary><h3 style="display:inline">The page's structure when it failed</h3></summary>
          <p class="vd-small vd-muted">What the recorder could see: the accessibility tree it looks for buttons, links and fields in.</p>
          <pre class="vd-code vd-code-plain" style="max-height:420px">{{ $step['snapshot'] }}</pre>
        </details>
      </section>
    @endif

    <section class="vd-error-section vd-error-context">
      @foreach ($report['context'] ?? [] as $title => $values)
        <div>
          <h3>{{ $title }}</h3>
          <dl class="vd-kv">
            @foreach ($values as $key => $value)
              <dt>{{ $key }}</dt><dd>{{ $value }}</dd>
            @endforeach
          </dl>
        </div>
      @endforeach
    </section>
  </div>
  <textarea hidden data-error-markdown>{{ \Ogunsakin01\VideoDemo\Studio\ErrorReport::markdown($report) }}</textarea>
</dialog>
<script>
  (() => {
    const dialog = document.getElementById('vd-error-details');
    document.querySelectorAll('[data-open-error]').forEach(b => b.addEventListener('click', () => dialog.showModal()));
    dialog.querySelector('[data-close-error]').addEventListener('click', () => dialog.close());
    // A click on the backdrop closes it too.
    dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
    const copy = dialog.querySelector('[data-copy-error]');
    copy.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(dialog.querySelector('[data-error-markdown]').value);
        copy.textContent = 'Copied';
      } catch {
        copy.textContent = 'Could not copy';
      }
      setTimeout(() => { copy.textContent = 'Copy as Markdown'; }, 2000);
    });
  })();
</script>
