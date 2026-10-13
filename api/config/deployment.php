<?php

return [

    // `online`: the system as run on a server with a domain, email included. `school`: an installation
    // on a school's own server, with no email anywhere (docs/slices/19c-installers.md, docs/api/README.md
    // section 7a). Any other value stops the application at boot.
    'mode' => env('DEPLOYMENT_MODE', 'online'),

];
