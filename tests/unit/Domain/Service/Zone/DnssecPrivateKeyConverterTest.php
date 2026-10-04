<?php

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Service\Zone\DnssecPrivateKeyConverter;

class DnssecPrivateKeyConverterTest extends TestCase
{
    private const ISC_P256 = "Private-key-format: v1.2\nAlgorithm: 13 (ECDSAP256SHA256)\nPrivateKey: 65VGllHsUbC3MJjc5PmI8r7YtYZGyBaCGm/VmhBPtq8=\n";

    // RFC 8032 section 7.1 test 1 and section 7.4 "-----Blank" secret keys
    private const ED25519_SEED = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';
    private const ED448_SEED = '6c82a562cb808d10d632be89c8513ebf6c929f34ddfa8c9f63c9960ef6e348a3528c8a3fcc2f044e39a3fc5b94492f8f032e7549a20098f95b';
    private const ED25519_PREFIX = '302e020100300506032b657004220420';
    private const ED448_PREFIX = '3047020100300506032b6571043b0439';
    private const X25519_PREFIX = '302e020100300506032b656e04220420';

    // RFC 8410 section 10.3: the same Ed25519 key as PKCS#8 v1, and as v2 with attributes and the public key
    private const RFC8410_V1 = "-----BEGIN PRIVATE KEY-----\n"
        . "MC4CAQAwBQYDK2VwBCIEINTuctv5E1hK1bbY8fdp+K06/nwoy/HU++CXqI9EdVhC\n"
        . "-----END PRIVATE KEY-----\n";
    private const RFC8410_V2 = "-----BEGIN PRIVATE KEY-----\n"
        . "MHICAQEwBQYDK2VwBCIEINTuctv5E1hK1bbY8fdp+K06/nwoy/HU++CXqI9EdVhC\n"
        . "oB8wHQYKKoZIhvcNAQkJFDEPDA1DdXJkbGUgQ2hhaXJzgSEAGb9ECWmEzf6FQbrB\n"
        . "Z9w7lshQhqowtrbLDFw4rXAxZuE=\n"
        . "-----END PRIVATE KEY-----\n";
    private const RFC8410_SEED = 'd4ee72dbf913584ad5b6d8f1f769f8ad3afe7c28cbf1d4fbe097a88f44755842';
    private const RFC8410_PUBLIC = '19bf44096984cdfe8541bac167dc3b96c85086aa30b6b6cb0c5c38ad703166e1';

    public function testIscKeyPassesThrough(): void
    {
        $this->assertSame(self::ISC_P256, DnssecPrivateKeyConverter::toIsc(self::ISC_P256, 'ecdsa256'));
    }

    public function testIscKeyWithWindowsLineEndingsIsNormalised(): void
    {
        $this->assertSame(self::ISC_P256, DnssecPrivateKeyConverter::toIsc(str_replace("\n", "\r\n", self::ISC_P256), 'ecdsa256'));
    }

    public function testABindKeyWithTimingLinesKeepsThemForPowerDnsToIgnore(): void
    {
        $bind = self::ISC_P256 . "Created: 20260101000000\nPublish: 20260101000000\n";

        $this->assertSame($bind, DnssecPrivateKeyConverter::toIsc($bind, 'ecdsa256'));
    }

    public function testIscTextInAnUnknownFormatVersionIsUnreadable(): void
    {
        $v2 = str_replace('v1.2', 'v2.0', self::ISC_P256);

        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc($v2, 'ecdsa256'));
    }

    public function testIscKeyForAnotherAlgorithmIsRefused(): void
    {
        $this->assertSame(DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH, DnssecPrivateKeyConverter::toIsc(self::ISC_P256, 'rsasha256'));
    }

    public function testIscKeyWithoutAlgorithmLineIsUnreadable(): void
    {
        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc("Private-key-format: v1.2\nPrivateKey: AAAA\n", 'ecdsa256'));
    }

    public function testRsaPemIsConvertedWithEveryField(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $rsa = openssl_pkey_get_details($key)['rsa'];

        $fields = $this->parse(DnssecPrivateKeyConverter::toIsc($pem, 'rsasha256'));

        $this->assertSame('8 (RSASHA256)', $fields['Algorithm']);
        $this->assertSame(base64_encode($rsa['n']), $fields['Modulus']);
        $this->assertSame(base64_encode($rsa['d']), $fields['PrivateExponent']);
        $this->assertSame(base64_encode($rsa['iqmp']), $fields['Coefficient']);
        $this->assertSame(['Private-key-format', 'Algorithm', 'Modulus', 'PublicExponent', 'PrivateExponent', 'Prime1', 'Prime2', 'Exponent1', 'Exponent2', 'Coefficient'], array_keys($fields));
    }

    public function testRsaPemForAnEcdsaAlgorithmIsRefused(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        openssl_pkey_export($key, $pem);

        $this->assertSame(DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH, DnssecPrivateKeyConverter::toIsc($pem, 'ecdsa256'));
    }

    public function testEcdsaPemScalarIsPaddedToTheCurveSize(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key)['ec']['d'];

        $fields = $this->parse(DnssecPrivateKeyConverter::toIsc($pem, 'ecdsa384'));

        $this->assertSame('14 (ECDSAP384SHA384)', $fields['Algorithm']);
        $scalar = base64_decode($fields['PrivateKey']);
        $this->assertSame(48, strlen($scalar));
        $this->assertSame(ltrim($d, "\0"), ltrim($scalar, "\0"));
    }

    public function testAnIndentedPemPasteIsRead(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $indented = "\n    " . str_replace("\n", "\r\n    ", trim($pem)) . "\n";

        $this->assertIsString(DnssecPrivateKeyConverter::toIsc($indented, 'ecdsa256'));
    }

    public function testEcdsaPemForTheOtherCurveIsRefused(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);

        $this->assertSame(DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH, DnssecPrivateKeyConverter::toIsc($pem, 'ecdsa384'));
    }

    public function testEd25519PemIsConverted(): void
    {
        $fields = $this->parse(DnssecPrivateKeyConverter::toIsc($this->pkcs8(self::ED25519_PREFIX, self::ED25519_SEED), 'ed25519'));

        $this->assertSame('15 (ED25519)', $fields['Algorithm']);
        $this->assertSame(base64_encode((string)hex2bin(self::ED25519_SEED)), $fields['PrivateKey']);
    }

    public function testEd448PemIsConverted(): void
    {
        $fields = $this->parse(DnssecPrivateKeyConverter::toIsc($this->pkcs8(self::ED448_PREFIX, self::ED448_SEED), 'ed448'));

        $this->assertSame('16 (ED448)', $fields['Algorithm']);
        $this->assertSame(base64_encode((string)hex2bin(self::ED448_SEED)), $fields['PrivateKey']);
    }

    public function testRfc8410Ed25519KeyIsReadInBothPkcs8Versions(): void
    {
        foreach (['v1' => self::RFC8410_V1, 'v2' => self::RFC8410_V2] as $version => $pem) {
            $fields = $this->parse(DnssecPrivateKeyConverter::toIsc($pem, 'ed25519'));

            $this->assertSame('15 (ED25519)', $fields['Algorithm'], $version);
            $this->assertSame(base64_encode((string)hex2bin(self::RFC8410_SEED)), $fields['PrivateKey'], $version);
        }
    }

    public function testRfc8410SeedYieldsThePublishedPublicKey(): void
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            $this->markTestSkipped('sodium is not available');
        }
        $fields = $this->parse(DnssecPrivateKeyConverter::toIsc(self::RFC8410_V2, 'ed25519'));
        $seed = base64_decode($fields['PrivateKey'], true);
        $this->assertIsString($seed);

        $this->assertSame(self::RFC8410_PUBLIC, bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))));
    }

    public function testEd448Pkcs8V2KeyWithAPublicKeyIsConverted(): void
    {
        // RFC 5958 v2 (INTEGER 1) with a [1] publicKey of 57 bytes
        $hex = '308183' . '020101' . '3005' . '06032b6571' . '043b0439' . self::ED448_SEED . '813a00' . str_repeat('ab', 57);

        $fields = $this->parse(DnssecPrivateKeyConverter::toIsc($this->derPem($hex), 'ed448'));

        $this->assertSame('16 (ED448)', $fields['Algorithm']);
        $this->assertSame(base64_encode((string)hex2bin(self::ED448_SEED)), $fields['PrivateKey']);
    }

    public function testEd25519Pkcs8KeyWithAttributesIsConverted(): void
    {
        // v1 with a [0] attributes element: the empty SET of attributes
        $hex = '3030' . '020100' . '3005' . '06032b6570' . '04220420' . self::ED25519_SEED . 'a000';

        $fields = $this->parse(DnssecPrivateKeyConverter::toIsc($this->derPem($hex), 'ed25519'));

        $this->assertSame(base64_encode((string)hex2bin(self::ED25519_SEED)), $fields['PrivateKey']);
    }

    public function testMalformedEdDsaStructuresAreUnreadable(): void
    {
        $seed = self::ED25519_SEED;
        $algorithm = '3005' . '06032b6570';
        $malformed = [
            'version 2' => '302e' . '020102' . $algorithm . '04220420' . $seed,
            'oid with parameters' => '3030' . '020100' . '3007' . '06032b6570' . '0500' . '04220420' . $seed,
            'seed too short' => '302d' . '020100' . $algorithm . '0421041f' . substr($seed, 2),
            'byte after the inner octet string' => '302f' . '020100' . $algorithm . '04230420' . $seed . '00',
            'bit string instead of the inner octet string' => '302e' . '020100' . $algorithm . '04220320' . $seed,
            'outer length too long' => '302f' . '020100' . $algorithm . '04220420' . $seed,
            'trailing bytes after the sequence' => '302e' . '020100' . $algorithm . '04220420' . $seed . '00',
            'stray byte after the private key' => '302f' . '020100' . $algorithm . '04220420' . $seed . '00',
            'cut-off attributes' => '3030' . '020100' . $algorithm . '04220420' . $seed . 'a005',
            'public key in a v1 key' => '3051' . '020100' . $algorithm . '04220420' . $seed . '812100' . str_repeat('ab', 32),
            'v2 key without a public key' => '302e' . '020101' . $algorithm . '04220420' . $seed,
            'long-form length below 128' => '30812e' . '020100' . $algorithm . '04220420' . $seed,
        ];
        foreach ($malformed as $case => $hex) {
            $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc($this->derPem($hex), 'ed25519'), $case);
        }
    }

    public function testTwoByteLengthThatFitsOneByteIsUnreadable(): void
    {
        // The valid Ed448 v2 key, with its length of 0x83 written as 82 00 83 instead of 81 83
        $hex = '30820083' . '020101' . '3005' . '06032b6571' . '043b0439' . self::ED448_SEED . '813a00' . str_repeat('ab', 57);

        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc($this->derPem($hex), 'ed448'));
    }

    public function testEd25519PemForAnotherAlgorithmIsRefused(): void
    {
        $pem = $this->pkcs8(self::ED25519_PREFIX, self::ED25519_SEED);

        $this->assertSame(DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH, DnssecPrivateKeyConverter::toIsc($pem, 'ed448'));
    }

    public function testAnX25519KeyIsNotTakenForEd25519(): void
    {
        $pem = $this->pkcs8(self::X25519_PREFIX, self::ED25519_SEED);

        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc($pem, 'ed25519'));
    }

    public function testEncryptedPemIsUnreadable(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem, 'secret');

        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc($pem, 'ecdsa256'));
    }

    public function testTextThatIsNoKeyIsUnreadable(): void
    {
        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc('not a key', 'ecdsa256'));
    }

    public function testOversizeInputIsRefusedBeforeParsing(): void
    {
        $input = self::ISC_P256 . str_repeat('A', DnssecPrivateKeyConverter::MAX_INPUT_BYTES);

        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc($input, 'ecdsa256'));
    }

    public function testAnUnknownAlgorithmNameIsRefused(): void
    {
        $this->assertSame(DnssecKeyOutcome::INVALID_PRIVATE_KEY, DnssecPrivateKeyConverter::toIsc(self::ISC_P256, 'no-such-algorithm'));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function algorithmProvider(): array
    {
        return [
            'rsasha1' => ['rsasha1', 5],
            'rsasha1-nsec3-sha1' => ['rsasha1-nsec3-sha1', 7],
            'rsasha256' => ['rsasha256', 8],
            'rsasha512' => ['rsasha512', 10],
        ];
    }

    #[DataProvider('algorithmProvider')]
    public function testTheAlgorithmLineNamesTheSelectedRsaAlgorithm(string $name, int $number): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
        openssl_pkey_export($key, $pem);

        $this->assertStringStartsWith((string)$number . ' (', $this->parse(DnssecPrivateKeyConverter::toIsc($pem, $name))['Algorithm']);
    }

    private function derPem(string $hex): string
    {
        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode((string)hex2bin($hex)), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    private function pkcs8(string $prefixHex, string $seedHex): string
    {
        $der = (string)hex2bin($prefixHex . $seedHex);

        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    /**
     * @return array<string, string>
     */
    private function parse(string|DnssecKeyOutcome $isc): array
    {
        $this->assertIsString($isc);
        $fields = [];
        foreach (explode("\n", trim($isc)) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $fields[$name] = $value;
        }

        return $fields;
    }
}
