<?php

/*
|--------------------------------------------------------------------------
| Markets band (App\Markets)
|--------------------------------------------------------------------------
|
| The sources `portal:sync-markets` reads twice a day. Both are public
| and need no key. Change a URL here only if the publisher moves it.
|
*/

return [

    /*
    | TRM: official dataset of datos.gov.co (Superintendencia Financiera).
    | The command adds `$order=vigenciadesde DESC&$limit=10`.
    */
    'trm_url' => 'https://www.datos.gov.co/resource/32sa-8pi3.json',

    /*
    | ECB daily reference rates (USD per EUR). The ECB has no COP rate: the
    | euro in pesos is the TRM times this rate.
    */
    'ecb_url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',

    /* How many TRM validities are asked for each run. */
    'trm_limit' => 10,

    /* Seconds to wait for a source, to connect and to read. */
    'timeout' => 10,

    /* Largest response read from a source, in bytes (1 MB). */
    'max_bytes' => 1024 * 1024,

];
