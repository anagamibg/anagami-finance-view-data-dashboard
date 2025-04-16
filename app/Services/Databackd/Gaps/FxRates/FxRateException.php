<?php

namespace App\Services\Databackd\Gaps\FxRates;

/**
 * An expected failure of the ECB download (network error, HTTP status, unreadable feed); the message is already
 * translated and safe to show to the user.
 */
class FxRateException extends \RuntimeException
{

}
