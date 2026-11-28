<?php

use App\Core\Config\Config;
use App\Core\Rules\Rules;

return Config::make()
    ->paths(['src'])
    // Generated code: each tagged union is a cycle between the parent, whose fromArray()
    // picks the variant, and its variants. Still counted in the metrics, and a cycle
    // reaching a class outside Schema is still reported.
    ->ignore(['Acp\Schema\*'])
    // The features of the client, checked for cycles between them.
    ->components([
        'Acp\Client\Session' => 'Acp\Client\Session\*',
        'Acp\Client\Permission' => 'Acp\Client\Permission\*',
        'Acp\Client\FileSystem' => 'Acp\Client\FileSystem\*',
    ])
    ->rules([
        Rules::noCycles(),
    ])
    ->baseline('dependencies-baseline.json');
