{{--
  Says when no queue worker is running, so videos would wait forever. It
  checks again every few seconds and goes away once a worker starts.
--}}
@if (\Ogunsakin01\VideoDemo\Support\Worker::warning())
  <div class="vd-flash vd-flash-warn" role="status" data-worker-warning>
    No queue worker is running, so new videos wait in the queue and nothing is made. Start one in a terminal:
    <code style="white-space:nowrap">php artisan video-demo:work</code>
  </div>
  @once
    <script>
      (() => {
        const note = document.querySelector('[data-worker-warning]');
        const check = setInterval(async () => {
          try {
            const s = await (await fetch(@json(route('video-demo.studio.spending')), { headers: { Accept: 'application/json' } })).json();
            if (s.worker !== false) { note.remove(); clearInterval(check); }
          } catch {}
        }, 5000);
      })();
    </script>
  @endonce
@endif
