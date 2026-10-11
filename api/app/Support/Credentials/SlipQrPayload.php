<?php

namespace App\Support\Credentials;

/**
 * What the QR code of a slip says: the voting address with the code in the fragment, the code as
 * it is shown (`K7MP4-QX9RT`). The fragment is read by the browser only and never sent to the
 * server, so the code does not reach a log through the address.
 */
final class SlipQrPayload
{
    /** @param  string  $votingAddress  `{APP_URL}/vote/{election uuid}`, with no code in it */
    public static function for(string $votingAddress, string $code): string
    {
        return $votingAddress.'#'.$code;
    }
}
