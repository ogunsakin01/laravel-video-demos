{{--
  The video's plan, editable: reword captions and voice-over lines, fix a
  target, reorder, remove or add steps, then record it again for free.
  Expects $video and $devices. While the video is being made it is read-only.
--}}
@php
  $steps = old('steps') ? array_values(old('steps')) : ($video->plan['steps'] ?? []);
  $editable = ! $video->status->isWorking();
  $blank = fn (string $action) => ['action' => $action, 'title' => $action === 'card' ? 'New title card' : ($action === 'caption' ? 'New caption' : null)];
@endphp
<details class="vd-card vd-fold" id="vd-plan" data-fold="plan" @if ($errors->has('plan') || old('steps') || session('status') === 'Plan saved. Record it again to see the change.') open data-fold-keep @endif>
  <summary>
    <span class="vd-fold-title">
      <h2>Plan · {{ count($video->plan['steps'] ?? []) }} steps</h2>
      <span class="vd-fold-hint" data-closed-only>{{ \Illuminate\Support\Str::limit($video->plan['summary'] ?? '', 140) ?: ($editable ? 'Reword captions and voice-over lines, or change the steps, without the AI.' : 'The steps the video plays.') }}</span>
    </span>
  </summary>
  <div class="vd-fold-body">
  @if (! $editable)
    @if (! empty($video->plan['summary']))<p class="vd-small vd-muted" style="margin:0">{{ $video->plan['summary'] }}</p>@endif
    <ol class="vd-steps">
      @foreach ($video->plan['steps'] as $step)
        <li>
          <code>{{ $step['action'] }}</code>
          <span>
            @if (in_array($step['action'], ['caption', 'card']))
              “{{ $step['title'] }}”
            @elseif ($step['action'] === 'goto')
              <span class="vd-mono">{{ $step['url'] }}</span>
            @elseif ($step['action'] === 'sign_in')
              as {{ ($step['value'] ?? '') ?: 'the default user' }}, then <span class="vd-mono">{{ $step['url'] }}</span>
            @else
              {{ collect($step['target'] ?? [])->filter()->map(fn ($v, $k) => "$k: $v")->implode(', ') }}
              @if (! empty($step['value'])) → “{{ $step['value'] }}” @endif
            @endif
            @if (! empty($step['why']))<span class="vd-muted"> · {{ $step['why'] }}</span>@endif
          </span>
        </li>
      @endforeach
    </ol>
  @else
    <form method="post" action="{{ route('video-demo.studio.plan', $video) }}" class="vd-stack-sm" id="vd-plan-form">
      @csrf @method('put')
      <p class="vd-small vd-muted" style="margin:0">Fix a word, a voice-over line or a button name without asking the AI. Recording the edited plan is free.</p>
      <div class="vd-row">
        <div class="vd-field">
          <label for="plan-title">Title in the library</label>
          <input type="text" id="plan-title" name="title" value="{{ old('title', $video->plan['title'] ?? '') }}" maxlength="160">
        </div>
        <div class="vd-field">
          <label for="plan-summary">Summary</label>
          <input type="text" id="plan-summary" name="summary" value="{{ old('summary', $video->plan['summary'] ?? '') }}" maxlength="1000">
        </div>
      </div>

      <ol class="vd-plan-steps" data-steps>
        @foreach ($steps as $i => $step)
          @include('video-demo::studio.partials.plan-step', ['i' => $i, 'step' => $step, 'devices' => $devices])
        @endforeach
      </ol>

      @foreach (['caption', 'card', 'pause'] as $kind)
        <template data-step-template="{{ $kind }}">
          @include('video-demo::studio.partials.plan-step', ['i' => '__i__', 'step' => $blank($kind), 'devices' => $devices])
        </template>
      @endforeach

      <div class="vd-actions">
        <span class="vd-small vd-muted">Add at the end:</span>
        <button type="button" class="vd-btn vd-btn-sm" data-step-add="caption">Caption</button>
        <button type="button" class="vd-btn vd-btn-sm" data-step-add="card">Title card</button>
        <button type="button" class="vd-btn vd-btn-sm" data-step-add="pause">Pause</button>
      </div>

      @error('plan')<span class="vd-error" role="alert">{{ $message }}</span>@enderror
      @error('steps')<span class="vd-error" role="alert">{{ $message }}</span>@enderror
      @if ($video->plan_edited_at)
        <span class="vd-hint">Edited by hand {{ $video->plan_edited_at->diffForHumans() }}. A new plan from the AI starts from your edits.</span>
      @endif

      <div class="vd-actions">
        <button class="vd-btn" name="then" value="save">Save plan</button>
        <button class="vd-btn vd-btn-primary" name="then" value="record">Save and record it again (free)</button>
      </div>
    </form>
  @endif
  </div>
</details>

@if ($editable)
  @once
    <script>
      (() => {
        const form = document.getElementById('vd-plan-form');
        if (!form) return;
        const list = form.querySelector('[data-steps]');
        // Number the steps as they now stand, and name their fields in that order.
        const renumber = () => [...list.children].forEach((li, n) => {
          li.querySelector('[data-step-num]').textContent = n + 1;
          li.querySelectorAll('[name^="steps["]').forEach(el => { el.name = el.name.replace(/^steps\[[^\]]+\]/, 'steps[' + n + ']'); });
          li.querySelectorAll('[id^="plan-"]').forEach(el => {
            const id = el.id.replace(/^plan-[^-]+-/, 'plan-' + n + '-');
            form.querySelectorAll('label[for="' + el.id + '"]').forEach(l => { if (li.contains(l)) l.htmlFor = id; });
            el.id = id;
          });
          li.querySelector('[data-step-move="-1"]').disabled = n === 0;
          li.querySelector('[data-step-move="1"]').disabled = n === list.children.length - 1;
        });
        list.addEventListener('click', e => {
          const button = e.target.closest('button');
          const li = button && button.closest('[data-step]');
          if (!li) return;
          if (button.hasAttribute('data-step-remove')) {
            li.remove();
          } else if (button.dataset.stepMove === '-1' && li.previousElementSibling) {
            li.previousElementSibling.before(li);
          } else if (button.dataset.stepMove === '1' && li.nextElementSibling) {
            li.nextElementSibling.after(li);
          } else {
            return;
          }
          renumber();
          (li.isConnected ? button : list).focus?.();
        });
        form.querySelectorAll('[data-step-add]').forEach(button => button.addEventListener('click', () => {
          const template = form.querySelector('[data-step-template="' + button.dataset.stepAdd + '"]');
          list.append(template.content.cloneNode(true));
          renumber();
          list.lastElementChild.querySelector('input[type=text]')?.focus();
        }));
        form.addEventListener('submit', renumber);
        renumber();
      })();
    </script>
  @endonce
@endif
