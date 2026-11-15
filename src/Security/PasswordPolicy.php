<?php

namespace App\Security;

/**
 * Требования к сложности пароля (п. 5.1 ТЗ).
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** @return list<string> список нарушений; пусто — пароль подходит */
    public static function violations(string $password, ?string $username = null): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = sprintf('не менее %d символов', self::MIN_LENGTH);
        }
        if (!preg_match('/\p{Lu}/u', $password)) {
            $errors[] = 'заглавная буква';
        }
        if (!preg_match('/\p{Ll}/u', $password)) {
            $errors[] = 'строчная буква';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'цифра';
        }
        if (!preg_match('/[^\p{L}\d]/u', $password)) {
            $errors[] = 'спецсимвол';
        }
        if ($username && mb_stripos($password, $username) !== false) {
            $errors[] = 'пароль не должен содержать логин';
        }

        return $errors;
    }

    public static function generate(int $length = 16): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+?'];
        $all = implode('', $sets);
        $chars = [];
        foreach ($sets as $s) {
            $chars[] = $s[random_int(0, strlen($s) - 1)];
        }
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }
}
