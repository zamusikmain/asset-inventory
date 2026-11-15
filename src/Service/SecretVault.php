<?php

namespace App\Service;

use App\Entity\SecretValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Шифрование секретов (п. 9, 16 ТЗ): libsodium secretbox (XSalsa20-Poly1305).
 * Ключ шифрования хранится вне БД — файл, путь к которому задан SECRETS_KEY_FILE.
 * Если файла нет — хранение секретов отключено (вариант по умолчанию по ТЗ).
 */
class SecretVault
{
    private ?string $key = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(SECRETS_KEY_FILE)%')]
        private readonly string $keyFile,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->loadKey() !== null;
    }

    private function loadKey(): ?string
    {
        if ($this->key === null && $this->keyFile !== '' && is_readable($this->keyFile)) {
            $raw = trim((string) file_get_contents($this->keyFile));
            $bin = base64_decode($raw, true);
            if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                $this->key = $bin;
            }
        }

        return $this->key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $plain): string
    {
        $key = $this->loadKey() ?? throw new \RuntimeException('Хранение секретов отключено: ключ шифрования не настроен (SECRETS_KEY_FILE)');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public function decrypt(string $cipher): string
    {
        $key = $this->loadKey() ?? throw new \RuntimeException('Ключ шифрования не настроен');
        $bin = base64_decode($cipher, true);
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Не удалось расшифровать значение (неверный ключ?)');
        }

        return $plain;
    }

    public function store(string $ownerType, string $ownerId, string $plain, ?string $by): void
    {
        $repo = $this->em->getRepository(SecretValue::class);
        $existing = $repo->findOneBy(['ownerType' => $ownerType, 'ownerId' => $ownerId]);
        $cipher = $this->encrypt($plain);
        if ($existing) {
            $existing->update($cipher, $by);
        } else {
            $this->em->persist(new SecretValue($ownerType, $ownerId, $cipher));
        }
    }

    public function reveal(string $ownerType, string $ownerId): ?string
    {
        $s = $this->em->getRepository(SecretValue::class)->findOneBy(['ownerType' => $ownerType, 'ownerId' => $ownerId]);

        return $s ? $this->decrypt($s->getCiphertext()) : null;
    }

    public function remove(string $ownerType, string $ownerId): void
    {
        $s = $this->em->getRepository(SecretValue::class)->findOneBy(['ownerType' => $ownerType, 'ownerId' => $ownerId]);
        if ($s) {
            $this->em->remove($s);
        }
    }

    public static function mask(string $value): string
    {
        $len = mb_strlen($value);

        return $len <= 4 ? str_repeat('•', $len) : str_repeat('•', $len - 4) . mb_substr($value, -4);
    }
}
