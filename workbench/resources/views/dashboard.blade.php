<x-workbench::layout title="Jobs">
  <section class="hero">
    <p class="eyebrow">Jobs</p>
    <h1>Open jobs</h1>
    <p class="lead">Three roles are taking applications.</p>
    <div class="actions" style="margin-top:20px">
      <a class="btn" href="/jobs/create">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        Post a job
      </a>
    </div>
  </section>

  <div class="grid">
    <section class="card">
      <h2>Listings</h2>
      <p class="sub">Sorted by newest first.</p>
      <table>
        <thead><tr><th>Role</th><th>Location</th><th>Applicants</th></tr></thead>
        <tbody>
          <tr><td><strong>Senior Laravel Developer</strong></td><td class="muted">Remote</td><td><span class="pill">12</span></td></tr>
          <tr><td><strong>Product Designer</strong></td><td class="muted">London</td><td><span class="pill">8</span></td></tr>
          <tr><td><strong>Support Lead</strong></td><td class="muted">Lagos</td><td><span class="pill">21</span></td></tr>
        </tbody>
      </table>
    </section>

    <aside class="card">
      <h2>New to posting jobs?</h2>
      <p class="sub">Watch the short walkthrough.</p>
      {{-- The package's components, dropped into the app's own page --}}
      <x-video-demo::player slug="post-a-job" style="width:100%;border-radius:10px;background:#0e0e0d;display:block;border:1px solid #e5e5e5" />
      <x-video-demo::search placeholder="How do I…" />
    </aside>
  </div>
</x-workbench::layout>
