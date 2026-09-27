/**
 * identity_api: address generator in Settings > Preferences > Shop address API.
 * Works in any browser, e.g. Firefox on iOS where extensions are not available.
 */
// API URL: resolve a relative setting against the browser's address (correct also
// behind proxies) and check that the API answers there (rewrite rule set up) and
// gets the Authorization header: a made-up token must be "invalid", not missing.
window.rcmail && rcmail.addEventListener('init', function () {
  var input = document.getElementById('identityapi-url');
  var check = document.getElementById('identityapi-urlcheck');
  if (!input || !check || !window.fetch) {
    return;
  }
  var url = new URL(input.getAttribute('data-api'), location.href).href;
  input.value = url;
  fetch(url + 'v1/me', {
    credentials: 'omit', redirect: 'manual', cache: 'no-store',
    headers: { Authorization: 'Bearer 0.00000000.check' }
  }).then(function (res) {
    if (res.status !== 401 || !/problem\+json/.test(res.headers.get('Content-Type') || '')) {
      return 'apiunreachable';
    }
    return res.json().then(function (p) { return p.detail === 'no token' ? 'apiauthdropped' : 'ok'; });
  }).catch(function () { return 'apiunreachable'; }).then(function (state) {
    check.setAttribute('data-state', state);
    if (state !== 'ok') {
      check.textContent = rcmail.get_label(state, 'identity_api');
      check.className = 'hint text-danger';
    }
  });
});

window.rcmail && rcmail.addEventListener('init', function () {
  var shop = document.getElementById('identityapi-shop');
  var result = document.getElementById('identityapi-result');
  if (!shop || !result) {
    return;
  }

  function copyButton(email) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'button btn btn-secondary btn-sm';
    btn.textContent = rcmail.get_label('copy', 'identity_api');
    btn.addEventListener('click', function () {
      var done = function () { rcmail.display_message(rcmail.get_label('copied', 'identity_api'), 'confirmation'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(email).then(done, function () { select(email); });
      } else {
        select(email);
      }
    });
    return btn;
  }

  // fallback: show the address selected, so the user can copy it manually
  function select(email) {
    var input = document.createElement('input');
    input.value = email;
    input.readOnly = true;
    input.className = 'form-control';
    result.appendChild(input);
    input.focus();
    input.select();
  }

  function row(email, fresh) {
    var div = document.createElement('div');
    div.className = 'identityapi-address' + (fresh ? ' fresh' : '');
    div.style.margin = '.5em 0';
    div.style.wordBreak = 'break-all';
    var code = document.createElement('code');
    code.textContent = email;
    div.appendChild(code);
    div.appendChild(document.createTextNode(' '));
    div.appendChild(copyButton(email));
    return div;
  }

  function post(action) {
    var domain = document.getElementById('identityapi-domain');
    var lock = rcmail.set_busy(true, 'loading');
    rcmail.http_post(action, { _shop: shop.value, _domain: domain ? domain.value : '' }, lock);
  }

  document.getElementById('identityapi-create').addEventListener('click', function () {
    if (!shop.value.trim()) {
      shop.focus();
      return;
    }
    post('plugin.identity_api-create');
  });

  document.getElementById('identityapi-showlist').addEventListener('click', function () {
    if (!shop.value.trim()) {
      shop.focus();
      return;
    }
    post('plugin.identity_api-list');
  });

  // Enter creates an address instead of submitting the preferences form
  shop.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      document.getElementById('identityapi-create').click();
    }
  });

  rcmail.addEventListener('plugin.identity_api_created', function (data) {
    result.textContent = '';
    result.appendChild(row(data.email, true));
  });

  rcmail.addEventListener('plugin.identity_api_list', function (data) {
    result.textContent = '';
    if (!data.emails.length) {
      result.textContent = rcmail.get_label('noexisting', 'identity_api');
    }
    data.emails.forEach(function (email) {
      result.appendChild(row(email, false));
    });
  });
});
