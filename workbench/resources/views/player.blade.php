{{--
  For trying, by hand, the players that pause while the viewer is away and
  start where they left off (video-demo.library.player.pause_when_away and
  remember_position, here turned on per player). Play a video, then switch to
  another tab, or scroll it out of view, and come back; or stop partway and
  load the page again. The log lists what each player did.
--}}
<x-workbench::layout title="Player">
  <section class="hero">
    <p class="eyebrow">Player</p>
    <h1>Pauses while you're away</h1>
    <p class="lead">Play either video, then switch to another tab or scroll it out of view. It pauses, and plays again from the same place when you come back. Pausing it yourself stops that until you play it again. Stop partway and load the page again: it continues from there.</p>
  </section>

  <div class="grid">
    <section class="card">
      <h2>Blade player</h2>
      <p class="sub">&lt;x-video-demo::player slug="post-a-job" pause-when-away remember-position /&gt;</p>
      <x-video-demo::player slug="post-a-job" pause-when-away remember-position />
    </section>

    <aside class="card">
      <h2>What happened</h2>
      <p class="sub">The players' video-demo:auto-pause and video-demo:auto-resume events.</p>
      <ol class="tips" id="log"></ol>
    </aside>
  </div>

  {{-- Room to scroll the first player out of view. --}}
  <div style="height:120vh"></div>

  <section class="card" style="max-width:720px">
    <h2>Web component, for frontends that aren't Blade</h2>
    <p class="sub">&lt;video-demo-player slug="post-a-job" pause-when-away="true" remember-position="true"&gt;</p>
    <video-demo-player slug="post-a-job" pause-when-away="true" remember-position="true"></video-demo-player>
  </section>

  <script src="{{ route('video-demo.public.embed-script') }}" defer></script>
  <script>
    const log = (player, event) => {
      const item = document.createElement('li');
      const reason = event.detail && event.detail.reason ? ` (${event.detail.reason})` : '';
      item.textContent = `${new Date().toLocaleTimeString()}: ${player}, ${event.type.replace('video-demo:', '')}${reason}`;
      document.getElementById('log').prepend(item);
    };
    ['video-demo:auto-pause', 'video-demo:auto-resume'].forEach(name => document.addEventListener(name, event => {
      log(event.target.localName === 'video-demo-player' ? 'Web component' : 'Blade player', event);
    }));
  </script>
</x-workbench::layout>
