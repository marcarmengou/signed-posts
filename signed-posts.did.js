(function () {
  'use strict';

  // --- Small helpers (URL-safe Base64, Base58btc, multibase/multicodec) ---
  function b64urlToBytes(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    const pad = '='.repeat((4 - (s.length % 4)) % 4);
    const bin = atob(s + pad);
    const arr = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    return arr;
  }
  function bytesToUtf8(bytes) {
    return new TextDecoder().decode(bytes);
  }
  function utf8ToBytes(str) {
    return new TextEncoder().encode(str);
  }

  // Simple Base58btc decoder (for did:key multibase 'z...')
  const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
  const ALPHA_MAP = (() => {
    const m = new Map();
    for (let i = 0; i < ALPHABET.length; i++) m.set(ALPHABET[i], i);
    return m;
  })();
  function base58btcDecode(s) {
    // s has no leading 'z' here
    let bytes = [0];
    for (let i = 0; i < s.length; i++) {
      const c = s[i];
      const val = ALPHA_MAP.get(c);
      if (val === undefined) throw new Error('Invalid base58 character');
      let carry = val;
      for (let j = 0; j < bytes.length; j++) {
        const x = bytes[j] * 58 + carry;
        bytes[j] = x & 0xff;
        carry = x >> 8;
      }
      while (carry > 0) {
        bytes.push(carry & 0xff);
        carry >>= 8;
      }
    }
    // handle leading zeros
    for (let k = 0; k < s.length && s[k] === '1'; k++) bytes.push(0);
    return new Uint8Array(bytes.reverse());
  }

  // Multicodec Ed25519 public key prefix 0xED 0x01
  function stripEd25519Multicodec(bytes) {
    if (bytes.length < 2) return null;
    if (bytes[0] === 0xED && bytes[1] === 0x01) {
      return bytes.slice(2);
    }
    // If no multicodec prefix, assume raw
    return bytes;
  }

  // --- Badge helpers (copied minimal bits to avoid dependency on PGP script) ---
  function ensureBadgeNotInsideLinks() {
    var badges = document.querySelectorAll('.wppgps-author-badge');
    if (!badges || !badges.length) return;
    badges.forEach(function (badge) {
      try {
        var anchor = badge.closest('a');
        if (anchor && anchor.contains(badge) && anchor.parentNode) {
          if (anchor.nextSibling) {
            anchor.parentNode.insertBefore(badge, anchor.nextSibling);
          } else {
            anchor.parentNode.appendChild(badge);
          }
        }
      } catch (e) {}
    });
  }
  function replaceBadgeTokens(postId, token) {
    if (!token) return;
    var walker = document.createTreeWalker(
      document.body,
      NodeFilter.SHOW_TEXT,
      {
        acceptNode: function (node) {
          if (!node.nodeValue || node.nodeValue.indexOf(token) === -1) {
            return NodeFilter.FILTER_SKIP;
          }
          return NodeFilter.FILTER_ACCEPT;
        }
      }
    );
    var nodes = [], n;
    while ((n = walker.nextNode())) nodes.push(n);
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i], value = node.nodeValue, idx;
      while ((idx = value.indexOf(token)) !== -1) {
        var before = value.slice(0, idx), after = value.slice(idx + token.length);
        node.nodeValue = before;
        var tmp = document.createElement('span');
        tmp.innerHTML = '' +
          '<span class="wppgps-author-badge" data-post-id="' + String(postId) + '">' +
          '<span class="dashicons dashicons-shield wppgps-author-shield wppgps-author-shield--pending" aria-hidden="true"></span>' +
          '<span class="wppgps-author-fp" aria-live="polite" aria-atomic="true" hidden>' +
          '<span class="wppgps-author-fp-text">Signed - KeyID: </span>' +
          '<span class="wppgps-author-fp-value">••••••</span>' +
          '</span>' +
          '</span>';
        var badgeEl = tmp.firstChild;
        if (node.parentNode) {
          node.parentNode.insertBefore(badgeEl, node.nextSibling);
          var afterNode = document.createTextNode(after);
          node.parentNode.insertBefore(afterNode, badgeEl.nextSibling);
          node = afterNode;
          value = after;
        } else {
          break;
        }
      }
    }
  }
  function setBadgeState(state, keyText) {
    var postId = (typeof wppgpsData !== 'undefined' && wppgpsData.postId) ? wppgpsData.postId : 0;
    var badge = document.querySelector('.wppgps-author-badge[data-post-id="' + postId + '"]');
    if (!badge) return;
    var shield = badge.querySelector('.wppgps-author-shield');
    var fpWrap = badge.querySelector('.wppgps-author-fp');
    var fpText = badge.querySelector('.wppgps-author-fp-text');
    var fpVal  = badge.querySelector('.wppgps-author-fp-value');
    if (!shield) return;
    shield.classList.remove('wppgps-author-shield--pending','wppgps-author-shield--valid','wppgps-author-shield--invalid','wppgps-author-shield--error');
    if (state === 'valid') {
      shield.classList.add('wppgps-author-shield--valid');
      if (fpText) fpText.textContent = 'Signed - KeyID: ';
      if (fpVal && keyText) fpVal.textContent = keyText;
      if (fpWrap) fpWrap.hidden = false;
    } else if (state === 'invalid') {
      shield.classList.add('wppgps-author-shield--invalid');
      if (fpText) fpText.textContent = 'Invalid signature - KeyID: ';
      if (fpVal && keyText) fpVal.textContent = keyText;
      if (fpWrap) fpWrap.hidden = false;
    } else if (state === 'error') {
      shield.classList.add('wppgps-author-shield--error');
      if (fpText) fpText.textContent = 'Verification error: ';
      if (fpVal && keyText) fpVal.textContent = keyText;
      if (fpWrap) fpWrap.hidden = false;
    } else {
      shield.classList.add('wppgps-author-shield--pending');
    }
  }

  // --- UI helpers for the bottom status card ---
  function setStatus(cssClass, text) {
    var resultDiv = document.getElementById('pgp-verification-result');
    if (!resultDiv) return;
    resultDiv.className = 'pgp-status-container ' + cssClass;
    var span = resultDiv.querySelector('.pgp-status-text') || resultDiv.firstChild;
    if (span) span.textContent = text;
  }
  function setMethodText(txt) {
    var el = document.getElementById('pgp-method-value');
    if (el) el.textContent = txt;
  }
  function setSourceText(txt) {
    var el = document.getElementById('pgp-key-url-link');
    if (el) el.textContent = txt;
  }
  function setResultDetails(txt) {
    var el = document.getElementById('pgp-result-details');
    if (el) el.textContent = txt;
  }

  // --- DID document fetch / parsing ---
  async function fetchDidWebDocument(did) {
    // did:web:example.com OR did:web:example.com:user:alice
    var id = did.slice('did:web:'.length).split(':').join('/');
    var url = 'https://' + id + '/.well-known/did.json';
    var res = await fetch(url, { mode: 'cors' });
    if (!res.ok) throw new Error('Failed to fetch did.json (' + res.status + ')');
    return res.json();
  }
  function extractEd25519FromVerificationMethod(vm) {
    // Prefer publicKeyMultibase (multicodec), else JWK OKP
    if (vm.publicKeyMultibase) {
      var mb = vm.publicKeyMultibase;
      if (mb[0] === 'z') {
        var raw = base58btcDecode(mb.slice(1));
        return stripEd25519Multicodec(raw);
      }
    }
    if (vm.publicKeyJwk && vm.publicKeyJwk.kty === 'OKP' && vm.publicKeyJwk.crv === 'Ed25519' && vm.publicKeyJwk.x) {
      return b64urlToBytes(vm.publicKeyJwk.x);
    }
    return null;
  }

  // --- Verify detached JWS (EdDSA) with b64=false, crit:["b64"] ---
  async function verifyDetachedJWSEd25519(jws, payloadBytes, publicKeyRaw) {
    // Parse compact form: BASE64URL(header).<payload not b64>.BASE64URL(signature)
    var p = jws.split('.');
    if (p.length !== 3) throw new Error('Invalid compact JWS');
    var h64 = p[0], dotOrPayload = p[1], s64 = p[2];
    // per RFC 7797 detached, second part in compact string should be empty; we will rebuild the signing input
    if (dotOrPayload !== '') {
      // Some tools might still put empty, enforce detached
      // We still verify against detached construction
    }
    var header = JSON.parse(bytesToUtf8(b64urlToBytes(h64)));
    if (header.alg !== 'EdDSA') throw new Error('Unsupported alg (expected EdDSA)');
    if (header.b64 !== false || !Array.isArray(header.crit) || header.crit.indexOf('b64') === -1) {
      throw new Error('JWS must be detached with {"b64": false, "crit": ["b64"]}');
    }

    // Signing input is: ASCII(BASE64URL(ProtectedHeader)) + "." + payload (raw bytes)
    var signingInput = new Uint8Array(h64.length + 1 + payloadBytes.length);
    signingInput.set(utf8ToBytes(h64), 0);
    signingInput.set(utf8ToBytes('.'), h64.length);
    signingInput.set(payloadBytes, h64.length + 1);

    var signature = b64urlToBytes(s64);

    // WebCrypto Ed25519 verify
    if (!('crypto' in self) || !crypto.subtle) {
      throw new Error('WebCrypto not available');
    }

    // import raw 32-byte Ed25519 public key
    if (publicKeyRaw.length !== 32) {
      throw new Error('Invalid Ed25519 public key length');
    }
    var key = await crypto.subtle.importKey(
      'raw',
      publicKeyRaw,
      { name: 'Ed25519' },
      false,
      ['verify']
    );

    var ok = await crypto.subtle.verify(
      { name: 'Ed25519' },
      key,
      signature,
      signingInput
    );

    return { ok, kid: header.kid || null };
  }

  async function resolvePublicKeyFromDID(cfg) {
    if (cfg.method === 'did:key') {
      if (!cfg.did || !cfg.did.startsWith('did:key:')) throw new Error('Invalid did:key');
      var mb = cfg.did.slice('did:key:'.length);
      if (mb[0] !== 'z') throw new Error('Only multibase base58btc is supported');
      var raw = base58btcDecode(mb.slice(1));
      var pub = stripEd25519Multicodec(raw);
      if (!pub || pub.length !== 32) throw new Error('Unsupported did:key material');
      return { publicKey: pub, sourceText: cfg.did };
    } else if (cfg.method === 'did:web') {
      if (!cfg.did || !cfg.did.startsWith('did:web:')) throw new Error('Invalid did:web');
      var doc = await fetchDidWebDocument(cfg.did);
      var vmList = Array.isArray(doc.verificationMethod) ? doc.verificationMethod : [];
      var targetKid = null;
      try {
        // kid might be included in header; we will set it later if present
        var header = JSON.parse(bytesToUtf8(b64urlToBytes((cfg.signature || '').split('.')[0] || '')));
        targetKid = header.kid || null;
      } catch (e) {}
      var vm = null;
      if (targetKid) {
        vm = vmList.find(function (v) { return v.id === targetKid; }) || null;
      }
      if (!vm && vmList.length) vm = vmList[0];

      if (!vm) throw new Error('No verificationMethod found in did.json');

      var pub = extractEd25519FromVerificationMethod(vm);
      if (!pub || pub.length !== 32) throw new Error('Unsupported did:web key material');

      // Build a nice source string
      var idPart = cfg.did.slice('did:web:'.length).split(':').join('/');
      var src = 'https://' + idPart + '/.well-known/did.json';
      return { publicKey: pub, sourceText: src, kid: vm.id || null };
    } else {
      throw new Error('Unsupported DID method');
    }
  }

  // --- Main init ---
  function init() {
    var cfg = (typeof wppgpsData !== 'undefined') ? wppgpsData : {};
    var postId = cfg.postId || 0;
    var token  = cfg.badgeToken || ('[[WPPGPS_BADGE:' + postId + ']]');
    replaceBadgeTokens(postId, token);
    ensureBadgeNotInsideLinks();

    if (!cfg.signature || !cfg.did || !cfg.method) {
      // Nothing to verify, but we still placed the badge out of links
      return;
    }

    // Update method & source placeholders
    setMethodText('Verified in your browser with DID (Ed25519 JWS)');
    setSourceText(cfg.did);

    // Canonical message from PHP
    var message = cfg.message || '';
    var payload = new TextEncoder().encode(message);

    setStatus('pgp-status-pending', '🔍 Resolving DID...');
    setBadgeState('pending');

    (async function () {
      try {
        var { publicKey, sourceText, kid } = await resolvePublicKeyFromDID(cfg);
        if (sourceText) setSourceText(sourceText);

        setStatus('pgp-status-pending', '🔏 Verifying signature...');
        var res = await verifyDetachedJWSEd25519(cfg.signature, payload, publicKey);

        var keyLabel = (res.kid || kid || cfg.did || '').toString();
        if (res.ok) {
          setStatus('pgp-status-correct', '✅ VERIFICATION CORRECT');
          setResultDetails('Valid signature. KeyID: ' + keyLabel);
          setBadgeState('valid', keyLabel);
        } else {
          setStatus('pgp-status-incorrect', '❌ VERIFICATION INCORRECT');
          setResultDetails('Invalid signature. KeyID: ' + keyLabel);
          setBadgeState('invalid', keyLabel);
        }
        ensureBadgeNotInsideLinks();
      } catch (err) {
        setStatus('pgp-status-error', '⚠️ ERROR verifying signature');
        setResultDetails((err && err.message) ? err.message : String(err));
        setBadgeState('error', (err && err.message) ? err.message : 'Error');
        ensureBadgeNotInsideLinks();
      }
    })();
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }
})();
