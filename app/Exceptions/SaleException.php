<?php

namespace App\Exceptions;

use RuntimeException;

/** Règle de vente ou d'encaissement non respectée (message affichable tel quel). */
class SaleException extends RuntimeException {}
