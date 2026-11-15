#xmlseclibs 

xmlseclibs is a library written in PHP for working with XML Encryption and Signatures.

The author of xmlseclibs is Rob Richards.

# Branches
Master is currently the only actively maintained branch.
* master: requires PHP version 8.0+
* 3.1: Added AES-GCM support requiring 7.1+
* 3.0: Removes mcrypt usage requiring 5.4+ (5.6.24+ recommended for security reasons)
* 2.0: Contains namespace support requiring 5.3+
* 1.4: Contains auto-loader support while also maintaining backwards compatiblity with the older 1.3 version using the xmlseclibs.php file. Supports PHP 5.2+

# Requirements

xmlseclibs requires PHP version 8.0 or greater. OpenSSL is optional (phpseclib is used for crypto).

## Security notes

* Prefer the safe-by-default verifier `verifyDocument()` (see below). It requires a caller-supplied (pinned) key, never derives the key from the document's `KeyInfo`, enforces an algorithm allowlist for both the `SignatureMethod` and every `DigestMethod`, and only reports success when every reference validated. It returns the validated nodes for you to operate on.
* If you use the low-level primitives directly, always check verification with a strict comparison: `$objDSig->verify($key) === 1`. A return of `-1` is an error and is truthy in boolean context.
* After `validateReference()`, use `getValidatedNodes()` and operate only on those nodes (especially for SAML / WS-Security). Do not re-select assertions by Id from the whole document.
* Do not trust a signing certificate from `KeyInfo` alone. Load and pin trusted keys yourself.
* By default `verifyDocument()` accepts only SHA-256/384/512 digests and RSA-SHA-256/384/512 (and RSA-PSS) and ECDSA-SHA-256/384/512 signatures. To interoperate with legacy peers, widen the sets explicitly, e.g. `$objDSig->allowedSignatureAlgorithms[] = XMLSecurityKey::RSA_SHA1;`. ECDSA keys are limited to the P-256, P-384 and P-521 curves (`XMLSecurityKey::EC_CURVE_OIDS`); to accept another curve, add it explicitly to `$objKey->allowedECCurves`.
* Prefer RSA-OAEP and AES-GCM for encryption. RSA-1.5 key transport is **denied by default** on decryption (Bleichenbacher risk); opt in with `$objenc->allowRSA15KeyTransport = true;` only for legacy interop. You can additionally pin exact algorithms via `$objenc->allowedKeyAlgorithms` / `$objenc->allowedDataAlgorithms` (presets: `XMLSecEnc::DEFAULT_KEY_ALGORITHMS` and `XMLSecEnc::DEFAULT_DATA_ALGORITHMS`, both authenticated/OAEP-only). Unauthenticated CBC modes remain available for interop but are malleable — prefer AES-GCM.
* XPath (`REC-xpath-19991116`) transforms are **rejected during verification by default**. They evaluate a document-supplied XPath expression in `validateReference()` before any signature crypto runs, so a crafted expression is a pre-authentication CPU denial-of-service — and the expression is arbitrary XPath by design, so it cannot be sanitized. SAML and WS-Security do not use them. Set `$objDSig->allowXPathTransforms = true` only if you must verify signatures that legitimately rely on XPath transforms and you trust the source. Signing is unaffected. When enabled, the count/namespace caps below still apply.

* XPath transforms (once enabled) are capped by default (`maxXPathTransforms` / `maxXPathNamespaces`, defaults 5 and 20). Raise or lower these on the `XMLSecurityDSig` instance if your use case needs different limits.
* `add509Cert(..., $isURL = true)` fetches over http/https only and rejects hosts that resolve to loopback/private/link-local/reserved/CGNAT addresses, with redirects disabled (SSRF hardening). `file://` is disabled unless you pass `array('allow_file_scheme' => true)` in `$options`. Only fetch certificates from trusted URLs — a small DNS-rebinding window remains.
* Decrypted XML containing a `DOCTYPE` is rejected to guard against entity-expansion / XXE. Always load untrusted *input* documents yourself with DTD/entity processing disabled.

* Documents carrying a `DOCTYPE` are rejected during signature verification (`locateSignature()` / `verifyDocument()`). This closes the entity-reference bypass in which an `Id="&e;"` attribute is resolved by `getAttribute()` but is invisible to the XPath reference lookup (a libxml2 hashing bug, the same root cause as CVE-2025-23369), causing `verify()` to validate a different node than the one your application reads. Set `$objDSig->forbidDoctype = false` only if you fully trust the document source and require DTD support.

* **Legacy interoperability (temporary):** if you must accept documents/peers that rely on pre-4.0 behaviour, call `$objDSig->enableLegacyMode()` and/or `$objenc->enableLegacyMode()` once after construction. That restores the interoperability settings in one place (DOCTYPE allowed on verify, XPath transforms allowed without count caps, RSA-1.5 key transport allowed). It does **not** undo always-on hardening such as SignatureMethod/key binding, uniform decryption errors, or DOCTYPE rejection in *decrypted* XML. Prefer migrating peers and removing the call.

## Breaking changes (3.1 → 4.0)

### Platform
- **PHP 8.0+ required** (was 5.4+)
- **`phpseclib/phpseclib` 3.0.57 or later required**; `ext-openssl` is now optional

### Signature verification
- **DOCTYPE rejected by default** during verification (`locateSignature` / `verifyDocument`). Opt out: `$dsig->forbidDoctype = false` or `enableLegacyMode()`
- **XPath Filtering Transforms rejected by default** on verify. Opt in: `$dsig->allowXPathTransforms = true` or `enableLegacyMode()`. Signing unchanged
- **XPath caps** when enabled: max 5 transforms / 20 namespaces per transform (`$maxXPathTransforms` / `$maxXPathNamespaces`; raised by `enableLegacyMode()`)
- **`verify()` always requires SignatureMethod === key algorithm**; mismatch throws (not restored by legacy mode)
- **HMAC keys cannot be loaded from certificates or asymmetric keys**
- **References fail closed**: unresolved, external, or duplicate-Id URIs throw; same-document only
- **Unknown CanonicalizationMethod rejected**
- **HMAC verify returns `1`/`0`** — always check `=== 1`

### Encryption / decryption
- **RSA-1.5 key transport denied by default**. Opt in: `$enc->allowRSA15KeyTransport = true` or `enableLegacyMode()`
- **Single decrypt error message**: `Failure decrypting Data` (do not branch on exception text)
- **DOCTYPE rejected in decrypted XML** (not restored by legacy mode)
- **EncryptedKey/RetrievalMethod depth capped**
- **ISO 10126 pad length validated** on CBC decrypt

### Certificate URL fetch (`add509Cert`)
- Only `http`/`https` by default; `file://` needs `['allow_file_scheme' => true]`
- Private/loopback/link-local/reserved/CGNAT targets rejected; redirects disabled

### API signature changes (defaults preserve most callers)
- `processTransforms(..., $signing = false)`
- `staticLocateKeyInfo(..., $depth = 0, $allowRSA15 = false)`
- `fromEncryptedKeyElement(..., $depth = 0, $allowRSA15 = false)`

### Legacy mode
`$dsig->enableLegacyMode()` / `$enc->enableLegacyMode()` restores **DOCTYPE (verify), XPath transforms + uncapped limits, and RSA-1.5** only. It does **not** undo algorithm/key binding, uniform decrypt errors, decrypted-XML DOCTYPE rejection, Reference fail-closed behavior, SSRF rules, or the PHP/phpseclib requirements.

**Migration tip:** Prefer `verifyDocument()` with a pinned key. Use legacy mode only while updating peers.

### Verifying a signature (recommended)

```php
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$doc = new DOMDocument();
$doc->load('./path/to/signed.xml');

// Pin the key/certificate you trust (do NOT read it from the document).
$objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, array('type' => 'public'));
$objKey->loadKey('./path/to/trusted-cert.pem', true, true);

$objDSig = new XMLSecurityDSig();
// If assertions use custom Id attributes (e.g. WS-Security), declare them first:
// $objDSig->idKeys = array('wsu:Id');
// $objDSig->idNS   = array('wsu' => 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd');

try {
    // Throws on any failure; returns the validated nodes on success.
    $validatedNodes = $objDSig->verifyDocument($objKey, $doc);
    // Operate ONLY on $validatedNodes from here on.
} catch (Exception $e) {
    // Verification failed - reject the message.
}
```

### Decrypting XML (recommended recipe)

xmlseclibs does not derive the decryption key from the document: you supply
your own private key, and the document's session key is unwrapped with it. The
recipe below covers the common `EncryptedKey` (key transport) + `EncryptedData`
(data) layout used by SAML and WS-Security, with the algorithm policy pinned.

```php
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityKey;

$doc = new DOMDocument();
$doc->load('./path/to/encrypted.xml');

$objenc = new XMLSecEnc();

// Pin the algorithm policy (all optional, but recommended):
//  - RSA-1.5 key transport is already denied by default (Bleichenbacher);
//    only set this if you must interoperate with a legacy peer.
// $objenc->allowRSA15KeyTransport = true;
//  - Restrict data encryption to authenticated AES-GCM (rejects CBC):
$objenc->allowedDataAlgorithms = XMLSecEnc::DEFAULT_DATA_ALGORITHMS;
//  - Restrict key transport to RSA-OAEP:
$objenc->allowedKeyAlgorithms  = XMLSecEnc::DEFAULT_KEY_ALGORITHMS;

$encData = $objenc->locateEncryptedData($doc);
if (! $encData) {
    throw new Exception('Cannot locate EncryptedData');
}
$objenc->setNode($encData);
$objenc->type = $encData->getAttribute('Type');

// Resolve the session key. locateKey() reads the data algorithm from the
// document; locateKeyInfo() finds the EncryptedKey.
$objKey = $objenc->locateKey();
if (! $objKey) {
    throw new Exception('Unknown data encryption algorithm');
}

if ($objKeyInfo = $objenc->locateKeyInfo($objKey)) {
    if ($objKeyInfo->isEncrypted) {
        // Load YOUR trusted private key to unwrap the session key.
        $objKeyInfo->loadKey('./path/to/your-private-key.pem', true);
        $sessionKey = $objKeyInfo->encryptedCtx->decryptKey($objKeyInfo);
        $objKey->loadKey($sessionKey);
    }
}

// If the session key was supplied out-of-band (no EncryptedKey), load it here:
// if (empty($objKey->key)) { $objKey->loadKey($sharedSecretBytes); }

// Decrypted content is returned; a DOCTYPE in the plaintext is rejected.
$decrypted = $objenc->decryptNode($objKey, true);
```

If the recipient key is selected by `KeyName` (rather than an embedded
`EncryptedKey`), read `$objKeyInfo->name` and load the matching local private
key yourself before decrypting. Never treat key material from the document as
trusted.


## How to Install

Install with [`composer.phar`](http://getcomposer.org).

```sh
php composer.phar require "robrichards/xmlseclibs"
```


## Use cases

xmlseclibs is being used in many different software.

* [SimpleSAMLPHP](https://github.com/simplesamlphp/simplesamlphp)
* [LightSAML](https://github.com/lightsaml/lightsaml)
* [OneLogin](https://github.com/onelogin/php-saml)

## Basic usage

The example below shows basic usage of xmlseclibs, with a SHA-256 signature.

```php
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

// Load the XML to be signed
$doc = new DOMDocument();
$doc->load('./path/to/file/tobesigned.xml');

// Create a new Security object 
$objDSig = new XMLSecurityDSig();
// Use the c14n exclusive canonicalization
$objDSig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
// Sign using SHA-256
$objDSig->addReference(
    $doc, 
    XMLSecurityDSig::SHA256, 
    array('http://www.w3.org/2000/09/xmldsig#enveloped-signature')
);

// Create a new (private) Security key
$objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, array('type'=>'private'));
/*
If key has a passphrase, set it using
$objKey->passphrase = '<passphrase>';
*/
// Load the private key
$objKey->loadKey('./path/to/privatekey.pem', TRUE);

// Sign the XML file
$objDSig->sign($objKey);

// Add the associated public key to the signature
$objDSig->add509Cert(file_get_contents('./path/to/file/mycert.pem'));

// Append the signature to the XML
$objDSig->appendSignature($doc->documentElement);
// Save the signed XML
$doc->save('./path/to/signed.xml');
```

## How to Contribute

* [Open Issues](https://github.com/robrichards/xmlseclibs/issues)
* [Open Pull Requests](https://github.com/robrichards/xmlseclibs/pulls)

Mailing List: https://groups.google.com/forum/#!forum/xmlseclibs
