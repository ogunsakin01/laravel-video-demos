{{--
  "Other languages": the video's versions in other languages, and a form to
  add one from its plan. On a translation, a link back to the original.
  Expects $video.
--}}
@php
  use Ogunsakin01\VideoDemo\Support\Language;
  use Ogunsakin01\VideoDemo\Voice\Voices;
  $translations = $video->source_video_id ? collect() : $video->translations()->orderBy('language')->get();
  $taken = $translations->map(fn ($t) => Language::base($t->languageCode()))->push(Language::base($video->languageCode()))->all();
  $choices = collect(Language::options())->reject(fn ($name, $code) => in_array(Language::base($code), $taken, true));
  $canAdd = ! $video->source_video_id && ! $video->status->isWorking() && ! empty($video->plan['steps']);
  $summary = $video->source_video_id
      ? 'The '.Language::name($video->languageCode()).' version of '.($video->source ? ($video->source->title ?: $video->source->slug) : 'a deleted video')
      : ($translations->isNotEmpty() ? $translations->map(fn ($t) => Language::name($t->languageCode()))->implode(', ') : 'None yet. The AI translates the words, for about a cent.');
  $hasErrors = $errors->has('language') || $errors->has('voice');
@endphp
<details class="vd-card vd-fold" id="vd-languages" data-fold="languages" @if ($hasErrors) open data-fold-keep @endif>
  <summary>
    <span class="vd-fold-title">
      <h2>Other languages</h2>
      <span class="vd-fold-hint">{{ $summary }}</span>
    </span>
  </summary>
  <div class="vd-fold-body vd-stack-sm">
  <div>
    @if ($video->source_video_id)
      <p class="vd-small vd-muted" style="margin:0">
        The {{ Language::name($video->languageCode()) }} version of
        @if ($video->source)
          <a href="{{ route('video-demo.studio.show', $video->source) }}">{{ $video->source->title ?: $video->source->slug }}</a>,
        @else
          a video that was deleted,
        @endif
        made from its plan. The player shows it to viewers in {{ Language::name($video->languageCode()) }}.
      </p>
    @else
      <p class="vd-small vd-muted" style="margin:0">The same video in another language, made from this plan: the AI translates the words, for about a cent, and it is recorded with your app in that language. The player shows each viewer their own.</p>
    @endif
  </div>

  @if ($translations->isNotEmpty())
    <ul class="vd-chapters">
      @foreach ($translations as $translation)
        <li>
          <a href="{{ route('video-demo.studio.show', $translation) }}">
            <span class="vd-num">{{ $translation->languageCode() }}</span>
            <span style="flex:1">{{ Language::name($translation->languageCode()) }}{{ $translation->title ? ' · '.$translation->title : '' }}</span>
            @include('video-demo::studio.partials.status', ['status' => $translation->status])
            @if ($translation->isPublished())<span class="vd-pill">Published</span>@endif
          </a>
        </li>
      @endforeach
    </ul>
  @endif

  @if ($canAdd && $choices->isNotEmpty())
    <form method="post" action="{{ route('video-demo.studio.translate', $video) }}" class="vd-stack-sm" data-busy-form>@csrf
      <div class="vd-row">
        <div class="vd-field">
          <label for="t-language">Language</label>
          <select id="t-language" name="language" required>
            @foreach ($choices as $code => $name)
              <option value="{{ $code }}" @selected(old('language') === $code)>{{ $name }}</option>
            @endforeach
          </select>
        </div>
        <div class="vd-field">
          <label for="t-voice">Voice-over</label>
          <select id="t-voice" name="voice">
            <option value="">No voice</option>
            @foreach (Voices::options() as $group => $voices)
              <optgroup label="{{ $group }}">
                @foreach ($voices as $key => $label)
                  <option value="{{ $key }}" data-speaks="{{ implode(' ', array_keys(array_filter(Language::options(), fn ($n, $c) => Voices::speaks($key, $c), ARRAY_FILTER_USE_BOTH))) }}" @selected(old('voice', $video->voice) === $key)>{{ $label }}</option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
        </div>
      </div>
      @error('language')<span class="vd-error" role="alert">{{ $message }}</span>@enderror
      @error('voice')<span class="vd-error" role="alert">{{ $message }}</span>@enderror
      <div><button class="vd-btn">Make it in this language</button></div>
    </form>
    <script>
      // Only voices that speak the chosen language can be picked.
      (() => {
        const language = document.getElementById('t-language'), voice = document.getElementById('t-voice');
        const base = code => code.split('-')[0];
        const sync = () => {
          const want = base(language.value);
          voice.querySelectorAll('option[data-speaks]').forEach(o => { o.disabled = !o.dataset.speaks.split(' ').some(c => base(c) === want); });
          if (voice.selectedOptions[0]?.disabled) voice.value = '';
        };
        language.addEventListener('change', sync);
        sync();
      })();
    </script>
  @endif
  </div>
</details>
