<?php

namespace App\Services\Databackd\Gaps\BankImport;

/**
 * A bank statement file cannot be read (wrong size, unknown or broken format, nothing to import).
 * The message is already translated (databackd-gaps-bank-import.errors.*) and safe to show to the user.
 */
class BankStatementException extends \RuntimeException
{

}
