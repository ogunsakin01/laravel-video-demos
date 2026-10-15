<x-video-demo::layout :title="$query !== '' ? 'Search' : null" :badge="$area === 'private' ? 'Staff' : null" :home="route('video-demo.'.$area.'.index')">
  <section class="vd-hero">
    @include('video-demo::partials.art', ['name' => 'book'])
    <div>
      <p class="vd-eyebrow">Help</p>
      <h1>{{ $query !== '' ? 'Videos about “'.$query.'”' : 'How-to videos' }}</h1>
      <p class="vd-sub">Short walkthroughs of how to get things done.</p>
    </div>
    <x-video-demo::search :area="$area" :value="$query" />
  </section>

  @if (session('video-demo.requested'))
    <div class="vd-flash" role="status">Thanks. The team will see that a video on this would help.</div>
  @endif

  @if (empty($videos))
    <div class="vd-card vd-empty">
      @include('video-demo::partials.art', ['name' => 'book', 'class' => 'vd-empty-art'])
      <strong>{{ $query !== '' ? 'No video covers that yet' : 'No videos yet' }}</strong>
      @if ($query !== '' && config('video-demo.library.accept_requests') && ! session('video-demo.requested'))
        <p style="margin:0 0 16px">Ask for one and the team will see it.</p>
        <form method="post" action="{{ route('video-demo.'.$area.'.request') }}">
          @csrf
          <input type="hidden" name="question" value="{{ $query }}">
          <input type="hidden" name="page_url" value="{{ url()->previous() }}">
          <button class="vd-btn vd-btn-primary">Ask for a video on this</button>
        </form>
      @endif
    </div>
  @else
    @php($manifest = app(\Ogunsakin01\VideoDemo\Library\Manifest::class))
    <div class="vd-grid">
      @foreach ($videos as $video)
        <a class="vd-tile vd-lift" href="{{ route('video-demo.'.$area.'.show', $video['slug']) }}">
          <div class="vd-tile-media">
            <img src="{{ $manifest->url($video, 'poster') }}" alt="" loading="lazy">
            <span class="vd-tile-time">{{ gmdate('i:s', (int) round($video['duration'] ?? 0)) }}</span>
          </div>
          <div class="vd-tile-body">
            <div class="vd-tile-title">{{ $video['title'] }}</div>
            @if ($area === 'private' && ($video['visibility'] ?? 'public') === 'private')
              <span class="vd-pill" style="margin-top:6px">Staff only</span>
            @endif
          </div>
        </a>
      @endforeach
    </div>
  @endif

  @if ($area === 'private' && $requests->isNotEmpty())
    <div class="vd-card" style="margin-top:20px">
      <h2>Asked for by users</h2>
      <ul class="vd-log">
        @foreach ($requests as $request)
          <li><time>{{ $request->created_at->format('j M') }}</time><span>{{ $request->question }}</span></li>
        @endforeach
      </ul>
    </div>
  @endif
</x-video-demo::layout>
