<x-workbench::layout title="Post a job">
  <section class="hero">
    <p class="eyebrow">Jobs</p>
    <h1>Post a job</h1>
    <p class="lead">Tell people what the role is about. It goes live on your careers page as soon as you publish it.</p>
  </section>

  <div class="grid">
    <form class="card" onsubmit="event.preventDefault(); document.getElementById('done').hidden = false;">
      <h2>Job details</h2>
      <p class="sub">Fields marked as required must be filled in before you publish.</p>

      <div class="field">
        <label for="title">Job title</label>
        <input type="text" id="title" placeholder="e.g. Laravel developer" required>
      </div>
      <div class="row">
        <div class="field">
          <label for="contract">Contract</label>
          <select id="contract">
            <option>Full time</option>
            <option>Part time</option>
            <option>Contract</option>
          </select>
        </div>
        <div class="field">
          <label for="location">Location</label>
          <select id="location">
            <option>London</option>
            <option>Lagos</option>
            <option>Remote</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="about">About the role</label>
        <textarea id="about" placeholder="What the person will do, and who they will work with"></textarea>
      </div>
      <div class="field">
        <label class="check"><input type="checkbox" id="remote"> Open to remote applicants</label>
      </div>
      <div class="actions">
        <button class="btn" type="submit">Publish job</button>
        <a class="btn btn-light" href="/">Cancel</a>
      </div>
      <div class="notice" id="done" role="status" hidden>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        Your job is live.
      </div>
    </form>

    <aside class="card">
      <h2>Tips for a good listing</h2>
      <p class="sub">Listings like this get twice as many applicants.</p>
      <ul class="tips">
        <li><b>1</b>Use the title people search for, not an internal one.</li>
        <li><b>2</b>Say whether the role is remote in the first line.</li>
        <li><b>3</b>Keep it under 300 words.</li>
      </ul>
    </aside>
  </div>
</x-workbench::layout>
