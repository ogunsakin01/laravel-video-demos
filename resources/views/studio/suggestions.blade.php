<x-video-demo::layout title="Ideas" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => 'ideas'])
  </x-slot:nav>

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>Ideas</h1>
      <p class="vd-sub">Videos the AI suggests making first, from your app's routes: the flows new users most need. Pick one and it opens in the new-video form, ready to check and generate. Ideas are kept, so you can come back to them.</p>
    </div>
    <form method="post" action="{{ route('video-demo.studio.suggestions.store') }}" class="vd-actions" id="vd-suggest-form" style="align-items:center">
      @csrf
      <select name="model" aria-label="Model" style="width:auto">
        @foreach ($models as $id => $label)
          <option value="{{ $id }}" @selected($model === $id)>{{ $label }}</option>
        @endforeach
      </select>
      @if ($ideas->isNotEmpty())
        <button class="vd-btn" name="replace" value="1" @disabled(! $hasKey) data-busy="Replacing…">Replace all</button>
      @endif
      <button class="vd-btn vd-btn-primary" name="replace" value="0" @disabled(! $hasKey) data-busy="Reading your routes…">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
        <span>{{ $ideas->isNotEmpty() ? 'Suggest more' : 'Suggest videos' }}</span>
      </button>
    </form>
  </section>

  @unless ($hasKey)
    <div class="vd-flash vd-flash-error" role="alert">Set <code>ANTHROPIC_API_KEY</code> (or your provider's key) in your <code>.env</code> to get suggestions.</div>
  @endunless

  @if ($ideas->isEmpty())
    <div class="vd-card vd-empty">
      <strong>No ideas yet</strong>
      <p style="margin:0">Click "Suggest videos" and the AI reads your routes and lists the videos worth making first. It takes a few seconds and costs about a cent or two. The ideas are saved here.</p>
    </div>
  @else
    <p class="vd-small vd-muted" style="margin:0 0 12px">
      {{ $ideas->count() }} {{ \Illuminate\Support\Str::plural('idea', $ideas->count()) }}, last suggested {{ $latestAt?->diffForHumans() }}. The AI cost ${{ number_format($spent, 3) }} for them in total.
      "Suggest more" adds new ideas and keeps these; "Replace all" starts over.
    </p>
    <div class="vd-grid" style="grid-template-columns:repeat(auto-fill,minmax(min(320px,100%),1fr))">
      @foreach ($ideas as $idea)
        <article class="vd-card vd-stack-sm" style="display:flex;flex-direction:column">
          <p class="vd-eyebrow" style="margin:0">{{ $idea->video_id ? 'Made into a video' : 'Idea' }}</p>
          <h2 style="margin:0">{{ $idea->title }}</h2>
          <p style="margin:0">{{ $idea->prompt }}</p>
          @if ($idea->why)<p class="vd-small vd-muted" style="margin:0">{{ $idea->why }}</p>@endif
          <div class="vd-actions" style="margin-top:4px">
            <span class="vd-pill">{{ $styles[$idea->style]->name ?? $idea->style }}</span>
            <span class="vd-pill vd-num">{{ gmdate('i:s', $idea->target_seconds) }}</span>
            @if ($idea->start_path)<span class="vd-pill vd-mono">{{ $idea->start_path }}</span>@endif
          </div>
          <div class="vd-actions" style="margin-top:auto;padding-top:12px">
            @if ($idea->video)
              <a class="vd-btn" href="{{ route('video-demo.studio.show', $idea->video) }}">Open the video</a>
            @endif
            <a class="vd-btn {{ $idea->video ? '' : 'vd-btn-primary' }}" href="{{ route('video-demo.studio.create', array_filter([
                'prompt' => $idea->prompt,
                'title' => $idea->title,
                'start_path' => $idea->start_path,
                'target_seconds' => $idea->target_seconds,
                'style' => $idea->style,
                'idea' => $idea->id,
            ])) }}">{{ $idea->video ? 'Make it again' : 'Make this video' }}</a>
            <form method="post" action="{{ route('video-demo.studio.suggestions.destroy', $idea) }}">@csrf @method('delete')
              <button class="vd-btn vd-btn-ghost vd-btn-danger" aria-label="Delete the idea {{ $idea->title }}">Delete</button>
            </form>
          </div>
        </article>
      @endforeach
    </div>
  @endif

  <x-slot:scripts>
    <script>
      document.getElementById('vd-suggest-form').addEventListener('submit', e => {
        const button = e.submitter || e.target.querySelector('.vd-btn-primary');
        // Keep the clicked button's value, which disabling would drop
        const keep = document.createElement('input');
        keep.type = 'hidden'; keep.name = button.name; keep.value = button.value;
        e.target.append(keep);
        e.target.querySelectorAll('button').forEach(b => { b.disabled = true; });
        (button.querySelector('span') || button).textContent = button.dataset.busy;
        button.querySelector('svg')?.classList.add('vd-spin');
      });
    </script>
  </x-slot:scripts>
</x-video-demo::layout>
