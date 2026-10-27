/**
 * MFA helper functions for Playwright tests
 */

import { createHmac } from 'node:crypto';

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/**
 * RFC 6238 TOTP (SHA-1, 6 digits, 30 second step) for a base32 secret.
 *
 * @param {string} secret - Base32 secret as shown on the MFA setup page
 * @param {number} offsetSteps - Time steps to shift, to step over a period boundary
 * @returns {string} Six digit code
 */
export function totp(secret, offsetSteps = 0) {
  let bits = '';
  for (const char of secret.replace(/=+$/, '').replace(/\s+/g, '').toUpperCase()) {
    bits += BASE32_ALPHABET.indexOf(char).toString(2).padStart(5, '0');
  }
  const key = Buffer.from(
    bits.match(/.{8}/g).map(byte => parseInt(byte, 2))
  );

  const counter = Math.floor(Date.now() / 30000) + offsetSteps;
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(counter));

  const hmac = createHmac('sha1', key).update(message).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const value = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];

  return String(value % 1000000).padStart(6, '0');
}
