{{--
  Says what must be fixed before videos can be made (the checks of
  php artisan video-demo:doctor), each with how to fix it. Loaded after the
  page, as some checks start programs, and shown only when something fails.
--}}
<div class="vd-flash vd-flash-error vd-setup" role="alert" data-setup hidden>
  <strong data-setup-title></strong>
  <ul data-setup-list></ul>
  <div class="vd-setup-actions">
    <button type="button" class="vd-btn vd-btn-sm" data-setup-again>Check again</button>
    <span class="vd-small">Or run <code>php artisan video-demo:doctor</code>.</span>
  </div>
</div>
@once
  <script>
    (() => {
      const box = document.querySelector('[data-setup]');
      const show = async fresh => {
        try {
          const res = await fetch(@json(route('video-demo.studio.setup')) + (fresh ? '?fresh=1' : ''), { headers: { Accept: 'application/json' } });
          const { problems } = await res.json();
          box.hidden = !problems.length;
          if (!problems.length) return;
          box.querySelector('[data-setup-title]').textContent = problems.length === 1 ? 'One thing to fix before videos can be made' : problems.length + ' things to fix before videos can be made';
          const list = box.querySelector('[data-setup-list]');
          list.replaceChildren(...problems.map(p => {
            const li = document.createElement('li');
            const what = document.createElement('span');
            what.textContent = p.check + ': ' + p.found + '.';
            li.append(what);
            if (p.fix) { const fix = document.createElement('span'); fix.className = 'vd-setup-fix'; fix.textContent = p.fix; li.append(fix); }
            return li;
          }));
        } catch {}
      };
      box.querySelector('[data-setup-again]').addEventListener('click', e => { e.target.setAttribute('aria-busy', 'true'); show(true).finally(() => e.target.removeAttribute('aria-busy')); });
      show(false);
    })();
  </script>
@endonce
