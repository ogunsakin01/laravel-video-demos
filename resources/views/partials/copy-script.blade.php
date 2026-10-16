{{-- Copy buttons: any element with data-copy copies its value. Included where they are used; added once a page. --}}
@once
  <script>
    document.addEventListener('click', async e => {
      const b = e.target.closest('[data-copy]');
      if (!b) return;
      try {
        await navigator.clipboard.writeText(b.dataset.copy);
      } catch {
        const t = document.createElement('textarea');
        t.value = b.dataset.copy; document.body.append(t); t.select(); document.execCommand('copy'); t.remove();
      }
      // The label is kept on the button, so pressing it again while it says Copied still comes back.
      b.dataset.label ??= b.textContent;
      b.textContent = 'Copied';
      clearTimeout(b.vdCopied);
      b.vdCopied = setTimeout(() => { b.textContent = b.dataset.label; }, 1500);
    });
  </script>
@endonce
