<?php

declare(strict_types=1);

namespace App;

use Waffle\Kernel as Base;

/**
 * Le kernel de la passerelle.
 *
 * Aucun comportement propre : tout l'assemblage vit dans
 * {@see \App\Factory\AppKernelFactory} (injection par constructeur, ARCH-03).
 */
final class Kernel extends Base {}
