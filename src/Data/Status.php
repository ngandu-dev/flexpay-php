<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Data;

/**
 * Class Status.
 *
 * Ce code donne le statut de la requête envoyée
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
enum Status: int
{
    /**
     * 0 : pour la requête bien envoyée
     */
    case SUCCESS = 0;

    /**
     * 1 : en cas de problème
     */
    case FAILURE = 1;
}
