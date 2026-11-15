<?php
namespace RobRichards\XMLSecLibs;

use DOMElement;
use Exception;
use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\Common\AsymmetricKey;
use phpseclib3\Crypt\DSA;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\EC\Formats\Keys\PKCS8 as ECPKCS8Format;
use phpseclib3\Crypt\EC\PrivateKey as ECPrivateKey;
use phpseclib3\Crypt\EC\PublicKey as ECPublicKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\NoKeyLoadedException;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\Formats\Keys\PKCS8 as PKCS8Format;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use phpseclib3\Crypt\RSA\PublicKey as RSAPublicKey;
use phpseclib3\Crypt\TripleDES;
use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Maps;
use phpseclib3\File\X509;

/**
 * xmlseclibs.php
 *
 * Copyright (c) 2007-2026, Robert Richards <rrichards@cdatazone.org>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Robert Richards nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author    Robert Richards <rrichards@cdatazone.org>
 * @copyright 2007-2026 Robert Richards <rrichards@cdatazone.org>
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 */

class XMLSecurityKey
{
    const TRIPLEDES_CBC = 'http://www.w3.org/2001/04/xmlenc#tripledes-cbc';
    const AES128_CBC = 'http://www.w3.org/2001/04/xmlenc#aes128-cbc';
    const AES192_CBC = 'http://www.w3.org/2001/04/xmlenc#aes192-cbc';
    const AES256_CBC = 'http://www.w3.org/2001/04/xmlenc#aes256-cbc';
    const AES128_GCM = 'http://www.w3.org/2009/xmlenc11#aes128-gcm';
    const AES192_GCM = 'http://www.w3.org/2009/xmlenc11#aes192-gcm';
    const AES256_GCM = 'http://www.w3.org/2009/xmlenc11#aes256-gcm';
    const RSA_1_5 = 'http://www.w3.org/2001/04/xmlenc#rsa-1_5';
    const RSA_OAEP_MGF1P = 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p';
    const RSA_OAEP = 'http://www.w3.org/2009/xmlenc11#rsa-oaep';
    const DSA_SHA1 = 'http://www.w3.org/2000/09/xmldsig#dsa-sha1';
    const RSA_SHA1 = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    const RSA_SHA384 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384';
    const RSA_SHA512 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512';
    const ECDSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256';
    const ECDSA_SHA384 = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384';
    const ECDSA_SHA512 = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512';
    const HMAC_SHA1 = 'http://www.w3.org/2000/09/xmldsig#hmac-sha1';
    const RSA_SHA256_MGF1 = 'http://www.w3.org/2007/05/xmldsig-more#sha256-rsa-MGF1';
    const AUTHTAG_LENGTH = 16;

    /**
     * Substituted session-key length when RSA PKCS#1 v1.5 padding check fails
     * (RFC 3218 §2.3.2 Bleichenbacher countermeasure).
     */
    const RSA15_SESSION_KEY_SIZE = 32;

    /**
     * Generic decryption failure message.
     *
     * All decryption failures (bad padding, wrong key, cipher error, failed
     * RSA unwrap) must surface the same message so callers cannot build a
     * padding / ciphertext-validity oracle from distinguishable errors.
     */
    const DECRYPTION_FAILURE = 'Failure decrypting Data';

    /**
     * Default allowlist of named curves for ECDSA keys (sign, verify and
     * ECKeyValue), as phpseclib curve name => OID. See $allowedECCurves.
     */
    const EC_CURVE_OIDS = array(
        'secp256r1' => '1.2.840.10045.3.1.7',
        'secp384r1' => '1.3.132.0.34',
        'secp521r1' => '1.3.132.0.35',
    );

    /**
     * @var array{
     *     library?: string,
     *     cipher?: string,
     *     mode?: string,
     *     type?: string,
     *     method?: string,
     *     keysize?: int,
     *     blocksize?: int,
     *     ivsize?: int,
     *     padding?: int,
     *     digest?: string
     * }
     */
    private $cryptParams = array();

    /** @var int|string */
    public $type = 0;

    /** @var string|null */
    public $key = null;

    /** @var string  */
    public $passphrase = "";

    /** @var string|null */
    public $iv = null;

    /** @var string|null */
    public $name = null;

    /** @var mixed|null */
    public $keyChain = null;

    /** @var bool */
    public $isEncrypted = false;

    /** @var XMLSecEnc|null */
    public $encryptedCtx = null;

    /** @var mixed|null */
    public $guid = null;

    /**
     * Allowlist of named curves accepted for ECDSA keys, as phpseclib curve
     * name => OID (without the "urn:oid:" prefix).
     *
     * When null (the default), only EC_CURVE_OIDS (P-256, P-384 and P-521) are
     * accepted. Keys on any other curve are rejected when signing, verifying,
     * and reading or writing ECKeyValue. To accept another curve, add it
     * explicitly, e.g.
     * $objKey->allowedECCurves = XMLSecurityKey::EC_CURVE_OIDS + array('brainpoolP256r1' => '1.3.36.3.3.2.8.1.1.7');
     * Set it before loading the key from KeyInfo (XMLSecEnc::staticLocateKeyInfo()).
     *
     * @var array<string, string>|null
     */
    public $allowedECCurves = null;

    /**
     * This variable contains the certificate as a string if this key represents an X509-certificate.
     * If this key doesn't represent a certificate, this will be null.
     * @var string|null
     */
    private $x509Certificate = null;

    /**
     * This variable contains the certificate thumbprint if we have loaded an X509-certificate.
     * @var string|null
     */
    private $X509Thumbprint = null;

    /**
     * @param string $type
     * @param null|array{type?: string} $params
     * @throws Exception
     */
    public function __construct($type, $params=null)
    {
        switch ($type) {
            case (self::TRIPLEDES_CBC):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['cipher'] = 'tripledes';
                $this->cryptParams['mode'] = 'cbc';
                $this->cryptParams['type'] = 'symmetric';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmlenc#tripledes-cbc';
                $this->cryptParams['keysize'] = 24;
                $this->cryptParams['blocksize'] = 8;
                $this->cryptParams['ivsize'] = 8;
                break;
            case (self::AES128_CBC):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['cipher'] = 'aes';
                $this->cryptParams['mode'] = 'cbc';
                $this->cryptParams['type'] = 'symmetric';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmlenc#aes128-cbc';
                $this->cryptParams['keysize'] = 16;
                $this->cryptParams['blocksize'] = 16;
                $this->cryptParams['ivsize'] = 16;
                break;
            case (self::AES192_CBC):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['cipher'] = 'aes';
                $this->cryptParams['mode'] = 'cbc';
                $this->cryptParams['type'] = 'symmetric';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmlenc#aes192-cbc';
                $this->cryptParams['keysize'] = 24;
                $this->cryptParams['blocksize'] = 16;
                $this->cryptParams['ivsize'] = 16;
                break;
            case (self::AES256_CBC):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['cipher'] = 'aes';
                $this->cryptParams['mode'] = 'cbc';
                $this->cryptParams['type'] = 'symmetric';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmlenc#aes256-cbc';
                $this->cryptParams['keysize'] = 32;
                $this->cryptParams['blocksize'] = 16;
                $this->cryptParams['ivsize'] = 16;
                break;
            case (self::AES128_GCM):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['cipher'] = 'aes';
                $this->cryptParams['mode'] = 'gcm';
                $this->cryptParams['type'] = 'symmetric';
                $this->cryptParams['method'] = 'http://www.w3.org/2009/xmlenc11#aes128-gcm';
                $this->cryptParams['keysize'] = 16;
                $this->cryptParams['blocksize'] = 16;
                $this->cryptParams['ivsize'] = 12;
                break;
            case (self::AES192_GCM):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['cipher'] = 'aes';
                $this->cryptParams['mode'] = 'gcm';
                $this->cryptParams['type'] = 'symmetric';
                $this->cryptParams['method'] = 'http://www.w3.org/2009/xmlenc11#aes192-gcm';
                $this->cryptParams['keysize'] = 24;
                $this->cryptParams['blocksize'] = 16;
                $this->cryptParams['ivsize'] = 12;
                break;
            case (self::AES256_GCM):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['cipher'] = 'aes';
                $this->cryptParams['mode'] = 'gcm';
                $this->cryptParams['type'] = 'symmetric';
                $this->cryptParams['method'] = 'http://www.w3.org/2009/xmlenc11#aes256-gcm';
                $this->cryptParams['keysize'] = 32;
                $this->cryptParams['blocksize'] = 16;
                $this->cryptParams['ivsize'] = 12;
                break;
            case (self::RSA_1_5):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['padding'] = RSA::ENCRYPTION_PKCS1;
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmlenc#rsa-1_5';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::RSA_OAEP_MGF1P):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['padding'] = RSA::ENCRYPTION_OAEP;
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmlenc#rsa-oaep-mgf1p';
                $this->cryptParams['digest'] = 'sha1';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::RSA_OAEP):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['padding'] = RSA::ENCRYPTION_OAEP;
                $this->cryptParams['method'] = 'http://www.w3.org/2009/xmlenc11#rsa-oaep';
                $this->cryptParams['digest'] = 'sha256';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::RSA_SHA1):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
                $this->cryptParams['padding'] = RSA::SIGNATURE_PKCS1;
                $this->cryptParams['digest'] = 'sha1';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::RSA_SHA256):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
                $this->cryptParams['padding'] = RSA::SIGNATURE_PKCS1;
                $this->cryptParams['digest'] = 'sha256';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::RSA_SHA384):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384';
                $this->cryptParams['padding'] = RSA::SIGNATURE_PKCS1;
                $this->cryptParams['digest'] = 'sha384';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::RSA_SHA512):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512';
                $this->cryptParams['padding'] = RSA::SIGNATURE_PKCS1;
                $this->cryptParams['digest'] = 'sha512';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::ECDSA_SHA256):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256';
                $this->cryptParams['digest'] = 'sha256';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::ECDSA_SHA384):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384';
                $this->cryptParams['digest'] = 'sha384';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::ECDSA_SHA512):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512';
                $this->cryptParams['digest'] = 'sha512';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            case (self::HMAC_SHA1):
                $this->cryptParams['library'] = $type;
                $this->cryptParams['method'] = 'http://www.w3.org/2000/09/xmldsig#hmac-sha1';
                break;
            case (self::RSA_SHA256_MGF1):
                $this->cryptParams['library'] = 'phpseclib';
                $this->cryptParams['method'] = 'http://www.w3.org/2007/05/xmldsig-more#sha256-rsa-MGF1';
                $this->cryptParams['padding'] = RSA::SIGNATURE_PSS;
                $this->cryptParams['digest'] = 'sha256';
                if (is_array($params) && ! empty($params['type'])) {
                    if ($params['type'] == 'public' || $params['type'] == 'private') {
                        $this->cryptParams['type'] = $params['type'];
                        break;
                    }
                }
                throw new Exception('Certificate "type" (private/public) must be passed via parameters');
            default:
                throw new Exception('Invalid Key Type');
        }
        $this->type = $type;
    }

    /**
     * Retrieve the key size for the symmetric encryption algorithm..
     *
     * If the key size is unknown, or this isn't a symmetric encryption algorithm,
     * null is returned.
     *
     * @return int|null  The number of bytes in the key.
     */
    public function getSymmetricKeySize(): ?int
    {
        if (! isset($this->cryptParams['keysize'])) {
            return null;
        }
        return $this->cryptParams['keysize'];
    }

    /**
     * Generates a session key.
     * In case of using DES3-CBC the key is checked for a proper parity bits set.
     * @return string
     * @throws Exception
     */
    public function generateSessionKey(): string
    {
        if (!isset($this->cryptParams['keysize'])) {
            throw new Exception('Unknown key size for type "' . $this->type . '".');
        }
        $keysize = $this->cryptParams['keysize'];
        if ($keysize < 1) {
            throw new Exception('Unknown key size for type "' . $this->type . '".');
        }

        $key = random_bytes($keysize);

        if ($this->type === self::TRIPLEDES_CBC) {
            /* Make sure that the generated key has the proper parity bits set.
             * Mcrypt doesn't care about the parity bits, but others may care.
            */
            for ($i = 0; $i < strlen($key); $i++) {
                $byte = ord($key[$i]) & 0xfe;
                $parity = 1;
                for ($j = 1; $j < 8; $j++) {
                    $parity ^= ($byte >> $j) & 1;
                }
                $byte |= $parity;
                $key[$i] = chr($byte);
            }
        }

        $this->key = $key;
        return $key;
    }

    /**
     * Get the raw thumbprint of a certificate
     *
     * @param string $cert
     * @return null|string
     */
    public static function getRawThumbprint($cert)
    {

        $arCert = explode("\n", $cert);
        $data = '';
        $inData = false;

        foreach ($arCert AS $curData) {
            if (! $inData) {
                if (strncmp($curData, '-----BEGIN CERTIFICATE', 22) == 0) {
                    $inData = true;
                }
            } else {
                if (strncmp($curData, '-----END CERTIFICATE', 20) == 0) {
                    break;
                }
                $data .= trim($curData);
            }
        }

        if (! empty($data)) {
            return strtolower(sha1(base64_decode($data)));
        }

        return null;
    }

    /**
     * Loads the given key, or - with isFile set true - the key from the keyfile.
     *
     * @param string $key
     * @param bool $isFile
     * @param bool $isCert
     * @throws Exception
     */
    public function loadKey($key, $isFile=false, $isCert = false): void
    {
        if ($isFile) {
            $fileContents = file_get_contents($key);
            if ($fileContents === false) {
                throw new Exception('Unable to read key from file');
            }
            $this->key = $fileContents;
        } else {
            $this->key = $key;
        }

        /*
         * Prevent signature key/algorithm confusion: an HMAC (shared-secret)
         * key must never be created from asymmetric material such as an X.509
         * certificate or a public/private key. Otherwise an attacker who can
         * choose the SignatureMethod (e.g. downgrading rsa-sha1 to hmac-sha1)
         * can forge signatures using that public material as the HMAC secret.
         *
         * Recognition is by parsing the bytes (PEM, DER, bare base64, and the
         * other encodings phpseclib accepts), not by looking for PEM armor.
         */
        if (($this->cryptParams['library'] ?? null) === self::HMAC_SHA1) {
            if ($isCert) {
                throw new Exception('An X.509 certificate cannot be used as an HMAC key');
            }
            if (is_string($this->key) && self::isAsymmetricKeyMaterial($this->key)) {
                throw new Exception('Asymmetric key material cannot be used as an HMAC key');
            }
        }

        if ($isCert) {
            $x509 = new X509();
            $certData = $x509->loadX509($this->key);
            if (! is_array($certData)) {
                throw new Exception('Unable to extract certificate');
            }
            $str_cert = $x509->saveX509($certData);
            $str_cert = self::requireString($str_cert, 'Unable to export certificate');
            $this->x509Certificate = $str_cert;
            $this->key = $str_cert;
            $this->X509Thumbprint = self::getRawThumbprint($this->key);
        } else {
            $this->x509Certificate = null;
        }
        if (($this->cryptParams['type'] ?? null) === 'symmetric') {
            if (! isset($this->cryptParams['keysize'])) {
                throw new Exception('Unknown key size for type "' . $this->type . '".');
            }
            $keysize = $this->cryptParams['keysize'];
            if (strlen($this->key) < $keysize) {
                throw new Exception(self::DECRYPTION_FAILURE);
            }
            /* Overlong keys (e.g. RSA-unwrapped session keys) are truncated at use time. */
        }
    }

    /**
     * Whether $key is asymmetric key material and therefore unsafe as an HMAC secret.
     *
     * Recognition does not depend on whether phpseclib can instantiate the
     * key's algorithm:
     *
     * - Any PEM-armored blob (`-----BEGIN`) is rejected, matching the original
     *   guard and covering algorithms the library does not support.
     * - Values that parse as a public/private key or X.509 certificate are
     *   rejected (PEM, DER, bare base64, and the other encodings phpseclib
     *   accepts).
     * - ASN.1 SubjectPublicKeyInfo and PKCS#8 / OneAsymmetricKey containers
     *   are recognized by structure alone, so unsupported standalone keys
     *   (e.g. SM2, Diffie–Hellman) are still rejected as DER or bare base64.
     * - Password-protected PKCS#8 is recognized as EncryptedPrivateKeyInfo
     *   without decrypting it. OpenSSL traditional encrypted PEM
     *   (Proc-Type / DEK-Info), OpenSSH private keys, and PuTTY key files are
     *   recognized from their defined headers.
     *
     * A failed parse is not key material. Arbitrary binary and base64 strings
     * that are not valid keys remain usable as HMAC secrets.
     *
     * @param string $key
     * @return bool
     */
    private static function isAsymmetricKeyMaterial($key)
    {
        if ($key === '') {
            return false;
        }

        /*
         * Preserve the original PEM rejection. Algorithms phpseclib cannot
         * load still arrive as -----BEGIN PUBLIC KEY----- (and similar).
         */
        if (strpos($key, '-----BEGIN') !== false) {
            return true;
        }

        /*
         * Garbage input makes phpseclib's ASN.1 parser emit warnings.
         * Keep those from escaping loadKey() / verifySignature().
         */
        set_error_handler(static function () {
            return true;
        });
        try {
            if (self::parsesAsPublicKeyOrCertificate($key)) {
                return true;
            }

            try {
                PKCS8Format::extractEncryptionAlgorithm($key);
                return true;
            } catch (\Throwable $e) {
            }

            if (self::parsesAsAsn1KeyContainer($key)) {
                return true;
            }
        } finally {
            restore_error_handler();
        }

        if (self::isTraditionalEncryptedPem($key)
            || self::isOpenSshPrivateKey($key)
            || self::isPuttyKey($key)
        ) {
            return true;
        }

        return false;
    }

    /**
     * True when phpseclib can read $key as a public key, private key, or certificate.
     *
     * PublicKeyLoader tries EC, then RSA, then DSA, then X.509. Two failure
     * modes need extra work:
     *
     * 1. An error other than NoKeyLoadedException (phpseclib's ASN.1 parser
     *    can throw ValueError on garbage) aborts that sequence early. Each
     *    algorithm is tried on its own so a fault while inspecting one format
     *    cannot hide a key or certificate of another.
     * 2. NoKeyLoadedException after PublicKeyLoader has already tried X.509
     *    still happens when the certificate parses but its public-key
     *    algorithm is unsupported (e.g. X25519). PublicKeyLoader only returns
     *    success when getPublicKey() yields a usable key. A standalone
     *    X509::loadX509() check always runs after unsuccessful key loading so
     *    certificate recognition does not depend on algorithm support.
     *
     * @param string $key
     * @return bool
     */
    private static function parsesAsPublicKeyOrCertificate($key)
    {
        try {
            PublicKeyLoader::load($key);
            return true;
        } catch (NoKeyLoadedException $e) {
            /* Fall through to the standalone X.509 check. */
        } catch (\Throwable $e) {
            foreach (array(EC::class, RSA::class, DSA::class) as $algorithm) {
                try {
                    $algorithm::load($key);
                    return true;
                } catch (\Throwable $ignored) {
                }
            }
        }

        try {
            $x509 = new X509();
            return is_array($x509->loadX509($key));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * True when $key is an ASN.1 public- or private-key container, regardless
     * of whether the algorithm OID is supported.
     *
     * SubjectPublicKeyInfo covers SPKI public keys (including SM2 and
     * Diffie–Hellman). OneAsymmetricKey covers PKCS#8 private keys (v1 and
     * v2). AlgorithmIdentifiers are matched by structure; unknown OIDs still
     * parse.
     *
     * @param string $key
     * @return bool
     */
    private static function parsesAsAsn1KeyContainer($key)
    {
        try {
            $ber = ASN1::extractBER($key);
            if ($ber === false) {
                $ber = $key;
            }
            $decoded = ASN1::decodeBER($ber);
            if (! $decoded) {
                return false;
            }

            if (is_array(ASN1::asn1map($decoded[0], Maps\SubjectPublicKeyInfo::MAP))) {
                return true;
            }
            if (is_array(ASN1::asn1map($decoded[0], Maps\OneAsymmetricKey::MAP))) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        return false;
    }

    /**
     * OpenSSL traditional PEM encryption (RFC 1421 Proc-Type / DEK-Info).
     *
     * @param string $key
     * @return bool
     */
    private static function isTraditionalEncryptedPem($key)
    {
        return preg_match('/(?:^|\R)Proc-Type:[ \t]*4,ENCRYPTED\b/i', $key) === 1
            && preg_match('/(?:^|\R)DEK-Info:[ \t]*\S+/i', $key) === 1;
    }

    /**
     * OpenSSH private key (unencrypted or bcrypt-encrypted), PEM or raw/base64.
     *
     * @param string $key
     * @return bool
     */
    private static function isOpenSshPrivateKey($key)
    {
        $magic = "openssh-key-v1\0";
        if (strpos($key, $magic) !== false) {
            return true;
        }

        $compact = preg_replace('/-----(?:BEGIN|END) [^-]+-----|\s+/', '', $key);
        if (! is_string($compact) || preg_match('/^[A-Za-z0-9+\/=]+$/', $compact) !== 1) {
            return false;
        }
        $decoded = base64_decode($compact, true);
        return is_string($decoded) && strncmp($decoded, $magic, strlen($magic)) === 0;
    }

    /**
     * PuTTY private-key file (v2 or v3), encrypted or not.
     *
     * @param string $key
     * @return bool
     */
    private static function isPuttyKey($key)
    {
        return strncmp(ltrim($key), 'PuTTY-User-Key-File-', 20) === 0;
    }

    /**
     * ISO 10126 Padding
     *
     * @param string $data
     * @param integer $blockSize
     * @throws Exception
     * @return string
     */
    private function padISO10126($data, $blockSize)
    {
        if ($blockSize > 256) {
            throw new Exception('Block size higher than 256 not allowed');
        }
        $padChr = $blockSize - (strlen($data) % $blockSize);
        $pattern = chr($padChr);
        return $data . str_repeat($pattern, $padChr);
    }

    /**
     * Remove ISO 10126 Padding
     *
     * @param string $data
     * @return string
     * @throws Exception
     */
    private function unpadISO10126($data)
    {
        $len = strlen($data);
        if ($len === 0) {
            /*
             * Use a single, generic error for every decryption failure. Do not
             * reveal whether the padding (as opposed to the ciphertext) was the
             * cause: distinguishable padding errors turn unauthenticated CBC
             * decryption into a padding oracle (plaintext recovery).
             */
            throw new Exception(self::DECRYPTION_FAILURE);
        }
        $padLen = ord($data[$len - 1]);
        $blockSize = $this->cryptParams['blocksize'] ?? 16;
        if ($padLen < 1 || $padLen > $blockSize || $padLen > $len) {
            throw new Exception(self::DECRYPTION_FAILURE);
        }
        return substr($data, 0, -$padLen);
    }

    /**
     * Whether this key represents a symmetric (data-encryption) cipher rather
     * than an asymmetric (key-transport) algorithm.
     *
     * @return bool
     */
    public function isSymmetricCipher()
    {
        return ($this->cryptParams['type'] ?? null) === 'symmetric';
    }

    /**
     * @return AES|TripleDES
     * @throws Exception
     */
    private function createSymmetricCipher()
    {
        if (! is_string($this->key)) {
            throw new Exception('Symmetric key is not loaded');
        }
        if (! isset($this->cryptParams['cipher'], $this->cryptParams['mode'])) {
            throw new Exception('Unknown symmetric cipher');
        }
        $mode = $this->cryptParams['mode'];
        if ($this->cryptParams['cipher'] === 'aes') {
            $cipher = new AES($mode);
        } elseif ($this->cryptParams['cipher'] === 'tripledes') {
            $cipher = new TripleDES($mode);
        } else {
            throw new Exception('Unknown symmetric cipher');
        }
        /* Truncate overlong keys to the algorithm size (legacy RSA-unwrapped keys). */
        $key = $this->key;
        if (isset($this->cryptParams['keysize']) && strlen($key) > $this->cryptParams['keysize']) {
            $key = substr($key, 0, $this->cryptParams['keysize']);
        }
        $cipher->setKey($key);
        return $cipher;
    }

    /**
     * Encrypts the given data (string) using phpseclib
     *
     * @param string $data
     * @return string
     * @throws Exception
     */
    private function encryptSymmetric($data)
    {
        if (! isset($this->cryptParams['ivsize'], $this->cryptParams['mode'])) {
            throw new Exception('Unknown symmetric cipher parameters');
        }
        $ivsize = $this->cryptParams['ivsize'];
        if ($ivsize < 1) {
            throw new Exception('Unknown IV size for type "' . $this->type . '".');
        }
        $this->iv = random_bytes($ivsize);
        $cipher = $this->createSymmetricCipher();
        $authTag = null;

        if ($this->cryptParams['mode'] === 'gcm') {
            $cipher->setNonce($this->iv);
            $encrypted = $cipher->encrypt($data);
            $authTag = $cipher->getTag(self::AUTHTAG_LENGTH);
        } else {
            if (! isset($this->cryptParams['blocksize'])) {
                throw new Exception('Unknown block size for type "' . $this->type . '".');
            }
            $data = $this->padISO10126($data, $this->cryptParams['blocksize']);
            $cipher->disablePadding();
            $cipher->setIV($this->iv);
            $encrypted = $cipher->encrypt($data);
        }

        $encrypted = self::requireString($encrypted, 'Failure encrypting Data (phpseclib symmetric)');
        return $this->iv . $encrypted . $authTag;
    }

    /**
     * Decrypts the given data (string) using phpseclib
     *
     * @param string $data
     * @return string
     * @throws Exception
     */
    private function decryptSymmetric($data)
    {
        if (! isset($this->cryptParams['ivsize'], $this->cryptParams['mode'])) {
            throw new Exception('Unknown symmetric cipher parameters');
        }

        /*
         * Catch every crypto/padding failure and rethrow the same generic
         * exception. Distinct phpseclib types/messages (LengthException,
         * BadDecryptionException, etc.) would otherwise form a ciphertext-
         * validity oracle.
         */
        try {
            $iv_length = $this->cryptParams['ivsize'];
            $this->iv = substr($data, 0, $iv_length);
            $data = substr($data, $iv_length);
            $cipher = $this->createSymmetricCipher();
            $authTag = null;

            if ($this->cryptParams['mode'] === 'gcm') {
                $offset = 0 - self::AUTHTAG_LENGTH;
                $authTag = substr($data, $offset);
                if (strlen($authTag) !== self::AUTHTAG_LENGTH) {
                    throw new Exception(self::DECRYPTION_FAILURE);
                }
                $data = substr($data, 0, $offset);
                $cipher->setNonce($this->iv);
                $cipher->setTag($authTag);
                $decrypted = $cipher->decrypt($data);
            } else {
                $cipher->disablePadding();
                $cipher->setIV($this->iv);
                $decrypted = $cipher->decrypt($data);
            }

            $decrypted = self::requireString($decrypted, self::DECRYPTION_FAILURE);
            return null !== $authTag ? $decrypted : $this->unpadISO10126($decrypted);
        } catch (\Throwable $e) {
            throw new Exception(self::DECRYPTION_FAILURE);
        }
    }

    /**
     * Ensure a crypto operation produced a string result.
     *
     * phpseclib PHPDocs often omit falsey failure modes (e.g. openssl_* returning
     * false); keep a runtime check without relying on those docs.
     *
     * @param mixed $value
     * @throws Exception
     */
    private static function requireString($value, string $message): string
    {
        if (! is_string($value)) {
            throw new Exception($message);
        }
        return $value;
    }

    /**
     * Apply padding / digest options to a phpseclib RSA key.
     *
     * @throws Exception
     */
    private function configureRSAKey(AsymmetricKey $key): RSA
    {
        if (! $key instanceof RSA) {
            throw new Exception('Expected an RSA key');
        }
        if (! isset($this->cryptParams['padding'])) {
            throw new Exception('RSA padding is not configured');
        }
        $configured = $key->withPadding($this->cryptParams['padding']);
        if (! $configured instanceof RSA) {
            throw new Exception('Expected an RSA key');
        }
        if (! empty($this->cryptParams['digest'])) {
            $configured = $configured->withHash($this->cryptParams['digest']);
            if (! $configured instanceof RSA) {
                throw new Exception('Expected an RSA key');
            }
            if (
                $this->cryptParams['padding'] === RSA::ENCRYPTION_OAEP ||
                $this->cryptParams['padding'] === RSA::SIGNATURE_PSS
            ) {
                $configured = $configured->withMGFHash($this->cryptParams['digest']);
                if (! $configured instanceof RSA) {
                    throw new Exception('Expected an RSA key');
                }
            }
        }
        return $configured;
    }

    /**
     * Whether this key represents an ECDSA signature algorithm.
     *
     * @return bool
     */
    private function isECDSA()
    {
        return $this->type === self::ECDSA_SHA256
            || $this->type === self::ECDSA_SHA384
            || $this->type === self::ECDSA_SHA512;
    }

    /**
     * The curves accepted for ECDSA keys: $allowedECCurves, or EC_CURVE_OIDS
     * when it is not set.
     *
     * @return array<string, string>
     */
    private function getAllowedECCurves(): array
    {
        return is_array($this->allowedECCurves) ? $this->allowedECCurves : self::EC_CURVE_OIDS;
    }

    /**
     * Reject keys whose curve is not in the ECDSA curve allowlist.
     *
     * EdDSA and Montgomery keys are also EC instances in phpseclib but are not
     * valid ECDSA keys, so they are rejected even if they are allowlisted.
     *
     * @param mixed $curve Curve name (string), or array for explicit curve parameters
     * @throws Exception
     */
    private function assertAllowedECCurve($curve): void
    {
        if (! is_string($curve)
            || in_array($curve, array('Ed25519', 'Ed448', 'Curve25519', 'Curve448'), true)) {
            throw new Exception('ECDSA requires a key on a named prime curve');
        }
        if (! isset($this->getAllowedECCurves()[$curve])) {
            throw new Exception("EC curve is not allowed: '$curve'");
        }
    }

    /**
     * Apply digest and signature encoding options to a phpseclib EC key.
     *
     * XML Signature encodes ECDSA signatures as the concatenation r || s of
     * two integers, each as wide as the curve order (IEEE P1363), not as the
     * ASN.1 DER sequence produced by default.
     *
     * Only keys on an allowlisted curve are accepted (see $allowedECCurves).
     *
     * @throws Exception
     */
    private function configureECKey(AsymmetricKey $key): EC
    {
        if (! $key instanceof EC) {
            throw new Exception('Expected an EC key');
        }
        $this->assertAllowedECCurve($key->getCurve());
        if (empty($this->cryptParams['digest'])) {
            throw new Exception('ECDSA digest is not configured');
        }
        $configured = $key->withHash($this->cryptParams['digest']);
        if (! $configured instanceof EC) {
            throw new Exception('Expected an EC key');
        }
        $configured = $configured->withSignatureFormat('IEEE');
        if (! $configured instanceof EC) {
            throw new Exception('Expected an EC key');
        }
        return $configured;
    }

    /**
     * Encrypts the given data (string) using phpseclib.
     *
     * @param string $data
     * @return string|null
     * @throws Exception
     */
    public function encryptData($data)
    {
        if (($this->cryptParams['library'] ?? null) !== 'phpseclib') {
            return null;
        }
        if (! is_string($this->key)) {
            throw new Exception('Key is not loaded');
        }
        switch ($this->cryptParams['type'] ?? null) {
            case 'symmetric':
                return $this->encryptSymmetric($data);
            case 'public':
                $public = $this->configureRSAKey(PublicKeyLoader::load($this->key));
                if (! $public instanceof RSAPublicKey) {
                    throw new Exception('Expected an RSA public key');
                }
                return self::requireString(
                    $public->encrypt($data),
                    'Failure encrypting Data (phpseclib)'
                );
            case 'private':
                throw new Exception('Encrypting with a private key is not supported');
            default:
                throw new Exception('Unsupported key type for encryption');
        }
    }

    /**
     * RSA private-key decryption with a PKCS#1 v1.5 padding-oracle countermeasure.
     *
     * On PKCS#1 v1.5 padding failure, return random bytes of the expected
     * session-key length (RFC 3218 §2.3.2) so callers cannot distinguish
     * invalid padding from a valid unwrap with wrong key material.
     *
     * @throws Exception
     */
    private function decryptPrivateRsa(RSAPrivateKey $private, string $data): string
    {
        $decrypted = false;
        try {
            $decrypted = $private->decrypt($data);
        } catch (\Throwable $e) {
            $decrypted = false;
        }
        if (is_string($decrypted) && $decrypted !== '') {
            if (($this->cryptParams['padding'] ?? null) === RSA::ENCRYPTION_PKCS1
                && ! self::isPlausibleSessionKeyLength(strlen($decrypted))) {
                return random_bytes(self::RSA15_SESSION_KEY_SIZE);
            }
            return $decrypted;
        }
        if (($this->cryptParams['padding'] ?? null) === RSA::ENCRYPTION_PKCS1) {
            return random_bytes(self::RSA15_SESSION_KEY_SIZE);
        }
        throw new Exception(self::DECRYPTION_FAILURE);
    }

    /**
     * Lengths used by XML Encryption session keys (AES-128/192/256, 3DES).
     */
    private static function isPlausibleSessionKeyLength(int $len): bool
    {
        return $len === 16 || $len === 24 || $len === 32;
    }

    /**
     * Decrypts the given data (string) using phpseclib.
     *
     * @param string $data
     * @return string|null
     * @throws Exception
     */
    public function decryptData($data)
    {
        if (($this->cryptParams['library'] ?? null) !== 'phpseclib') {
            return null;
        }
        if (! is_string($this->key)) {
            throw new Exception('Key is not loaded');
        }
        switch ($this->cryptParams['type'] ?? null) {
            case 'symmetric':
                return $this->decryptSymmetric($data);
            case 'public':
                throw new Exception('Decrypting with a public key is not supported');
            case 'private':
                if ($this->passphrase !== '') {
                    $private = PublicKeyLoader::load($this->key, $this->passphrase);
                } else {
                    $private = PublicKeyLoader::load($this->key);
                }
                $private = $this->configureRSAKey($private);
                if (! $private instanceof RSAPrivateKey) {
                    throw new Exception('Expected an RSA private key');
                }
                return $this->decryptPrivateRsa($private, $data);
            default:
                throw new Exception('Unsupported key type for decryption');
        }
    }

    /**
     * Signs the data (string) using the extension assigned to the type in the constructor.
     *
     * @param string $data
     * @return string
     * @throws Exception
     */
    public function signData($data)
    {
        if (! is_string($this->key)) {
            throw new Exception('Key is not loaded');
        }
        switch ($this->cryptParams['library'] ?? null) {
            case 'phpseclib':
                if ($this->passphrase !== '') {
                    $private = PublicKeyLoader::load($this->key, $this->passphrase);
                } else {
                    $private = PublicKeyLoader::load($this->key);
                }
                if ($this->isECDSA()) {
                    $private = $this->configureECKey($private);
                    if (! $private instanceof ECPrivateKey) {
                        throw new Exception('Expected an EC private key');
                    }
                } else {
                    $private = $this->configureRSAKey($private);
                    if (! $private instanceof RSAPrivateKey) {
                        throw new Exception('Expected an RSA private key');
                    }
                }
                return self::requireString(
                    $private->sign($data),
                    'Failure Signing Data'
                );
            case (self::HMAC_SHA1):
                if (self::isAsymmetricKeyMaterial($this->key)) {
                    throw new Exception('Asymmetric key material cannot be used as an HMAC key');
                }
                return hash_hmac("sha1", $data, $this->key, true);
            default:
                throw new Exception('Unsupported key type for signing');
        }
    }

    /**
     * Verifies the data (string) against the given signature using the extension assigned to the type in the constructor.
     *
     * Returns:
     *  1 on succesful signature verification,
     *  0 when signature verification failed,
     *  -1 if an error occurred during processing.
     *
     * NOTE: be very careful when checking the return value, because in PHP,
     * -1 will be cast to True when in boolean context. So always check the
     * return value in a strictly typed way, e.g. "$obj->verify(...) === 1".
     *
     * @param string $data
     * @param string $signature
     * @return int
     */
    public function verifySignature($data, $signature)
    {
        switch ($this->cryptParams['library'] ?? null) {
            case 'phpseclib':
                try {
                    if (! is_string($this->key)) {
                        return -1;
                    }
                    if ($this->isECDSA()) {
                        $public = $this->configureECKey(PublicKeyLoader::load($this->key));
                        if (! $public instanceof ECPublicKey) {
                            return -1;
                        }
                        /*
                         * r || s must not be wider than twice the curve size.
                         * Shorter values are accepted on purpose: some signers
                         * (e.g. Apache Santuario) strip leading zero bytes, so
                         * about 1 in 4 of their P-521 signatures are 130 bytes
                         * instead of 132. phpseclib splits the value in half.
                         */
                        $maxLength = 2 * (int) ceil($public->getLength() / 8);
                        if (strlen($signature) > $maxLength) {
                            return 0;
                        }
                        $result = $public->verify($data, $signature);
                        return $result === true ? 1 : 0;
                    }
                    $public = $this->configureRSAKey(PublicKeyLoader::load($this->key));
                    if (! $public instanceof RSAPublicKey) {
                        return -1;
                    }
                    $result = $public->verify($data, $signature);
                    return $result === true ? 1 : 0;
                } catch (Exception $e) {
                    return -1;
                }
            case (self::HMAC_SHA1):
                /* Defense in depth against key/algorithm confusion: refuse to
                 * HMAC with asymmetric material even if it was assigned to
                 * $key without loadKey() (see loadKey()). */
                if (! is_string($this->key) || self::isAsymmetricKeyMaterial($this->key)) {
                    return -1;
                }
                $expectedSignature = hash_hmac("sha1", $data, $this->key, true);
                return hash_equals($expectedSignature, $signature) ? 1 : 0;
        }
        return -1;
    }

    /**
     * @deprecated
     * @see getAlgorithm()
     * @return mixed
     */
    public function getAlgorith()
    {
        return $this->getAlgorithm();
    }

    /**
     * @return string
     */
    public function getAlgorithm()
    {
        return $this->cryptParams['method'] ?? '';
    }

    /**
     *
     * @param int $type
     * @param string $string
     * @return null|string
     */
    public static function makeAsnSegment($type, $string)
    {
        switch ($type) {
            case 0x02:
                if ($string === '' || ord($string[0]) > 0x7f)
                    $string = chr(0).$string;
                break;
            case 0x03:
                $string = chr(0).$string;
                break;
        }

        $length = strlen($string);

        if ($length < 128) {
            $output = sprintf("%c%c%s", $type, $length, $string);
        } else if ($length < 0x0100) {
            $output = sprintf("%c%c%c%s", $type, 0x81, $length, $string);
        } else if ($length < 0x010000) {
            $output = sprintf("%c%c%c%c%s", $type, 0x82, $length / 0x0100, $length % 0x0100, $string);
        } else {
            $output = null;
        }
        return $output;
    }

    /**
     *
     * Hint: Modulus and Exponent must already be base64 decoded
     * @param string $modulus
     * @param string $exponent
     * @return string
     * @throws Exception
     */
    public static function convertRSA($modulus, $exponent)
    {
        if ($modulus === '' || $exponent === '') {
            throw new Exception('Unable to convert RSA key');
        }
        /* make an ASN publicKeyInfo */
        $exponentEncoding = self::makeAsnSegment(0x02, $exponent);
        $modulusEncoding = self::makeAsnSegment(0x02, $modulus);
        if ($exponentEncoding === null || $modulusEncoding === null) {
            throw new Exception('Unable to convert RSA key');
        }
        $sequenceEncoding = self::makeAsnSegment(0x30, $modulusEncoding.$exponentEncoding);
        if ($sequenceEncoding === null) {
            throw new Exception('Unable to convert RSA key');
        }
        $bitstringEncoding = self::makeAsnSegment(0x03, $sequenceEncoding);
        if ($bitstringEncoding === null) {
            throw new Exception('Unable to convert RSA key');
        }
        $rsaAlgorithmIdentifier = pack("H*", "300D06092A864886F70D0101010500");
        $publicKeyInfo = self::makeAsnSegment(0x30, $rsaAlgorithmIdentifier.$bitstringEncoding);
        if ($publicKeyInfo === null) {
            throw new Exception('Unable to convert RSA key');
        }

        /* encode the publicKeyInfo in base64 and add PEM brackets */
        $publicKeyInfoBase64 = base64_encode($publicKeyInfo);
        $encoding = "-----BEGIN PUBLIC KEY-----\n";
        $offset = 0;
        while ($segment = substr($publicKeyInfoBase64, $offset, 64)) {
            $encoding = $encoding.$segment."\n";
            $offset += 64;
        }
        return $encoding."-----END PUBLIC KEY-----\n";
    }

    /**
     * Convert the content of an ECKeyValue element to a PEM public key.
     *
     * Hint: the point must already be base64 decoded
     * @param string $curveOID Named curve OID, without the "urn:oid:" prefix
     * @param string $point Uncompressed EC point (0x04 || X || Y)
     * @param array<string, string>|null $allowedCurves Curve allowlist (phpseclib name => OID); null for EC_CURVE_OIDS
     * @return string
     * @throws Exception
     */
    public static function convertEC($curveOID, $point, $allowedCurves = null)
    {
        if (! is_array($allowedCurves)) {
            $allowedCurves = self::EC_CURVE_OIDS;
        }
        $curveName = array_search($curveOID, $allowedCurves, true);
        if (! is_string($curveName)
            || in_array($curveName, array('Ed25519', 'Ed448', 'Curve25519', 'Curve448'), true)) {
            throw new Exception('Unsupported ECKeyValue curve');
        }
        $curveClass = '\\phpseclib3\\Crypt\\EC\\Curves\\'.$curveName;
        if (! class_exists($curveClass)) {
            throw new Exception('Unsupported ECKeyValue curve');
        }
        $curve = new $curveClass();
        $pointLength = 1 + 2 * $curve->getLengthInBytes();
        if (strlen($point) !== $pointLength || $point[0] !== "\x04") {
            throw new Exception('Unable to convert EC key');
        }
        try {
            /* extractPoint() rejects points that are not on the curve. */
            $publicPoint = ECPKCS8Format::extractPoint("\0".$point, $curve);
            return ECPKCS8Format::savePublicKey($curve, $publicPoint)."\n";
        } catch (\Throwable $e) {
            throw new Exception('Unable to convert EC key');
        }
    }

    /**
     * Return the data needed to write an ECKeyValue element for this key.
     *
     * The key may be a certificate, a public key or a private key.
     *
     * @return array{oid: string, point: string} Named curve OID and uncompressed EC point
     * @throws Exception
     */
    public function getECPublicKeyData(): array
    {
        if (! is_string($this->key)) {
            throw new Exception('Key is not loaded');
        }
        if ($this->passphrase !== '') {
            $key = PublicKeyLoader::load($this->key, $this->passphrase);
        } else {
            $key = PublicKeyLoader::load($this->key);
        }
        if ($key instanceof ECPrivateKey) {
            $key = $key->getPublicKey();
        }
        if (! $key instanceof ECPublicKey) {
            throw new Exception('Expected an EC key');
        }
        $curve = $key->getCurve();
        $this->assertAllowedECCurve($curve);
        return array(
            'oid' => $this->getAllowedECCurves()[$curve],
            'point' => $key->getEncodedCoordinates(),
        );
    }

    /**
     * @param mixed $parent
     */
    public function serializeKey($parent): void
    {

    }

    /**
     * Retrieve the X509 certificate this key represents.
     *
     * Will return the X509 certificate in PEM-format if this key represents
     * an X509 certificate.
     *
     * @return string|null The X509 certificate or null if this key doesn't represent an X509-certificate.
     */
    public function getX509Certificate(): ?string
    {
        return $this->x509Certificate;
    }

    /**
     * Get the thumbprint of this X509 certificate.
     *
     * Returns:
     *  The thumbprint as a lowercase 40-character hexadecimal number, or null
     *  if this isn't a X509 certificate.
     *
     *  @return string|null Lowercase 40-character hexadecimal number of thumbprint
     */
    public function getX509Thumbprint(): ?string
    {
        return $this->X509Thumbprint;
    }


    /**
     * Create key from an EncryptedKey-element.
     *
     * @param DOMElement $element The EncryptedKey-element.
     * @param int $depth
     * @param bool $allowRSA15
     * @throws Exception
     *
     * @return XMLSecurityKey The new key.
     */
    public static function fromEncryptedKeyElement(DOMElement $element, int $depth = 0, bool $allowRSA15 = false): XMLSecurityKey
    {

        $objenc = new XMLSecEnc();
        $objenc->allowRSA15KeyTransport = $allowRSA15;
        $objenc->setNode($element);
        if (! $objKey = $objenc->locateKey()) {
            throw new Exception("Unable to locate algorithm for this Encrypted Key");
        }
        $objKey->isEncrypted = true;
        $objKey->encryptedCtx = $objenc;
        XMLSecEnc::staticLocateKeyInfo($objKey, $element, $depth, $allowRSA15);
        return $objKey;
    }

}
