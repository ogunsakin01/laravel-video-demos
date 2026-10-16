{{--
  "Does it still work?": the last check that the video's plan still plays in
  the app, and buttons to check it again or fix it. Expects $video.
--}}
@php
  $check = $video->verify_result ?? [];
  $state = $check['status'] ?? null;
  $devices = collect($check['checked'] ?? [])->map(fn ($d) => config("video-demo.devices.$d.label", ucfirst($d)))->implode(', ');
  $broken = $state === 'done' && ! ($check['ok'] ?? false);
  $summary = match (true) {
      $state === 'working' => 'Checking…',
      $broken => ! empty($check['step']) ? 'Step '.$check['step'].' no longer works' : 'The video no longer works',
      $state === 'done' => 'Every step worked, '.$video->verified_at?->diffForHumans(),
      default => 'Not checked yet. Free: no AI is used.',
  };
@endphp
<details class="vd-card vd-fold" id="vd-check" data-fold="check" @if ($state === 'working' || $broken) open data-fold-keep @endif @if ($state === 'working') data-check-working @endif>
  <summary>
    <span class="vd-fold-title">
      <h2>Does it still work?</h2>
      <span class="vd-fold-hint" @if ($broken) data-tone="bad" @elseif ($state === 'done') data-tone="ok" @endif>{{ $summary }}</span>
    </span>
  </summary>
  <div class="vd-fold-body vd-stack-sm">
    <p class="vd-small vd-muted" style="margin:0">Plays the video’s steps in your app without recording them, to find what a change broke. Free: no AI is used.</p>

  @if ($state === 'working')
    <p class="vd-small" style="margin:0;display:flex;gap:10px;align-items:center">
      <span class="vd-btn vd-btn-sm" aria-busy="true" style="pointer-events:none">Checking</span>
      Opening the video’s pages and playing its steps.
    </p>
  @elseif ($state === 'done' && ($check['ok'] ?? false))
    <p class="vd-small" style="margin:0;color:var(--vd-ok)">
      Every step worked{{ $devices ? ' on '.$devices : '' }}, {{ $video->verified_at?->diffForHumans() }}.
    </p>
  @elseif ($state === 'done')
    <div class="vd-stack-sm" style="padding:12px 14px;border-radius:var(--vd-radius);background:var(--vd-bad-bg);color:var(--vd-bad)">
      <strong>
        @if (! empty($check['step']))
          Step {{ $check['step'] }}{{ ! empty($check['action']) ? ' ('.$check['action'].')' : '' }} no longer works{{ ! empty($check['device']) ? ' on '.config('video-demo.devices.'.$check['device'].'.label', $check['device']) : '' }}
        @else
          The video no longer works
        @endif
      </strong>
      <span class="vd-small" style="overflow-wrap:anywhere">{{ $check['error'] ?? '' }}</span>
      @foreach ($check['issues'] ?? [] as $issue)
        @if (! empty($issue['detail']))<span class="vd-small vd-mono" style="overflow-wrap:anywhere">{{ $issue['path'] ?? '' }}: {{ $issue['detail'] }}</span>@endif
      @endforeach
      <span class="vd-small">Checked {{ $video->verified_at?->diffForHumans() }}{{ ! empty($check['url']) ? ', on '.(parse_url($check['url'], PHP_URL_PATH) ?: $check['url']) : '' }}.</span>
    </div>
    @if (! empty($check['screenshot']))
      <a href="{{ route('video-demo.studio.verify-screenshot', $video) }}?v={{ $video->verified_at?->timestamp }}" target="_blank" rel="noopener" class="vd-check-shot">
        <img src="{{ route('video-demo.studio.verify-screenshot', $video) }}?v={{ $video->verified_at?->timestamp }}" alt="The page when the step failed" loading="lazy">
      </a>
    @endif
  @endif

  @unless ($state === 'working')
    <div class="vd-actions">
      @if ($state === 'done' && ! ($check['ok'] ?? false))
        <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
          <input type="hidden" name="mode" value="plan">
          <button class="vd-btn vd-btn-primary">Fix with a new plan</button>
        </form>
      @endif
      <form method="post" action="{{ route('video-demo.studio.verify', $video) }}" data-busy-form>@csrf
        <button class="vd-btn">{{ $state === 'done' ? 'Check again' : 'Check it now' }}</button>
      </form>
    </div>
    <span class="vd-hint">In CI: <span class="vd-mono">php artisan video-demo:verify --changed=origin/main</span> checks the videos a change touches.</span>
  @endunless
  </div>
</details>

@once
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('#vd-check [data-busy-form]').forEach(form => form.addEventListener('submit', () => {
        const b = form.querySelector('button');
        b.setAttribute('aria-busy', 'true');
        b.disabled = true;
      }));

      // While the check runs, look now and then, and show the result once it's in.
      if (document.querySelector('[data-check-working]')) {
        const poll = setInterval(async () => {
          try {
            const s = await (await fetch(@json(route('video-demo.studio.status', $video)), { headers: { Accept: 'application/json' } })).json();
            if (s.verify !== 'working') {
              clearInterval(poll);
              location.hash = 'vd-check';
              location.reload();
            }
          } catch {}
        }, 3000);
      }
    });
  </script>
@endonce
