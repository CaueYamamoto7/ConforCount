<?php
declare(strict_types=1);

namespace App\Service;

final class Cnpj
{
    public static function normalizar(string $v): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $v) ?? '');
    }

    public static function dv(string $base): int
    {
        $n = strlen($base);
        $pesos = $n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < $n; $i++) {
            $soma += (ord($base[$i]) - 48) * $pesos[$i];
        }
        $r = $soma % 11;

        return $r < 2 ? 0 : 11 - $r;
    }

    public static function valido(string $v): bool
    {
        $c = self::normalizar($v);
        if (!preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $c)) {
            return false;
        }
        if (preg_match('/^(.)\1{13}$/', $c)) {
            return false;
        }
        $d1 = self::dv(substr($c, 0, 12));
        $d2 = self::dv(substr($c, 0, 12) . $d1);

        return $c[12] === (string)$d1 && $c[13] === (string)$d2;
    }

    public static function formatar(string $c): string
    {
        $c = self::normalizar($c);
        if (strlen($c) !== 14) {
            return $c;
        }

        return substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12, 2);
    }
}
