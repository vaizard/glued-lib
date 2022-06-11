<?php
declare(strict_types=1);
namespace Glued\Lib;

class Crypto
{

    public function __construct() {
        $this->base64_variant = SODIUM_BASE64_VARIANT_URLSAFE;
    }

    /**
     * Returns a base64 encoded encryption key
     * @return string encryption key
     */
    public function genkey_base64(): string {
        $key = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), $this->base64_variant); // 256 bit
        return $key;
    }

    /**
     * Encrypts message, returns it in base64.
     * @param $msg string message to be encrypted
     * @param $key string base64 encoded encryption key
     * @return string encrypted $msg
     */
    public function encrypt(string $msg, string $key): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); // 24 bytes
        $ciphertext = sodium_crypto_secretbox($msg, $nonce, sodium_base642bin($key, $this->base64_variant));
        $encoded = sodium_bin2base64($nonce . $ciphertext, $this->base64_variant);
        sodium_memzero($msg);
        sodium_memzero($key);
        return $encoded;
    }

    /**
     * Decrypts a base64 coded encrypted message.
     * @param $encoded string message to be decrypted
     * @param $key string base64 encoded encryption key
     * @return string decrypted $encoded
     */
    public function decrypt(string $encoded, string $key): string {
        $decoded = sodium_base642bin($encoded, $this->base64_variant);
        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, sodium_base642bin($key, $this->base64_variant));
        sodium_memzero($ciphertext);
        sodium_memzero($key);
        return $plaintext;
    }
}