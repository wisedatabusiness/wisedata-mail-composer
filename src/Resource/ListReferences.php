<?php

declare(strict_types=1);

namespace WiseData\Mail\Resource;

/**
 * As listas padrão vindas da configuração: `clientes-finances,leads` no `.env`.
 *
 * Número vira id (`3` → `3`), o resto segue como chave. Vazio entre vírgulas é
 * ignorado — `a,,b` é digitação, não pedido de lista sem nome.
 */
final class ListReferences
{
    /**
     * @return list<int|string>
     */
    public static function fromConfig(mixed $valor): array
    {
        $itens = match (true) {
            is_array($valor) => $valor,
            is_string($valor) => explode(',', $valor),
            is_int($valor) => [$valor],
            default => [],
        };

        $listas = [];

        foreach ($itens as $item) {
            if (is_int($item)) {
                $listas[] = $item;

                continue;
            }

            if (! is_string($item) || trim($item) === '') {
                continue;
            }

            $item = trim($item);
            $listas[] = ctype_digit($item) ? (int) $item : $item;
        }

        return array_values(array_unique($listas, SORT_REGULAR));
    }
}
