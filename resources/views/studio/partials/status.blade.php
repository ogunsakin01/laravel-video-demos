@php($class = $status->isWorking() ? 'working' : $status->value)
<span class="vd-status vd-status-{{ $class }}" data-status>{{ $status->label() }}</span>
