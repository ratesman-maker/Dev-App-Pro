<?php
declare(strict_types=1);

namespace DevAppPro\Services;

/**
 * Služba pro šifrování a dešifrování citlivých údajů (hesla k přístupům).
 * Používá AES-256-GCM s náhodným IV a autentizačním tagem.
 */
class CryptoService
{
    private string $key;
    private string $cipher = 'aes-256-gcm';

    public function __construct()
    {
        $key = defined('CREDENTIALS_ENCRYPTION_KEY') ? CREDENTIALS_ENCRYPTION_KEY : '';
        if ($key === '') {
            throw new \RuntimeException('CREDENTIALS_ENCRYPTION_KEY není definován v config.php');
        }
        // Klíč musí být 32 bajtů pro AES-256
        $this->key = hash('sha256', $key, true);
    }

    /**
     * Zašifruje plaintext a vrátí base64-encoded blob (iv + tag + ciphertext).
     */
    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Šifrování selhalo: ' . openssl_error_string());
        }

        // Formát: base64(iv + tag + ciphertext)
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Dešifruje base64-encoded blob zpět na plaintext.
     */
    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $blob = base64_decode($encrypted, true);
        if ($blob === false) {
            return '';
        }

        $ivLen = openssl_cipher_iv_length($this->cipher);
        $tagLen = 16; // GCM tag je 16 bajtů

        if (strlen($blob) < $ivLen + $tagLen) {
            return '';
        }

        $iv = substr($blob, 0, $ivLen);
        $tag = substr($blob, $ivLen, $tagLen);
        $ciphertext = substr($blob, $ivLen + $tagLen);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? '' : $plaintext;
    }
}
