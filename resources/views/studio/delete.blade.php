<x-video-demo::layout title="Delete videos" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => 'index'])
  </x-slot:nav>

  @php($count = $videos->count())
  @php($used = $videos->filter(fn ($v) => $v->isPublished() || ! empty($embeds[$v->slug])))

  <section class="vd-hero">
    <div>
      <p class="vd-eyebrow">Studio</p>
      <h1>Delete {{ $count === 1 ? 'this video' : "these $count videos" }}?</h1>
      <p class="vd-sub">Their recordings are deleted, and published ones are taken out of the library. This can't be undone. What they cost so far isn't refunded.</p>
    </div>
  </section>

  @if ($used->isNotEmpty())
    <div class="vd-flash vd-flash-warn" role="alert">
      <strong>{{ $used->count() === 1 ? '1 of them is in use.' : $used->count().' of them are in use.' }}</strong>
      Pages that show a deleted video show nothing in its place, and links to it in the library stop working. Remove the tags listed below from your pages, or unpublish instead to keep the recording.
    </div>
  @endif

  <form method="post" action="{{ route('video-demo.studio.bulk') }}" class="vd-stack">
    @csrf
    <input type="hidden" name="action" value="delete">

    @foreach ($videos as $video)
      <input type="hidden" name="videos[]" value="{{ $video->id }}">
      <div class="vd-card vd-stack-sm">
        <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
          <a href="{{ route('video-demo.studio.show', $video) }}"><strong>{{ $video->title ?: \Illuminate\Support\Str::limit($video->prompt, 80) }}</strong></a>
          <span class="vd-small vd-muted">{{ $video->status->label() }} · ${{ number_format($video->cost, 4) }} spent</span>
        </div>

        @if ($video->status->isWorking())
          <p class="vd-small" style="margin:0;color:var(--vd-warn)">Still being made, so it will be kept. Stop it first.</p>
        @endif

        @if ($video->isPublished())
          <p class="vd-small" style="margin:0;color:var(--vd-warn)">Published in the {{ $video->visibility === 'private' ? 'private' : 'public' }} library: it will be taken out.</p>
        @endif

        @if (! empty($embeds[$video->slug]))
          <div class="vd-small" style="color:var(--vd-warn)">Shown on {{ count($embeds[$video->slug]) === 1 ? 'a page' : count($embeds[$video->slug]).' places' }} in your code:</div>
          <ul class="vd-issues">
            @foreach ($embeds[$video->slug] as $embed)
              <li><span class="vd-mono">{{ $embed['file'] }}:{{ $embed['line'] }}</span><span class="vd-detail">{{ $embed['text'] }}</span></li>
            @endforeach
          </ul>
        @elseif (! $video->isPublished())
          <p class="vd-small vd-muted" style="margin:0">Not published, and not shown on any page in your code.</p>
        @endif
      </div>
    @endforeach

    <div class="vd-actions">
      <button class="vd-btn vd-btn-danger-solid">{{ $count === 1 ? 'Delete video' : "Delete $count videos" }}</button>
      <a class="vd-btn vd-btn-ghost" href="{{ url()->previous() !== url()->current() ? url()->previous() : route('video-demo.studio.index') }}">Cancel</a>
    </div>
  </form>
</x-video-demo::layout>
