<?php

namespace Poweradmin\Domain\Service;

class PasswordEncryptionService
{
    const ALGORITHM = 'aes-256-cbc';
    const IV_LENGTH = 16;
    private string $session_key;

    public function __construct(string $session_key)
    {
        $this->session_key = $session_key;
    }

    public function encrypt(string $password): string
    {
        if (empty($password)) {
            return '';
        }
        $key = $this->computeKey();
        $iv = $this->computeIV();

        return openssl_encrypt($password, self::ALGORITHM, $key, 0, $iv) . ':' . base64_encode($iv);
    }

    public function decrypt(string $password): string
    {
        $key = $this->computeKey();

        list($encryptedPassword, $iv) = explode(':', $password, 2);
        $iv = base64_decode($iv);

        return rtrim(openssl_decrypt($encryptedPassword, self::ALGORITHM, $key, 0, $iv), "\0");
    }

    private function computeKey(): string
    {
        return hash('sha256', $this->session_key);
    }

    private function computeIV(): string
    {
        return openssl_random_pseudo_bytes(self::IV_LENGTH);
    }
}
