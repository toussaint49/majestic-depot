<?php

namespace App\Exceptions;

use RuntimeException;

/** Le client n'a pas rapporté les vides nécessaires : la vente est refusée. */
class MissingPackagingException extends RuntimeException
{
    /** @param array<string, int> $missing libellé de l'emballage => quantité manquante */
    public function __construct(public readonly array $missing)
    {
        $list = collect($missing)->map(fn ($q, $name) => "$q × $name")->implode(', ');
        parent::__construct("Vente refusée : emballages vides manquants ($list).");
    }
}
