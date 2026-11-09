// Generate a real WebCrypto token to verify PHP/OpenSSL interoperability.
import { webcrypto } from 'node:crypto';

const { subtle } = webcrypto;
const encoder = new TextEncoder();
const encode = value => Buffer.from(value).toString('base64url');
const decode = value => Buffer.from(value, 'base64url');
const [publicKey, kid] = process.argv.slice(2);
if (!publicKey || !kid) process.exit(2);

const serverPublic = await subtle.importKey('spki', decode(publicKey), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
const ephemeral = await subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
const shared = new Uint8Array(await subtle.deriveBits({ name: 'ECDH', public: serverPublic }, ephemeral.privateKey, 256));
const user = webcrypto.getRandomValues(new Uint8Array(32));
const salt = webcrypto.getRandomValues(new Uint8Array(16));
const iv = webcrypto.getRandomValues(new Uint8Array(12));
const material = new Uint8Array(64);
material.set(shared);
material.set(user, 32);
const hkdf = await subtle.importKey('raw', material, 'HKDF', false, ['deriveKey']);
const aes = await subtle.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt, info: encoder.encode('mysqlapi-connection-v1') }, hkdf, { name: 'AES-GCM', length: 256 }, false, ['encrypt']);
const now = Math.floor(Date.now() / 1000);
const connection = { host: 'db.example.com', port: 3306, database: 'example', user: 'example', password: 'a-secret', socket: null, iat: now, exp: now + 86400 };
const ct = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv, additionalData: encoder.encode('mysqlapi|v1|' + kid), tagLength: 128 }, aes, encoder.encode(JSON.stringify(connection))));
const epk = await subtle.exportKey('spki', ephemeral.publicKey);
const token = 'mapi1.' + encode(encoder.encode(JSON.stringify({ v: 1, kid, epk: encode(epk), salt: encode(salt), iv: encode(iv), ct: encode(ct) })));
process.stdout.write(JSON.stringify({ token, key: encode(user) }));
