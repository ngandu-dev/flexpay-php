<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Data;

/**
 * Class Currency.
 *
 * La devise de la transaction.
 *
 * @author bernard-ng <bernard@ngandu.dev>
 */
enum Currency: string
{
    /**
     * USD : dollars américains
     */
    case USD = 'USD';

    /**
     * Franc Congolais.
     */
    case CDF = 'CDF';
}
