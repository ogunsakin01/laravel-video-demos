@use('Ogunsakin01\VideoDemo\Enums\VideoStatus')
<x-video-demo::layout :title="$video->title ?: 'Video'" badge="Studio · local" :home="route('video-demo.studio.index')">
  <x-slot:nav>
    @include('video-demo::studio.partials.nav', ['active' => null])
  </x-slot:nav>

  <section class="vd-hero">
    @include('video-demo::partials.art', ['name' => 'play'])
    <div style="min-width:0">
      <p class="vd-eyebrow">Video</p>
      <h1>{{ $video->title ?: \Illuminate\Support\Str::limit($video->prompt, 70) }}</h1>
      <p class="vd-sub" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px">
        <span id="vd-status" aria-live="polite">@include('video-demo::studio.partials.status', ['status' => $video->status])</span>
        @if ($video->isPublished())
          <span class="vd-pill">{{ $video->visibility === 'public' ? 'Public' : 'Private' }} · published {{ $video->published_at->diffForHumans() }}</span>
          @if (config('video-demo.library.public.enabled') && $video->visibility === 'public')
            <a class="vd-small" href="{{ route('video-demo.public.show', $video->slug) }}">Open in library</a>
          @endif
        @endif
      </p>
      @if ($progress = \Ogunsakin01\VideoDemo\Studio\Progress::for($video))
        <div class="vd-progress" id="vd-progress" data-percent="{{ $progress['percent'] }}" data-remaining="{{ $progress['remaining'] }}">
          <div class="vd-progress-track" role="progressbar" aria-label="Progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}"><span style="width:{{ $progress['percent'] }}%"></span></div>
          <span class="vd-small vd-muted"><strong data-percent>{{ $progress['percent'] }}%</strong> <span data-stage>{{ $progress['stage'] ? ' · '.$progress['stage'] : '' }}</span> · <span data-left></span></span>
        </div>
      @endif
    </div>
    @if (in_array($video->status, [VideoStatus::Failed, VideoStatus::Stopped], true))
        @php($hasPlan = $video->hasPlan())
        <div class="vd-actions">
          <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
            <input type="hidden" name="mode" value="plan">
            <button class="vd-btn vd-btn-primary">{{ $hasPlan ? 'Try again with a new plan' : 'Try again' }}</button>
          </form>
          @if ($hasPlan)
            <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
              <input type="hidden" name="mode" value="record">
              <button class="vd-btn">Record the same plan again</button>
            </form>
          @endif
        </div>
    @endif
    @if ($video->status->isWorking())
      <form method="post" action="{{ route('video-demo.studio.stop', $video) }}" class="vd-actions" id="vd-stop">@csrf
        <button class="vd-btn vd-btn-danger" @disabled($video->stop_requested)>{{ $video->stop_requested ? 'Stopping…' : 'Stop' }}</button>
      </form>
    @endif
    @if ($video->isReady())
      <div class="vd-actions">
        @if ($video->isPublished())
          <form method="post" action="{{ route('video-demo.studio.unpublish', $video) }}">@csrf
            <button class="vd-btn">Unpublish</button>
          </form>
        @endif
        <form method="post" action="{{ route('video-demo.studio.publish', $video) }}">@csrf
          <button class="vd-btn vd-btn-primary">{{ $video->isPublished() ? 'Publish this version' : 'Approve and publish' }}</button>
        </form>
      </div>
    @endif
  </section>

  @if ($video->status->isWorking() || ($video->spots['status'] ?? null) === 'working')
    @include('video-demo::studio.partials.worker-warning')
  @endif
  @if ($queueWarning)
    <div class="vd-flash vd-flash-warn" role="status">{{ $queueWarning }}</div>
  @endif

  @if (! empty($video->issues))
    <div class="vd-flash vd-flash-error vd-stack-sm" role="alert" id="vd-issues">
      <strong>{{ count($video->issues) === 1 ? 'A page in your app has a problem' : count($video->issues).' pages in your app have problems' }}, so the video wasn't recorded</strong>
      <ul class="vd-issues">
        @foreach ($video->issues as $issue)
          <li>
            <span><span class="vd-mono">{{ $issue['path'] }}</span>: {{ $issue['problem'] }}</span>
            @if (! empty($issue['detail']))<span class="vd-detail">{{ $issue['detail'] }}</span>@endif
          </li>
        @endforeach
      </ul>
      @if ($video->sample_data)
        <span class="vd-small">The AI couldn't add the data {{ count($video->issues) === 1 ? 'this page needs' : 'these pages need' }} to the sample database. Fix {{ count($video->issues) === 1 ? 'it' : 'them' }} in your app, or say in the description what the user needs, then try again.</span>
      @else
        <span class="vd-small">No AI was spent on the broken {{ count($video->issues) === 1 ? 'page' : 'pages' }}. If {{ count($video->issues) === 1 ? 'it works' : 'they work' }} for users with the right data, such as a role, a permission or a finished profile, record on sample data instead: a copy of your database where the AI adds what the pages need. Your own data isn't touched. Otherwise, fix {{ count($video->issues) === 1 ? 'it' : 'them' }} in your app and try again.</span>
        @unless ($video->status->isWorking())
          <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
            <input type="hidden" name="mode" value="plan">
            <input type="hidden" name="sample_data" value="1">
            <button class="vd-btn vd-btn-primary">Try again on sample data</button>
          </form>
        @endunless
      @endif
    </div>
  @endif

  <div class="vd-split">
    <div class="vd-stack">
      @if ($video->hasRecording())
        @php($recorded = array_values(array_filter($video->recordedDevices(), fn ($d) => isset($devices[$d]))))
        @php($first = $video->primaryDevice())
        <video class="vd-video" id="vd-preview" controls preload="metadata"
          style="aspect-ratio:{{ $devices[$first]['width'] }}/{{ $devices[$first]['height'] }};max-height:78vh;margin:0 auto"
          poster="{{ route('video-demo.studio.file', [$video, $first, 'poster']) }}?v={{ $video->version }}"
          src="{{ route('video-demo.studio.file', [$video, $first, 'video']) }}?v={{ $video->version }}">
          @if (\Illuminate\Support\Facades\Storage::disk(config('video-demo.storage.work_disk'))->exists($video->workFile($first, 'subtitles')))
            <track kind="subtitles" srclang="{{ $video->languageCode() }}" label="Captions" src="{{ route('video-demo.studio.file', [$video, $first, 'subtitles']) }}?v={{ $video->version }}">
          @endif
        </video>
        @if (count($recorded) > 1)
          <div class="vd-segments" role="group" aria-label="Version">
            @foreach ($recorded as $key)
              <button type="button" class="vd-segment" aria-pressed="{{ $key === $first ? 'true' : 'false' }}"
                data-src="{{ route('video-demo.studio.file', [$video, $key, 'video']) }}?v={{ $video->version }}"
                data-poster="{{ route('video-demo.studio.file', [$video, $key, 'poster']) }}?v={{ $video->version }}"
                data-ratio="{{ $devices[$key]['width'] }}/{{ $devices[$key]['height'] }}">{{ $devices[$key]['label'] }} · {{ gmdate('i:s', (int) round($video->recordings[$key]['seconds'] ?? 0)) }}</button>
            @endforeach
          </div>
        @endif
      @else
        <div class="vd-card vd-empty">
          @if ($video->status->isWorking())
            <strong>Making the video</strong>
            This page updates as it goes.
          @else
            <strong>No recording yet</strong>
          @endif
        </div>
      @endif

      @if ($video->isFinished())
        @include('video-demo::studio.partials.spots')
      @endif

      @if (! $video->status->isWorking() && $video->hasPlan() && ($video->isReady() || $video->verify_result))
        @include('video-demo::studio.partials.check')
      @endif

      @if ($video->isFinished())
        @include('video-demo::studio.partials.downloads')
      @endif

      @if ($video->isPublished())
        @include('video-demo::studio.partials.watching')
      @endif

      @if ($video->source_video_id || ($video->isReady() && $video->hasPlan()) || $video->translations()->exists())
        @include('video-demo::studio.partials.translations')
      @endif

      @if ($video->error)
        <div class="vd-card vd-stack-sm" style="border-color:transparent;background:var(--vd-bad-bg)">
          <h2 style="color:var(--vd-bad);margin:0">What went wrong</h2>
          <p style="margin:0;color:var(--vd-bad);overflow-wrap:anywhere">{{ $video->error }}</p>
          @if (! empty($video->error_details['exception']))
            <div><button type="button" class="vd-btn" data-open-error>View full error</button></div>
            @include('video-demo::studio.partials.error-details')
          @endif
          @if ($video->status === VideoStatus::Failed)
            <p class="vd-small" style="margin:0;color:var(--vd-bad)">Trying again uses the same description, style, versions and model. To change any of them, use "Make it again" below.</p>
            @php($hasPlan = $video->hasPlan())
        <div class="vd-actions">
          <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
            <input type="hidden" name="mode" value="plan">
            <button class="vd-btn vd-btn-primary">{{ $hasPlan ? 'Try again with a new plan' : 'Try again' }}</button>
          </form>
          @if ($hasPlan)
            <form method="post" action="{{ route('video-demo.studio.retry', $video) }}">@csrf
              <input type="hidden" name="mode" value="record">
              <button class="vd-btn">Record the same plan again</button>
            </form>
          @endif
        </div>
          @endif
        </div>
      @endif

      @if ($video->hasPlan())
        @include('video-demo::studio.partials.plan-editor')
      @endif

      <details class="vd-card vd-fold" id="vd-details" data-fold="details" @if ($errors->has('title') || $errors->has('visibility')) open data-fold-keep @endif>
        <summary>
          <span class="vd-fold-title">
            <h2>Details</h2>
            <span class="vd-fold-hint" data-closed-only>{{ $video->title }} · {{ $video->visibility === 'public' ? 'Public' : 'Private (staff)' }} library</span>
          </span>
        </summary>
      <form method="post" action="{{ route('video-demo.studio.update', $video) }}" class="vd-fold-body vd-stack">
        @csrf @method('patch')
        <div class="vd-row">
          <div>
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required maxlength="160" value="{{ old('title', $video->title) }}">
          </div>
          <div>
            <label for="visibility">Library</label>
            <select id="visibility" name="visibility">
              <option value="public" @selected($video->visibility === 'public')>Public</option>
              <option value="private" @selected($video->visibility === 'private')>Private (staff)</option>
            </select>
          </div>
        </div>
        <div><button class="vd-btn">Save details</button></div>
      </form>
      </details>

      @unless ($video->status->isWorking())
        @php($hasPlan = $video->hasPlan())
        @php($currentStyle = $styles->first(fn ($s) => $s->key === ($video->style['key'] ?? null)) ?? $styles->first(fn ($s) => $s->name === ($video->style['name'] ?? null)))
        <details class="vd-card vd-fold" id="vd-again-fold" data-fold="again" @if ($errors->any() && old('prompt') !== null) open data-fold-keep @endif>
          <summary>
            <span class="vd-fold-title">
              <h2>Make it again</h2>
              <span class="vd-fold-hint">Change the length, versions, style or voice. Same plan: free. New plan: the AI makes one.</span>
            </span>
          </summary>
        <form method="post" action="{{ route('video-demo.studio.again', $video) }}" class="vd-fold-body" id="vd-again">@csrf

          <section class="vd-section">
            <div class="vd-field">
              <div class="vd-field-head">
                <label for="a-prompt">Description</label>
                <button type="button" class="vd-btn vd-btn-sm" id="a-improve" @disabled(! $hasKey)>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
                  <span>Improve with AI</span>
                </button>
              </div>
              <textarea id="a-prompt" name="prompt" required maxlength="4000" data-needs-plan>{{ old('prompt', $video->prompt) }}</textarea>
              <input type="text" id="a-wish" maxlength="1000" placeholder="What should be better? Optional" aria-label="What should be better" style="margin-top:8px">
              <span class="vd-ai-note" id="a-improve-note" aria-live="polite">Improve with AI rewrites it from how this video went. It only fills in the form.</span>
              <ul class="vd-ai-changes" id="a-improve-changes" hidden></ul>
            </div>

            <div class="vd-row">
              <div class="vd-field">
                <span class="vd-label" id="a-length-label">Length</span>
                <input type="hidden" name="target_seconds" id="a-seconds" value="{{ old('target_seconds', $video->target_seconds) }}" data-needs-plan>
                <div class="vd-segments" role="group" aria-labelledby="a-length-label">
                  @foreach ([30 => '30s', 45 => '45s', 60 => '1m', 90 => '1½m', 120 => '2m', 180 => '3m'] as $seconds => $label)
                    @if ($seconds <= config('video-demo.limits.max_seconds', 300))
                      <button type="button" class="vd-segment" data-target="a-seconds" data-value="{{ $seconds }}">{{ $label }}</button>
                    @endif
                  @endforeach
                </div>
              </div>
              <div class="vd-field">
                <span class="vd-label" id="a-speed-label">Speed</span>
                <input type="hidden" name="speed" id="a-speed" value="{{ old('speed', rtrim(rtrim(number_format($video->playbackSpeed(), 2), '0'), '.')) }}">
                <div class="vd-segments" role="group" aria-labelledby="a-speed-label">
                  @foreach (['1' => '1×', '1.25' => '1.25×', '1.5' => '1.5×', '2' => '2×', '3' => '3×'] as $value => $label)
                    <button type="button" class="vd-segment" data-target="a-speed" data-value="{{ $value }}">{{ $label }}</button>
                  @endforeach
                </div>
              </div>
            </div>

            <div class="vd-field">
              <span class="vd-label" id="a-devices-label">Versions</span>
              @include('video-demo::studio.partials.devices', ['devices' => $devices, 'selected' => old('devices', $video->deviceList()), 'labelledBy' => 'a-devices-label'])
              @error('devices')<span class="vd-error">Choose at least one version.</span>@enderror
            </div>
          </section>

          <section class="vd-section">
            <div class="vd-row">
              <div class="vd-field">
                <label for="a-record-as">Record as</label>
                <select id="a-record-as" name="record_as" data-needs-plan>
                  @foreach ($recordAs as $value => $label)
                    <option value="{{ $value }}" @selected((string) ($video->record_as ?? '') === (string) $value)>{{ $label }}</option>
                  @endforeach
                </select>
              </div>
              <div class="vd-field">
                <label for="a-start">Start page <span class="vd-optional">optional</span></label>
                <input type="text" id="a-start" name="start_path" value="{{ old('start_path', $video->start_path) }}" placeholder="/jobs" data-needs-plan>
              </div>
            </div>
            <div class="vd-field">
              <input type="hidden" name="sample_data" value="0">
              <label class="vd-toggle vd-toggle-wide">
                <input type="checkbox" name="sample_data" value="1" @checked($video->sample_data) data-needs-plan>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5.5" rx="7.5" ry="2.5"/><path d="M4.5 5.5v13c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5v-13M4.5 12c0 1.4 3.4 2.5 7.5 2.5s7.5-1.1 7.5-2.5"/></svg>
                <span class="vd-toggle-text"><span class="vd-toggle-name">Sample data</span><span class="vd-toggle-size">A throwaway copy of your database, filled for the video</span></span>
                <span class="vd-switch" aria-hidden="true"></span>
              </label>
            </div>
          </section>

          <section class="vd-section">
            <div class="vd-field">
              <label for="r-style">Style</label>
              <select id="r-style" name="style_id" data-needs-plan data-defaults-for="sound">
                @unless ($currentStyle)
                  <option value="" selected>{{ $video->style['name'] ?? 'Its current style' }} (as it was)</option>
                @endunless
                @foreach ($styles as $style)
                  <option value="{{ $style->id }}" data-direction="{{ $style->direction }}" data-voice="{{ $style->voice }}" data-music="{{ $style->music }}" @selected($currentStyle?->is($style))>{{ $style->name }}</option>
                @endforeach
              </select>
              <span class="vd-hint" id="r-style-hint">{{ $currentStyle?->direction }}</span>
            </div>

            @include('video-demo::studio.partials.sound', ['prefix' => 'a', 'voice' => old('voice', $video->voice), 'music' => old('music', $video->music), 'volume' => $video->music_volume, 'language' => old('language', $video->language), 'appLanguage' => $video->page_language])

            <details class="vd-more">
              <summary>More options <small>model, spending limit, steps</small></summary>
              <div class="vd-row">
                <div class="vd-field">
                  <label for="a-model">Model</label>
                  <select id="a-model" name="model" data-needs-plan>
                    @foreach ($models as $id => $label)
                      <option value="{{ $id }}" @selected($video->model === $id)>{{ $label }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="vd-field">
                  <label for="a-effort">Effort</label>
                  <select id="a-effort" name="effort" data-needs-plan>
                    @foreach ($efforts as $effort)
                      <option value="{{ $effort }}" @selected($video->effort === $effort)>{{ ucfirst($effort) }}</option>
                    @endforeach
                  </select>
                </div>
              </div>
              <div class="vd-row">
                <div class="vd-field">
                  <label for="a-max-cost">Spending limit <span class="vd-optional">US $</span></label>
                  <input type="number" id="a-max-cost" name="max_cost" step="0.01" min="0.01" max="100" inputmode="decimal" value="{{ old('max_cost', $video->spendLimit()) }}">
                </div>
                <div class="vd-field">
                  <label for="a-max-steps">Max steps <span class="vd-optional">optional</span></label>
                  <input type="number" id="a-max-steps" name="max_steps" min="3" max="250" inputmode="numeric" value="{{ old('max_steps', $video->max_steps) }}" placeholder="No limit" data-needs-plan>
                </div>
              </div>
            </details>
          </section>

          <section class="vd-section">
            <div class="vd-field">
              <input type="hidden" name="new_plan" value="{{ $hasPlan ? 0 : 1 }}">
              <label class="vd-toggle vd-toggle-wide">
                <input type="checkbox" name="new_plan" value="1" id="a-new-plan" @checked(! $hasPlan) @disabled(! $hasPlan)>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg>
                <span class="vd-toggle-text"><span class="vd-toggle-name">Ask the AI for a new plan</span>
                  <span class="vd-toggle-size" id="a-new-plan-hint">{{ $hasPlan ? 'Off: the same plan, recorded again for free. Turns on when you change what the AI plans from.'.($video->plan_edited_at ? ' A new plan starts from your edits to the plan.' : '') : 'There is no plan yet, so the AI makes one.' }}</span></span>
                <span class="vd-switch" aria-hidden="true"></span>
              </label>
            </div>
            @if ($errors->any())<span class="vd-error">{{ $errors->first() }}</span>@endif
            <button class="vd-btn vd-btn-primary vd-btn-block" id="a-submit">{{ $hasPlan ? 'Record it again (free)' : 'Make it again with a new plan' }}</button>
          </section>
        </form>
        </details>
      @endunless
    </div>

    <aside class="vd-stack">
      <div class="vd-card">
        <p class="vd-eyebrow">AI cost</p>
        @if (\Ogunsakin01\VideoDemo\Ai\Pricing::known($video->model))
          <div class="vd-figure" id="vd-cost">${{ number_format($video->cost, 4) }}</div>
        @else
          <div class="vd-figure">Unknown</div>
          <p class="vd-small vd-muted" style="margin:4px 0 0">No prices for this model in the config.</p>
        @endif
        <dl style="margin:12px 0 0">
          @if ($video->estimate_low !== null)
            <div class="vd-stat"><dt>Estimate</dt><dd class="vd-num">${{ number_format($video->estimate_low, 2) }} – ${{ number_format($video->estimate_high, 2) }}</dd></div>
          @endif
          <div class="vd-stat"><dt>Length</dt><dd class="vd-num">{{ $video->duration_seconds ? round($video->duration_seconds).'s of '.$video->target_seconds.'s target' : $video->target_seconds.'s target' }}</dd></div>
        </dl>
        <details class="vd-settings" data-fold="settings">
          <summary>How it was made</summary>
        <dl style="margin:4px 0 0">
          @if (! empty($video->style['name']))
            <div class="vd-stat"><dt>Style</dt><dd>{{ $video->style['name'] }}</dd></div>
          @endif
          <div class="vd-stat"><dt>Versions</dt><dd>{{ collect($video->deviceList())->map(fn ($d) => $devices[$d]['label'] ?? $d)->implode(', ') }}</dd></div>
          <div class="vd-stat"><dt>Speed</dt><dd class="vd-num">{{ rtrim(rtrim(number_format($video->playbackSpeed(), 2), '0'), '.') }}×</dd></div>
          <div class="vd-stat"><dt>Spending limit</dt><dd class="vd-num">${{ number_format($video->spendLimit(), 2) }}</dd></div>
          @if ($video->max_steps)
            <div class="vd-stat"><dt>Max steps</dt><dd class="vd-num">{{ $video->max_steps }}</dd></div>
          @endif
          <div class="vd-stat"><dt>Recorded as</dt><dd>{{ $video->record_as_label ?: \Ogunsakin01\VideoDemo\Recording\RecordAs::label($video->record_as) }}</dd></div>
          <div class="vd-stat"><dt>Data</dt><dd>{{ $video->sample_data ? 'Sample data' : 'Your local database' }}</dd></div>
          <div class="vd-stat"><dt>Language</dt><dd>{{ \Ogunsakin01\VideoDemo\Support\Language::name($video->languageCode()) }}{{ $video->language ? '' : ', as the app' }}</dd></div>
          <div class="vd-stat"><dt>Voice-over</dt><dd>{{ \Ogunsakin01\VideoDemo\Voice\Voices::label($video->voice) }}</dd></div>
          <div class="vd-stat"><dt>Music</dt><dd>{{ \Ogunsakin01\VideoDemo\Voice\Music::label($video->music) }}</dd></div>
          <div class="vd-stat"><dt>Model</dt><dd>{{ \Ogunsakin01\VideoDemo\Ai\Pricing::model($video->model)['label'] ?? $video->model }}, {{ $video->effort }}</dd></div>
        </dl>
        </details>
      </div>

      @if ($video->isPublished() && ($entry = app(\Ogunsakin01\VideoDemo\Library\Manifest::class)->find($video->slug, 'private')))
        @include('video-demo::partials.embed', ['entry' => $entry])
      @elseif ($video->isReady())
        <div class="vd-card">
          <h2>Embed</h2>
          <p class="vd-small vd-muted" style="margin:0">Publish the video to get code that shows it on any page.</p>
        </div>
      @endif

      <details class="vd-card vd-fold" id="vd-log-fold" data-fold="progress" @if ($video->status->isWorking()) open data-fold-keep @endif>
        <summary>
          <span class="vd-fold-title">
            <h2>Progress</h2>
            <span class="vd-fold-hint" data-closed-only>{{ \Illuminate\Support\Arr::last($video->log ?? [])['message'] ?? 'Nothing yet.' }}</span>
          </span>
        </summary>
        <div class="vd-fold-body">
        <ul class="vd-log" id="vd-log" role="log">
          @foreach (array_slice($video->log ?? [], -40) as $entry)
            <li><time datetime="{{ $entry['at'] }}">{{ \Illuminate\Support\Carbon::parse($entry['at'])->format('H:i:s') }}</time><span>{{ $entry['message'] }}</span></li>
          @endforeach
        </ul>
        </div>
      </details>

      @unless ($video->status->isWorking())
        {{-- A page first says where the video is used: the library, and pages that embed it. --}}
        <a class="vd-btn vd-btn-ghost vd-btn-danger vd-btn-block" href="{{ route('video-demo.studio.delete', ['videos' => [$video->id]]) }}">Delete video…</a>
      @endunless
    </aside>
  </div>

  <script>
    // Folded sections: each viewer's last choice is remembered, but a section
    // that needs attention (working, failed, a form error) stays open, as does
    // one the address points to or one whose form was just sent.
    (() => {
      const store = (key, open) => { try { localStorage.setItem('vd-fold:' + key, open ? '1' : '0'); } catch {} };
      const folds = [...document.querySelectorAll('details[data-fold]')];
      folds.forEach(fold => {
        if (!fold.hasAttribute('data-fold-keep')) {
          try {
            const saved = localStorage.getItem('vd-fold:' + fold.dataset.fold);
            if (saved !== null) fold.open = saved === '1';
          } catch {}
        }
        fold.querySelector(':scope > summary').addEventListener('click', () => setTimeout(() => store(fold.dataset.fold, fold.open)));
        fold.addEventListener('submit', () => store(fold.dataset.fold, true));
      });
      const reveal = () => {
        const target = location.hash.length > 1 && document.getElementById(decodeURIComponent(location.hash.slice(1)));
        const fold = target && target.closest('details[data-fold]');
        if (fold && !fold.open) { fold.open = true; target.scrollIntoView(); }
      };
      reveal();
      addEventListener('hashchange', reveal);
    })();
    // Log times in the viewer's own time zone, as the live updates show them.
    document.querySelectorAll('#vd-log time[datetime]').forEach(t => { t.textContent = new Date(t.dateTime).toTimeString().slice(0, 8); });
    document.querySelectorAll('[data-src]').forEach(b => b.addEventListener('click', () => {
      const v = document.getElementById('vd-preview');
      v.poster = b.dataset.poster; v.src = b.dataset.src; v.style.aspectRatio = b.dataset.ratio;
      document.querySelectorAll('[data-src]').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
    }));
    // "Make it again": the segment pickers, the style's direction, and the new-plan toggle.
    (() => {
      const form = document.getElementById('vd-again');
      if (!form) return;
      const toggle = document.getElementById('a-new-plan');
      const submit = document.getElementById('a-submit');
      const label = () => { submit.textContent = toggle.checked ? 'Make it again with a new plan' : 'Record it again (free)'; };
      const needsPlan = () => { if (!toggle.disabled && !toggle.checked) { toggle.checked = true; label(); } };
      toggle.addEventListener('change', label);
      form.querySelectorAll('[data-needs-plan]').forEach(el => el.addEventListener(el.tagName === 'TEXTAREA' || el.type === 'text' || el.type === 'number' ? 'input' : 'change', needsPlan));

      form.querySelectorAll('.vd-segments').forEach(group => {
        const buttons = group.querySelectorAll('[data-target]');
        const input = document.getElementById(buttons[0].dataset.target);
        const mark = () => buttons.forEach(b => b.setAttribute('aria-pressed', String(Number(b.dataset.value) === Number(input.value))));
        buttons.forEach(b => b.addEventListener('click', () => {
          input.value = b.dataset.value;
          mark();
          if (input.hasAttribute('data-needs-plan')) needsPlan();
        }));
        mark();
      });

      const style = document.getElementById('r-style');
      style.addEventListener('change', () => { document.getElementById('r-style-hint').textContent = style.selectedOptions[0]?.dataset.direction || ''; });

      // Improve with AI: fill the form from how this video went. Nothing starts until the form is sent.
      const improve = document.getElementById('a-improve');
      const improveLabel = improve.querySelector('span');
      const note = document.getElementById('a-improve-note');
      const changes = document.getElementById('a-improve-changes');
      const money = n => '$' + (n < 0.1 ? n.toFixed(3) : n.toFixed(2));
      improve.addEventListener('click', async () => {
        improve.disabled = true;
        improveLabel.textContent = 'Improving…';
        improve.setAttribute('aria-busy', 'true');
        note.removeAttribute('data-error');
        try {
          const res = await fetch(@json(route('video-demo.studio.improve', $video)), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value },
            body: JSON.stringify({ wish: document.getElementById('a-wish').value, model: form.elements.model.value }),
          });
          const d = await res.json();
          if (!res.ok) throw new Error(d.error || d.message || 'Something went wrong.');
          form.elements.prompt.value = d.prompt;
          if (d.start_path) form.elements.start_path.value = d.start_path;
          const seconds = document.getElementById('a-seconds');
          seconds.value = d.target_seconds;
          form.querySelectorAll('[data-target="a-seconds"]').forEach(b => b.setAttribute('aria-pressed', String(Number(b.dataset.value) === Number(d.target_seconds))));
          needsPlan();
          changes.replaceChildren(...d.changes.map(c => Object.assign(document.createElement('li'), { textContent: c })));
          changes.hidden = d.changes.length === 0;
          note.textContent = (d.cost_known ? 'Improved by AI for ' + money(d.cost) : 'Improved by AI') + ', with a ' + d.target_seconds + '-second target. Edit anything, then press the button below.';
          const cost = document.getElementById('vd-cost');
          if (cost && d.cost_known) cost.textContent = '$' + Number(d.video_cost).toFixed(4);
        } catch (e) {
          note.textContent = e.message;
          note.setAttribute('data-error', '');
        } finally {
          improve.disabled = false;
          improveLabel.textContent = 'Improve with AI';
          improve.removeAttribute('aria-busy');
        }
      });
    })();
  </script>

  @if ($video->status->isWorking())
    <x-slot:scripts>
      <script>
        (() => {
          const url = @json(route('video-demo.studio.status', $video));
          const log = document.getElementById('vd-log');
          const status = document.querySelector('#vd-status [data-status]');
          const cost = document.getElementById('vd-cost');
          const time = iso => new Date(iso).toTimeString().slice(0, 8);

          // The progress bar: the server's estimate every couple of seconds, a
          // countdown in between. It never goes backwards, even when a repair
          // sends the video back to planning.
          const bar = document.getElementById('vd-progress');
          let shown = bar ? Number(bar.dataset.percent) : 0, remaining = bar ? Number(bar.dataset.remaining) : 0;
          const left = secs => secs < 45 ? 'less than a minute left' : secs < 90 ? 'about a minute left'
            : secs < 3600 ? `about ${Math.round(secs / 60)} min left` : `about ${Math.floor(secs / 3600)} h ${Math.round((secs % 3600) / 60)} min left`;
          const paint = () => {
            if (!bar) return;
            bar.querySelector('[role=progressbar] span').style.width = shown + '%';
            bar.querySelector('[role=progressbar]').setAttribute('aria-valuenow', shown);
            bar.querySelector('[data-percent]').textContent = shown + '%';
            bar.querySelector('[data-left]').textContent = left(Math.max(0, remaining));
          };
          const showProgress = p => {
            shown = Math.max(shown, p.percent);
            remaining = p.remaining;
            if (bar) bar.querySelector('[data-stage]').textContent = p.stage ? ' · ' + p.stage : '';
            paint();
          };
          paint();
          setInterval(() => { if (remaining > 5) { remaining--; paint(); } }, 1000);
          const tick = async () => {
            try {
              const s = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
              status.textContent = s.label;
              const stop = document.querySelector('#vd-stop button');
              if (stop && s.stopping) { stop.disabled = true; stop.textContent = 'Stopping…'; }
              cost.textContent = '$' + Number(s.cost).toFixed(4);
              if (s.progress) showProgress(s.progress);
              log.replaceChildren(...s.log.map(e => {
                const li = document.createElement('li');
                const t = document.createElement('time'); t.textContent = time(e.at);
                const m = document.createElement('span'); m.textContent = e.message;
                li.append(t, m);
                return li;
              }));
              log.scrollTop = log.scrollHeight;
              if (!s.working) { location.reload(); return; }
            } catch {}
            setTimeout(tick, 2000);
          };
          setTimeout(tick, 1500);
        })();
      </script>
    </x-slot:scripts>
  @endif
</x-video-demo::layout>
