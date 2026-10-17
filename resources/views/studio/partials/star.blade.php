{{--
  Once a video has been published, a small note asks for a star on GitHub.
  It is hidden until the page checks it wasn't dismissed in this browser,
  and starring or dismissing it hides it for good. Nothing is tracked.
--}}
@if (\Ogunsakin01\VideoDemo\Models\Video::query()->whereNotNull('published_at')->exists())
  <div class="vd-flash vd-star" role="status" data-star-note hidden>
    <span>If Laravel Video Demos saved you time, a star on GitHub helps other Laravel developers find it.</span>
    <span class="vd-star-actions">
      <a class="vd-btn vd-btn-sm" href="{{ \Ogunsakin01\VideoDemo\VideoDemo::GITHUB_URL }}" target="_blank" rel="noopener" data-star-hide>Star on GitHub</a>
      <button type="button" class="vd-btn vd-btn-sm vd-btn-ghost" data-star-hide>Not now</button>
    </span>
  </div>
  @once
    <script>
      (() => {
        const note = document.querySelector('[data-star-note]');
        const key = 'vd-star-note';
        try { if (localStorage.getItem(key)) return; } catch {}
        note.hidden = false;
        note.querySelectorAll('[data-star-hide]').forEach(el => el.addEventListener('click', () => {
          note.hidden = true;
          try { localStorage.setItem(key, '1'); } catch {}
        }));
      })();
    </script>
  @endonce
@endif
