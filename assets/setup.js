'use strict';

const encoder = new TextEncoder();
const form = document.querySelector('#connection-form');
const output = document.querySelector('#output');
const message = document.querySelector('#message');
const generate = document.querySelector('#generate');

function encode64(bytes) {
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function decode64(value) {
  const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(binary, char => char.charCodeAt(0));
}

function setMessage(text, kind = '') {
  message.textContent = text;
  message.className = 'notice ' + kind;
}

form.addEventListener('submit', async event => {
  event.preventDefault();
  output.hidden = true;
  generate.disabled = true;
  setMessage('Gerando chaves e criptografando localmente…');
  try {
    if (!crypto.subtle) throw new Error('A criptografia exige HTTPS ou localhost.');
    const response = await fetch('?public-key', { cache: 'no-store' });
    if (!response.ok) throw new Error('O servidor ainda não tem uma chave de criptografia configurada.');
    const server = await response.json();
    if (server.version !== 1) throw new Error('Versão de criptografia incompatível.');
    const fields = new FormData(form);
    const days = Number(fields.get('days'));
    if (days > server.maxAgeDays) throw new Error('A validade excede o limite do servidor.');
    const now = Math.floor(Date.now() / 1000);
    const connection = {
      host: String(fields.get('host')).trim(),
      port: Number(fields.get('port')),
      database: String(fields.get('database')).trim(),
      user: String(fields.get('user')).trim(),
      password: String(fields.get('password')),
      socket: null,
      iat: now,
      exp: now + days * 86400
    };
    const serverPublic = await crypto.subtle.importKey('spki', decode64(server.publicKey), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
    const ephemeral = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
    const shared = new Uint8Array(await crypto.subtle.deriveBits({ name: 'ECDH', public: serverPublic }, ephemeral.privateKey, 256));
    const userKey = crypto.getRandomValues(new Uint8Array(32));
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const inputKey = new Uint8Array(64);
    inputKey.set(shared);
    inputKey.set(userKey, 32);
    const hkdfInput = await crypto.subtle.importKey('raw', inputKey, 'HKDF', false, ['deriveKey']);
    const aesKey = await crypto.subtle.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt, info: encoder.encode('mysqlapi-connection-v1') }, hkdfInput, { name: 'AES-GCM', length: 256 }, false, ['encrypt']);
    const encrypted = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: encoder.encode('mysqlapi|v1|' + server.kid), tagLength: 128 }, aesKey, encoder.encode(JSON.stringify(connection)));
    const ephemeralPublic = new Uint8Array(await crypto.subtle.exportKey('spki', ephemeral.publicKey));
    const token = 'mapi1.' + encode64(encoder.encode(JSON.stringify({ v: 1, kid: server.kid, epk: encode64(ephemeralPublic), salt: encode64(salt), iv: encode64(iv), ct: encode64(new Uint8Array(encrypted)) })));
    document.querySelector('#token').value = token;
    document.querySelector('#user-key').value = encode64(userKey);
    document.querySelector('#example').textContent = JSON.stringify({ token, key: encode64(userKey), sql: 'SELECT VERSION() AS version;', responseType: 'row' }, null, 2);
    form.reset();
    output.hidden = false;
    setMessage('Token gerado! Salve os dois valores em locais seguros.', 'success');
  } catch (error) {
    setMessage(error.message || 'Não foi possível gerar o token.', 'error');
  } finally {
    generate.disabled = false;
  }
});

document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
  const id = button.dataset.copy;
  const element = document.getElementById(id);
  try {
    await navigator.clipboard.writeText('value' in element ? element.value : element.textContent);
    setMessage('Copiado para a área de transferência.', 'success');
  } catch (_) {
    setMessage('Selecione e copie manualmente o conteúdo.', 'error');
  }
}));
