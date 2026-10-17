<?php

// A separate frontend (php -S with this router): one page for every path,
// which keeps its token in localStorage, sends it to the API at API_URL,
// and goes to /login in the browser when it has none.
$api = json_encode(getenv('API_URL'));
echo <<<HTML
<!doctype html>
<html lang="en">
<title>Jobs</title>
<body>
<script>
const api = $api;
const token = localStorage.getItem('token');
const headers = token ? { Authorization: 'Bearer ' + token } : {};
const show = html => { document.body.innerHTML = html; };
(async () => {
  if (location.pathname === '/jobs/new') {
    show('<h1>Post a job</h1><form><label for="t">Job title</label><input id="t"><label for="l">Location</label><input id="l"><button>Publish job</button></form>');
    // An optional endpoint that answers 404, and Laravel Precognition's live
    // validation, which answers 422 while the form isn't finished: both normal.
    fetch(api + '/api/optional', { headers });
    t.oninput = () => fetch(api + '/api/jobs', { method: 'POST', headers: { ...headers, Precognition: 'true', 'Content-Type': 'application/json' }, body: JSON.stringify({ title: t.value }) });
    document.querySelector('form').onsubmit = async e => {
      e.preventDefault();
      const res = await fetch(api + '/api/jobs', { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify({ title: t.value, location: l.value }) });
      if (res.ok) { const job = await res.json(); show('<p>Published ' + job.title + ' in ' + job.location + '</p>'); }
    };
    return;
  }
  if (location.pathname === '/save') {
    show('<h1>Settings</h1><button>Save</button>');
    document.querySelector('button').onclick = async () => {
      const res = await fetch(api + '/api/save', { method: 'POST', headers });
      if (res.ok) show('<p>Saved</p>');
    };
    return;
  }
  if (location.pathname === '/reports') {
    await fetch(api + '/api/reports', { headers });
    return show('<h1>Reports</h1>');
  }
  const res = await fetch(api + '/api/me', { headers });
  if (res.status === 401) {
    history.replaceState({}, '', '/login');
    return show('<h1>Sign in</h1>');
  }
  const me = await res.json();
  show('<h1>Dashboard</h1><button>Signed in as ' + me.name + '</button>');
})();
</script>
</body>
</html>
HTML;
