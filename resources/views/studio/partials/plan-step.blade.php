{{--
  One step of the plan editor. Expects $i (its index, or "__i__" in the
  template for new steps), $step and $devices. Fields are named steps[$i][…];
  the editor renumbers them in order when the form is sent.
--}}
@php
  $action = $step['action'] ?? 'caption';
  $target = is_array($step['target'] ?? null) ? $step['target'] : [];
  $usesTarget = in_array($action, ['click', 'hover', 'check', 'type', 'select', 'scroll', 'wait'], true);
  $name = fn (string $field) => "steps[$i][$field]";
  $id = fn (string $field) => "plan-$i-$field";
  $summary = match (true) {
      in_array($action, ['caption', 'card'], true) => $step['title'] ?? '',
      $action === 'goto' => $step['url'] ?? '',
      $action === 'sign_in' => (($step['value'] ?? '') ?: 'default user').' → '.($step['url'] ?? ''),
      $action === 'pause' => '',
      default => collect($target)->filter()->except('frame')->map(fn ($v, $k) => "$k: $v")->implode(', '),
  };
  $valueLabel = ['type' => 'Text typed', 'select' => 'Option', 'press' => 'Key', 'sign_in' => 'Type of user (empty for the default user)'][$action] ?? null;
@endphp
<li class="vd-plan-step" data-step>
  <div class="vd-plan-step-head">
    <span class="vd-plan-step-num" data-step-num></span>
    <code>{{ $action }}</code>
    <span class="vd-plan-step-sum vd-small">{{ $summary }}</span>
    <span class="vd-plan-step-tools">
      <button type="button" class="vd-btn vd-btn-sm vd-btn-ghost" data-step-move="-1" aria-label="Move up" title="Move up"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
      <button type="button" class="vd-btn vd-btn-sm vd-btn-ghost" data-step-move="1" aria-label="Move down" title="Move down"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M19 12l-7 7-7-7"/></svg></button>
      <button type="button" class="vd-btn vd-btn-sm vd-btn-ghost vd-btn-danger" data-step-remove aria-label="Remove step" title="Remove step"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </span>
  </div>

  <input type="hidden" name="{{ $name('action') }}" value="{{ $action }}">
  <input type="hidden" name="{{ $name('why') }}" value="{{ $step['why'] ?? '' }}">

  <div class="vd-plan-step-fields">
    @if (in_array($action, ['caption', 'card'], true))
      <div class="vd-field">
        <label for="{{ $id('title') }}">Heading</label>
        <input type="text" id="{{ $id('title') }}" name="{{ $name('title') }}" value="{{ $step['title'] ?? '' }}" maxlength="200" required>
      </div>
      <div class="vd-field">
        <label for="{{ $id('text') }}">Sentence <span class="vd-optional">optional</span></label>
        <input type="text" id="{{ $id('text') }}" name="{{ $name('text') }}" value="{{ $step['text'] ?? '' }}" maxlength="400">
      </div>
    @elseif (in_array($action, ['goto', 'sign_in'], true))
      <div class="vd-field">
        <label for="{{ $id('url') }}">{{ $action === 'sign_in' ? 'Page to open once signed in' : 'Page' }}</label>
        <input type="text" id="{{ $id('url') }}" name="{{ $name('url') }}" value="{{ $step['url'] ?? '' }}" maxlength="500" class="vd-mono" placeholder="/jobs" required>
      </div>
    @endif
    @if ($valueLabel)
      <div class="vd-field">
        <label for="{{ $id('value') }}">{{ $valueLabel }}</label>
        <input type="text" id="{{ $id('value') }}" name="{{ $name('value') }}" value="{{ $step['value'] ?? '' }}" maxlength="2000">
      </div>
    @endif
    <div class="vd-field">
      <label for="{{ $id('say') }}">Voice-over line <span class="vd-optional">optional</span></label>
      <input type="text" id="{{ $id('say') }}" name="{{ $name('say') }}" value="{{ $step['say'] ?? '' }}" maxlength="400" placeholder="Silent">
    </div>
  </div>

  <details class="vd-plan-step-more">
    <summary class="vd-small">More</summary>
    <div class="vd-plan-step-fields">
      @if ($usesTarget)
        <fieldset class="vd-plan-target">
          <legend class="vd-small">The element it uses <span class="vd-optional">fill in one way to find it</span></legend>
          @foreach (\Ogunsakin01\VideoDemo\Recording\Plan::TARGET_FIELDS as $field)
            <div class="vd-field">
              <label for="{{ $id('target-'.$field) }}">{{ ['role' => 'Role', 'name' => 'Name', 'label' => 'Field label', 'placeholder' => 'Placeholder', 'text' => 'Text', 'selector' => 'CSS selector', 'frame' => 'Inside the iframe'][$field] }}</label>
              <input type="text" id="{{ $id('target-'.$field) }}" name="steps[{{ $i }}][target][{{ $field }}]" value="{{ $target[$field] ?? '' }}" maxlength="500" @class(['vd-mono' => in_array($field, ['selector', 'frame'], true)])>
            </div>
          @endforeach
        </fieldset>
      @endif
      <div class="vd-row">
        <div class="vd-field">
          <label for="{{ $id('chapter') }}">Starts a chapter <span class="vd-optional">optional</span></label>
          <input type="text" id="{{ $id('chapter') }}" name="{{ $name('chapter') }}" value="{{ $step['chapter'] ?? '' }}" maxlength="120">
        </div>
        <div class="vd-field">
          <label for="{{ $id('ms') }}">{{ in_array($action, ['caption', 'card', 'pause'], true) ? 'Shown for' : 'Pause after' }} <span class="vd-optional">ms</span></label>
          <input type="number" id="{{ $id('ms') }}" name="{{ $name('ms') }}" value="{{ $step['ms'] ?? '' }}" min="0" max="60000" step="100" inputmode="numeric" placeholder="Default">
        </div>
      </div>
      @if (count($devices) > 1)
        <div class="vd-field">
          <span class="vd-label">Only on <span class="vd-optional">none ticked: every version</span></span>
          <div class="vd-actions">
            @foreach ($devices as $key => $device)
              <label class="vd-check-row"><input type="checkbox" name="steps[{{ $i }}][only_on][]" value="{{ $key }}" @checked(in_array($key, (array) ($step['only_on'] ?? []), true))> {{ $device['label'] }}</label>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  </details>
</li>
