<?php

declare(strict_types=1);

namespace Ngandu\Flexpay\Data;

enum Type: int
{
    case MOBILE = 1;
    case CARD = 2;
}
