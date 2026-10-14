{{-- The studio's menu, the same on every studio page. $active: index, ideas, examples, styles, create --}}
@php($active = $active ?? null)
<a class="vd-btn vd-btn-ghost" href="{{ route('video-demo.studio.index') }}" @if ($active === 'index') aria-current="page" @endif>All videos</a>
<a class="vd-btn vd-btn-ghost" href="{{ route('video-demo.studio.suggestions.index') }}" @if ($active === 'ideas') aria-current="page" @endif>Ideas</a>
@if (\Ogunsakin01\VideoDemo\Studio\Examples::enabled())
  <a class="vd-btn vd-btn-ghost" href="{{ route('video-demo.studio.examples') }}" @if ($active === 'examples') aria-current="page" @endif>Examples</a>
@endif
<a class="vd-btn vd-btn-ghost" href="{{ route('video-demo.studio.styles.index') }}" @if ($active === 'styles') aria-current="page" @endif>Styles</a>
@if (config('video-demo.library.public.enabled'))
  <a class="vd-btn vd-btn-ghost" href="{{ route('video-demo.public.index') }}" target="_blank" rel="noopener">
    View library
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" style="width:13px;height:13px"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
    <span class="vd-sr">(opens in a new tab)</span>
  </a>
@endif
<a class="vd-btn vd-btn-primary" href="{{ route('video-demo.studio.create') }}" @if ($active === 'create') aria-current="page" @endif>New video</a>
