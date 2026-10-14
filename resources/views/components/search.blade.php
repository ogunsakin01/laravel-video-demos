{{--
  A search box that opens the video library:
  <x-video-demo::search placeholder="How do I…" />
--}}
@props(['area' => 'public', 'value' => '', 'placeholder' => 'Search videos, e.g. “post a job”'])
<form method="get" action="{{ route('video-demo.'.$area.'.index') }}" role="search" {{ $attributes->merge(['class' => 'vd-search']) }}>
  <label class="vd-sr" for="vd-q">Search videos</label>
  <input type="search" id="vd-q" name="q" value="{{ $value }}" placeholder="{{ $placeholder }}">
  <button class="vd-btn">Search</button>
</form>
